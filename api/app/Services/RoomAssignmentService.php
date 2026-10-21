<?php

namespace App\Services;

use App\Enums\ExaminationStatus;
use App\Jobs\SendRoomAssignmentNotification;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\ExamSession;
use Illuminate\Validation\ValidationException;

class RoomAssignmentService
{
    public function assertEditable(ExaminationSchedule $schedule): void
    {
        $hasActiveSession = ExamSession::where('examination_schedule_id', $schedule->id)
            ->whereIn('status', ['open', 'lobby_open', 'in_progress'])->exists();
        $status = $schedule->status?->value ?? $schedule->status;
        if ($status !== ExaminationStatus::Scheduled->value || $hasActiveSession) {
            throw ValidationException::withMessages(['rooms' => 'Room assignments are locked once a lobby opens or the examination starts.']);
        }
    }

    public function distribute(ExaminationSchedule $schedule): void
    {
        if (! $schedule->rooms_managed) return;
        $this->assertEditable($schedule);
        $rooms = $schedule->rooms()->orderBy('id')->get()->keyBy('id');
        $students = $schedule->registrations()->orderBy('id')->get();
        $regular = $students->whereNull('previous_schedule_id');
        $rescheduled = $students->whereNotNull('previous_schedule_id');
        $counts = $rooms->mapWithKeys(fn ($r) => [$r->id => ['regular' => 0, 'rescheduled' => 0]])->all();

        // Keep manual placements; automatic regular assignments are recalculated in room order.
        foreach ($regular->where('room_assignment_manual', true) as $student) {
            $id = $student->assigned_room_id;
            if (! isset($counts[$id])) continue;
            if (++$counts[$id]['regular'] > $rooms[$id]->capacity) {
                throw ValidationException::withMessages(['assignments' => "{$rooms[$id]->room_name} exceeds regular capacity."]);
            }
        }
        foreach ($rescheduled as $student) {
            $id = $student->assigned_room_id;
            if (! isset($counts[$id])) continue;
            if (++$counts[$id]['rescheduled'] > $rooms[$id]->reschedule_capacity) {
                throw ValidationException::withMessages(['assignments' => "{$rooms[$id]->room_name} exceeds reschedule capacity."]);
            }
        }
        foreach ($regular as $student) {
            if ($student->room_assignment_manual && isset($counts[$student->assigned_room_id])) continue;
            $id = $rooms->keys()->first(fn ($key) => $counts[$key]['regular'] < $rooms[$key]->capacity);
            if ($id === null) throw ValidationException::withMessages(['rooms' => 'Insufficient regular room capacity.']);
            $counts[$id]['regular']++;
            $this->savePlacement($schedule, $student, (int) $id, false);
        }
        foreach ($rescheduled as $student) {
            if ($student->assigned_room_id && isset($counts[$student->assigned_room_id])) continue;
            $id = $rooms->keys()->first(fn ($key) => $counts[$key]['rescheduled'] < $rooms[$key]->reschedule_capacity);
            if ($id === null) throw ValidationException::withMessages(['rooms' => 'Insufficient reschedule room capacity.']);
            $counts[$id]['rescheduled']++;
            $this->savePlacement($schedule, $student, (int) $id, true);
        }
        $schedule->increment('rooms_revision');
    }

    private function savePlacement(ExaminationSchedule $schedule, ExaminationRegistration $student, int $roomId, bool $manual): void
    {
        if ((int) $student->assigned_room_id === $roomId) return;
        ExaminationRegistration::whereKey($student->id)->update(['assigned_room_id' => $roomId, 'room_assignment_manual' => $manual]);
        if ($schedule->rooms_published_at) SendRoomAssignmentNotification::dispatch($student->id)->afterCommit();
    }

    public function publish(ExaminationSchedule $schedule): void
    {
        $this->distribute($schedule);
        if (! $schedule->rooms()->exists()) throw ValidationException::withMessages(['rooms' => 'Select at least one room before publishing.']);
        $schedule->forceFill(['rooms_published_at' => now()])->save();
        $schedule->registrations()->pluck('id')->each(fn ($id) => SendRoomAssignmentNotification::dispatch($id)->afterCommit());
    }
}
