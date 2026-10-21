<?php

namespace App\Http\Controllers\Examination;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ExaminationRoom;
use App\Models\ExaminationSchedule;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\User;
use App\Services\ExamSessionService;
use App\Services\RoomAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleRoomAssignmentController extends Controller
{
    public function show(ExaminationSchedule $examinationSchedule)
    {
        $s = $examinationSchedule->fresh();
        $editable = true;
        try {
            app(RoomAssignmentService::class)->assertEditable($s);
        } catch (\Illuminate\Validation\ValidationException) {
            $editable = false;
        }
        return response()->json(['data' => [
            'managed' => $s->rooms_managed,
            'published_at' => $s->rooms_published_at,
            'editable' => $editable,
            'revision' => $s->rooms_revision,
            'rooms' => $s->rooms()->with('proctor:id,name')
                ->withCount('registrations')->orderBy('id')->get()->map(function ($room) {
                    $regularCount = $room->registrations()->whereNull('previous_schedule_id')->count();
                    $rescheduledCount = $room->registrations()->whereNotNull('previous_schedule_id')->count();
                    $session = $room->examSessions()->whereIn('status', ['open', 'lobby_open', 'in_progress'])->with('proctor:id,name')->first();
                    return [...$room->toArray(), 'session' => $session ? [
                        'id' => $session->id, 'status' => $session->status->value,
                        'proctor' => $session->proctor,
                    ] : null, 'regular_count' => $regularCount, 'rescheduled_count' => $rescheduledCount,
                    'total_count' => $regularCount + $rescheduledCount,
                    'total_capacity' => (int) $room->capacity + (int) $room->reschedule_capacity];
                }),
            'students' => $s->registrations()->with('applicant:id,name,applicant_code')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->applicant?->name,
                'code' => $r->applicant?->applicant_code, 'room_id' => $r->assigned_room_id,
                'manual' => (bool) $r->room_assignment_manual,
                'rescheduled' => $r->previous_schedule_id !== null,
            ]),
        ]]);
    }

    public function update(Request $request, ExaminationSchedule $examinationSchedule)
    {
        $data = $request->validate([
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.room_id' => ['required', 'integer', 'distinct', 'exists:rooms,id'],
            'rooms.*.proctor_id' => ['nullable', 'integer', 'distinct', 'exists:users,id'],
            'revision' => ['required', 'integer'],
        ]);
        return DB::transaction(function () use ($data, $examinationSchedule) {
            $schedule = ExaminationSchedule::whereKey($examinationSchedule->id)->lockForUpdate()->firstOrFail();
            abort_if($schedule->rooms_revision !== $data['revision'], 409, 'Assignments changed. Refresh before saving.');
            $service = app(RoomAssignmentService::class);
            $service->assertEditable($schedule);
            $selected = [];
            foreach ($data['rooms'] as $item) {
                $catalog = Room::whereKey($item['room_id'])->lockForUpdate()->firstOrFail();
                abort_unless($catalog->is_active, 422, "{$catalog->name} is inactive.");
                $proctorId = $item['proctor_id'] ?? null;
                $this->validateProctor($proctorId);
                // One physical room/proctor cannot serve overlapping schedules.
                $conflicts = ExaminationRoom::where('examination_schedule_id', '!=', $schedule->id)
                    ->whereHas('schedule', fn ($q) => $q->whereDate('exam_date', $schedule->exam_date)
                        ->whereNotIn('status', ['completed', 'cancelled'])
                        ->where('start_time', '<', $schedule->end_time)
                        ->where('end_time', '>', $schedule->start_time));
                abort_if((clone $conflicts)->where('room_id', $catalog->id)->exists(), 422, "{$catalog->name} is booked for an overlapping examination.");
                abort_if($proctorId && (clone $conflicts)->where('proctor_id', $proctorId)->exists(), 422, 'This proctor is assigned to an overlapping examination.');
                $room = $schedule->rooms()->updateOrCreate(['room_id' => $catalog->id], [
                    'room_name' => $catalog->name, 'capacity' => $catalog->capacity, 'reschedule_capacity' => $catalog->reschedule_capacity, 'proctor_id' => $proctorId,
                ]);
                $selected[] = $room->id;
            }
            $removed = $schedule->rooms()->whereNotIn('id', $selected)->pluck('id');
            // Release references before deletion; distribution restores every student atomically.
            $schedule->registrations()->whereIn('assigned_room_id', $removed)->update([
                'assigned_room_id' => null, 'room_assignment_manual' => false,
            ]);
            abort_if(ExamSession::whereIn('examination_room_id', $removed)->exists(), 422, 'Rooms with session history cannot be removed.');
            $schedule->rooms()->whereIn('id', $removed)->delete();
            $schedule->forceFill(['rooms_managed' => true])->save();
            $service->distribute($schedule);
            return $this->show($schedule);
        }, 3);
    }

    public function move(Request $request, ExaminationSchedule $examinationSchedule)
    {
        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.registration_id' => ['required', 'integer', 'distinct'],
            'assignments.*.room_id' => ['required', 'integer'],
            'assignments.*.manual' => ['required', 'boolean'],
            'revision' => ['required', 'integer'],
        ]);
        return DB::transaction(function () use ($data, $examinationSchedule) {
            $s = ExaminationSchedule::whereKey($examinationSchedule->id)->lockForUpdate()->firstOrFail();
            abort_if($s->rooms_revision !== $data['revision'], 409, 'Assignments changed. Refresh before saving.');
            $service = app(RoomAssignmentService::class);
            $service->assertEditable($s);
            abort_unless($s->rooms_managed, 422, 'Select rooms first.');
            $changed = [];
            foreach ($data['assignments'] as $item) {
                $registration = $s->registrations()->findOrFail($item['registration_id']);
                $s->rooms()->findOrFail($item['room_id']);
                if ((int) $registration->assigned_room_id !== $item['room_id']) {
                    $changed[] = $registration->id;
                }
                $registration->update(['assigned_room_id' => $item['room_id'], 'room_assignment_manual' => $item['manual']]);
            }
            $service->distribute($s);
            if ($s->rooms_published_at) {
                foreach ($changed as $id) {
                    \App\Jobs\SendRoomAssignmentNotification::dispatch($id)->afterCommit();
                }
            }
            return $this->show($s);
        }, 3);
    }

    public function publish(ExaminationSchedule $examinationSchedule)
    {
        return DB::transaction(function () use ($examinationSchedule) {
            $s = ExaminationSchedule::whereKey($examinationSchedule->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->rooms_managed, 422, 'Select rooms first.');
            app(RoomAssignmentService::class)->publish($s);
            return $this->show($s);
        }, 3);
    }

    public function recover(Request $request, ExaminationRoom $examinationRoom)
    {
        $data = $request->validate([
            'proctor_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $this->validateProctor($data['proctor_id'] ?? null);
        return DB::transaction(function () use ($data, $examinationRoom, $request) {
            if ($data['proctor_id'] ?? null) {
                User::whereKey($data['proctor_id'])->lockForUpdate()->firstOrFail();
                abort_if(ExamSession::where('proctor_id', $data['proctor_id'])->whereIn('status', ['open', 'lobby_open', 'in_progress'])
                    ->where('examination_room_id', '!=', $examinationRoom->id)->exists(), 409, 'This proctor already manages another room.');
            }
            ExaminationSchedule::whereKey($examinationRoom->examination_schedule_id)->lockForUpdate()->firstOrFail();
            $room = ExaminationRoom::whereKey($examinationRoom->id)->lockForUpdate()->firstOrFail();
            $session = $room->examSessions()->whereIn('status', ['lobby_open', 'in_progress'])->lockForUpdate()->firstOrFail();
            $previous = $session->proctor_id;
            if ($data['proctor_id'] ?? null) {
                $session->update(['proctor_id' => $data['proctor_id']]);
                $room->update(['proctor_id' => $data['proctor_id']]);
            } else {
                abort_if($session->status->value !== 'lobby_open', 422, 'An examination in progress must be transferred to another proctor, not released.');
                app(ExamSessionService::class)->closeLobby($session);
                $room->update(['proctor_id' => null]);
            }
            ActivityLog::create([
                'user_id' => $request->user()->id, 'action' => 'room_ownership_recovered',
                'subject_type' => ExaminationRoom::class, 'subject_id' => $room->id,
                'description' => "Room {$room->id}: proctor {$previous} → ".($data['proctor_id'] ?? 'released').". Reason: {$data['reason']}",
            ]);
            return response()->json(['message' => 'Room ownership updated.']);
        }, 3);
    }

    private function validateProctor(?int $id): void
    {
        if ($id) {
            $user = User::findOrFail($id);
            abort_unless($user->isProctor() && $user->status->value === 'active', 422, 'Select an active proctor.');
        }
    }
}
