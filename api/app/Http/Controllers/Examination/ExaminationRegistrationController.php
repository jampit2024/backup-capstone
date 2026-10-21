<?php

namespace App\Http\Controllers\Examination;

use App\Http\Controllers\Controller;

use App\Enums\AttendanceStatus;
use App\Enums\ExaminationStatus;
use App\Enums\ResultStatus;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\User;
use App\Services\ExaminationPasskeyService;
use App\Support\ExamTestMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExaminationRegistrationController extends Controller
{
    public function createTestSchedule(Request $request): JsonResponse
    {
        if (! ExamTestMode::enabled()) {
            return response()->json(['success' => false, 'message' => 'Test schedules are disabled in this environment.'], 404);
        }

        $validated = $request->validate([
            'registration_ids' => ['required', 'array', 'min:1', 'max:5'],
            'registration_ids.*' => ['required', 'integer', 'distinct', 'exists:exam_registrations,id'],
            'proctor_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $proctor = User::query()->with('role')->find($validated['proctor_id']);
        if (! $proctor || ! $proctor->isProctor() || $proctor->status !== UserStatus::Active) {
            return response()->json(['success' => false, 'message' => 'Choose an active examination proctor.'], 422);
        }

        $now = Carbon::now('Asia/Manila');
        $start = $now->copy()->subMinutes(5);
        if ($start->lt($now->copy()->startOfDay())) {
            $start = $now->copy()->startOfDay();
        }
        $end = $now->copy()->addMinutes(30);
        if ($end->gt($now->copy()->endOfDay())) {
            $end = $now->copy()->endOfDay();
        }
        if (! $end->gt($now)) {
            return response()->json(['success' => false, 'message' => 'A test window cannot include the current time this close to midnight.'], 422);
        }

        $adminId = (int) $request->user()->id;
        $schedule = DB::transaction(function () use ($request, $validated, $proctor, $now, $start, $end, $adminId) {
            $registrations = ExaminationRegistration::query()
                ->with(['applicant', 'schedule'])
                ->whereIn('id', $validated['registration_ids'])
                ->whereIn('passkey_status', ['not_sent', 'sent'])
                ->whereNotNull('exam_passkey_hash')
                ->whereNotNull('exam_passkey_encrypted')
                ->whereNull('exam_session_id')
                ->whereNull('score')
                ->where('result_status', ResultStatus::Pending->value)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();
            if ($registrations->count() !== count($validated['registration_ids'])) {
                throw ValidationException::withMessages([
                    'registration_ids' => 'Each selected test student must have an unused, recoverable key and no active attempt or result.',
                ]);
            }

            $schedule = ExaminationSchedule::query()->create([
                'title' => 'Test Entrance Examination',
                'exam_date' => $now->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'time_slot' => $start->format('g:i A').' - '.$end->format('g:i A'),
                'venue' => 'Test Room',
                'batch_code' => 'TEST-'.$now->format('ymdHis'),
                'proctor_id' => $proctor->id,
                'status' => ExaminationStatus::Scheduled,
                'expected_examinees' => $registrations->count(),
            ]);
            app(\App\Services\ExamSessionService::class)->ensureDefaultRooms($schedule, 1);

            foreach ($registrations as $registration) {
                $request->merge([
                    'target_schedule_id' => $schedule->id,
                    'reason' => 'Local test schedule',
                ]);
                $rescheduleResponse = $this->reschedule($request, $registration);
                if ($rescheduleResponse->getStatusCode() >= 300) {
                    throw new \RuntimeException('Unable to move a selected test student into the test schedule.');
                }
            }

            ActivityLog::query()->create([
                'user_id' => $adminId,
                'action' => 'test_exam_schedule_created',
                'description' => "Created test examination schedule {$schedule->id} for {$registrations->count()} rescheduled registration(s).",
                'subject_type' => ExaminationSchedule::class,
                'subject_id' => $schedule->id,
            ]);

            return $schedule;
        });

        return response()->json([
            'success' => true,
            'message' => "Today's test schedule is ready. Existing passkeys were retained.",
            'data' => [
                'schedule' => [
                    'id' => $schedule->id,
                    'title' => $schedule->title,
                    'exam_date' => $schedule->exam_date->toDateString(),
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'time_slot' => $schedule->time_slot,
                    'batch_code' => $schedule->batch_code,
                    'proctor_id' => $schedule->proctor_id,
                    'expected_examinees' => $schedule->expected_examinees,
                    'room_name' => 'Room 01',
                ],
            ],
        ], 201);
    }

    public function reschedule(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'target_schedule_id' => ['required', 'integer', 'exists:exam_schedules,id'],
            'target_room_id' => ['nullable', 'integer', 'exists:exam_rooms,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $registration->load(['applicant', 'schedule', 'assignedRoom']);

        $fromSchedule = $registration->schedule;
        if (! $fromSchedule) {
            return response()->json(['success' => false, 'message' => 'Current schedule not found.'], 404);
        }

        if ((int) $validated['target_schedule_id'] === (int) $registration->examination_schedule_id) {
            throw ValidationException::withMessages([
                'target_schedule_id' => 'Choose a different day or batch.',
            ]);
        }

        $attendance = $registration->attendance_status?->value ?? $registration->attendance_status;
        $result = $registration->result_status?->value ?? $registration->result_status;
        $lobbyStatus = $registration->lobby_status;
        $completed = in_array($lobbyStatus, ['finished', 'terminated'], true)
            || ($registration->score !== null && in_array($result, [
                ResultStatus::Passed->value,
                ResultStatus::Failed->value,
            ], true));

        if ($completed || ($attendance === AttendanceStatus::Present->value && $result !== ResultStatus::Pending->value)) {
            throw ValidationException::withMessages([
                'registration' => 'This examinee already has a recorded result and cannot be rescheduled.',
            ]);
        }

        $target = ExaminationSchedule::query()->findOrFail($validated['target_schedule_id']);
        $targetStatus = $target->status?->value ?? $target->status;

        if (in_array($targetStatus, [ExaminationStatus::Completed->value, ExaminationStatus::Cancelled->value], true)) {
            throw ValidationException::withMessages([
                'target_schedule_id' => 'That batch is completed or cancelled. Pick another schedule.',
            ]);
        }

        $alreadyOnTarget = ExaminationRegistration::query()
            ->where('examination_schedule_id', $target->id)
            ->where('applicant_id', $registration->applicant_id)
            ->where('id', '!=', $registration->id)
            ->exists();

        if ($alreadyOnTarget) {
            throw ValidationException::withMessages([
                'target_schedule_id' => 'This examinee is already registered in that batch.',
            ]);
        }

        $targetRooms = $target->rooms()->get();
        $targetRoom = null;
        if ($targetRooms->isNotEmpty()) {
            $targetRoomId = $validated['target_room_id'] ?? null;
            if ($targetRoomId) {
                $targetRoom = $targetRooms->firstWhere('id', (int) $targetRoomId);
                if (! $targetRoom) {
                    throw ValidationException::withMessages([
                        'target_room_id' => 'The selected room is not assigned to that examination schedule.',
                    ]);
                }

                $currentOccupancy = ExaminationRegistration::query()
                    ->where('examination_schedule_id', $target->id)
                    ->where('assigned_room_id', $targetRoom->id)
                    ->where('id', '!=', $registration->id)
                    ->count();

                $rescheduleOccupancy = ExaminationRegistration::query()->where('examination_schedule_id', $target->id)
                    ->where('assigned_room_id', $targetRoom->id)->whereNotNull('previous_schedule_id')->where('id', '!=', $registration->id)->count();
                if ($rescheduleOccupancy >= $targetRoom->reschedule_capacity) {
                    throw ValidationException::withMessages([
                        'target_room_id' => sprintf(
                            '%s has reached its reschedule limit (%d/%d). Please select another room.',
                            $targetRoom->room_name,
                            $rescheduleOccupancy,
                            $targetRoom->reschedule_capacity
                        ),
                    ]);
                }
            } else {
                $targetRoom = $targetRooms->first(function ($r) use ($target, $registration) {
                    $occ = ExaminationRegistration::query()
                        ->where('examination_schedule_id', $target->id)
                        ->where('assigned_room_id', $r->id)
                        ->where('id', '!=', $registration->id)
                        ->count();
                    $rescheduled = ExaminationRegistration::query()->where('examination_schedule_id', $target->id)
                        ->where('assigned_room_id', $r->id)->whereNotNull('previous_schedule_id')->count();
                    return $rescheduled < $r->reschedule_capacity;
                }) ?? $targetRooms->first();
            }
        }

        $reason = trim($validated['reason']);
        $fromLabel = $this->batchLabel($fromSchedule);
        $toLabel = $this->batchLabel($target);
        $passkeyStatus = $registration->passkey_email_sent || filled($registration->passkey_sent_to_email)
            ? 'sent'
            : 'not_sent';
        $passkeyExpiry = app(ExaminationPasskeyService::class)->scheduleExpiry($target);

        DB::transaction(function () use ($request, $registration, $target, $fromSchedule, $targetRoom, $reason, $fromLabel, $toLabel, $passkeyStatus, $passkeyExpiry) {
            $updateData = [
                'examination_schedule_id' => $target->id,
                'previous_schedule_id' => $fromSchedule->id,
                'reschedule_reason' => $reason,
                'rescheduled_at' => now(),
                'rescheduled_by' => $request->user()->id,
                'passkey_status' => $passkeyStatus,
                'passkey_expires_at' => $passkeyExpiry,
                'passkey_used_at' => null,
                'exam_session_id' => null,
                'participation_token' => null,
                'lobby_status' => null,
                'joined_lobby_at' => null,
                'last_heartbeat_at' => null,
                'agreed_at' => null,
                'reconnect_allowed' => false,
                'reconnect_code' => null,
                'reconnect_code_expires_at' => null,
                'attendance_status' => AttendanceStatus::Pending,
                'result_status' => ResultStatus::Pending,
                'score' => null,
            ];

            if ($targetRoom) {
                $updateData['assigned_room_id'] = $targetRoom->id;
                $updateData['room_assignment_manual'] = true;
            }

            $registration->update($updateData);

            ActivityLog::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'student_rescheduled',
                'description' => sprintf(
                    'Rescheduled %s (%s) from %s to %s%s. Reason: %s',
                    $registration->applicant?->name ?: 'examinee',
                    $registration->applicant?->applicant_code ?: 'n/a',
                    $fromLabel,
                    $toLabel,
                    $targetRoom ? ' ('.$targetRoom->room_name.')' : '',
                    $reason
                ),
                'subject_type' => ExaminationRegistration::class,
                'subject_id' => $registration->id,
            ]);
        });

        $registration->refresh()->load(['applicant', 'schedule', 'previousSchedule', 'assignedRoom']);

        return response()->json([
            'success' => true,
            'message' => 'Examinee moved to the new batch' . ($targetRoom ? ' and room ' . $targetRoom->room_name : '') . '.',
            'data' => [
                'registration_id' => $registration->id,
                'applicant_code' => $registration->applicant?->applicant_code,
                'student_name' => $registration->applicant?->name,
                'from_schedule_id' => $fromSchedule->id,
                'from_batch_label' => $fromLabel,
                'to_schedule_id' => $target->id,
                'to_batch_label' => $toLabel,
                'to_room_id' => $registration->assigned_room_id,
                'to_room_name' => $registration->assignedRoom?->room_name,
                'reason' => $reason,
                'rescheduled_at' => optional($registration->rescheduled_at)?->toIso8601String(),
            ],
        ]);
    }

    private function batchLabel(ExaminationSchedule $schedule): string
    {
        $dateLabel = optional($schedule->exam_date)->format('M j, Y');
        $timeSlot = $schedule->time_slot
            ?: trim(
                (optional($schedule->start_time ? Carbon::parse($schedule->start_time) : null)?->format('H:i') ?? '')
                .'–'
                .(optional($schedule->end_time ? Carbon::parse($schedule->end_time) : null)?->format('H:i') ?? ''),
                '–'
            );

        return trim(($dateLabel ?: '').', '.($schedule->batch_code ?: '').' · '.($timeSlot ?: ''));
    }
}
