<?php

namespace App\Http\Controllers\Examination;

use App\Http\Controllers\Controller;

use App\Enums\ExaminationStatus;
use App\Models\ActivityLog;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ExaminationScheduleController extends Controller
{
    public function __construct(private \App\Services\ApplicantService $applicants)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        // Link any imported students that are not yet on a schedule slot.
        $this->applicants->syncUnscheduledApplicants();

        $query = ExaminationSchedule::with(['rooms.proctor:id,name'])
            ->withCount([
                'rooms',
                'registrations',
                'registrations as passkey_sent_count' => fn ($q) => $q->where('passkey_email_sent', true),
                'registrations as passkey_with_gmail_count' => fn ($q) => $q->whereHas('applicant', function ($inner) {
                    $inner->where(function ($emailQ) {
                        $emailQ->whereNotNull('gmail')->where('gmail', '!=', '')
                            ->orWhere(function ($legacy) {
                                $legacy->whereNotNull('email')->where('email', '!=', '');
                            });
                    });
                }),
            ])
            ->orderBy('exam_date')
            ->orderBy('start_time');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('upcoming')) {
            $query->whereDate('exam_date', '>=', Carbon::today()->toDateString());
        }

        $schedules = $query->get();

        // Ensure every schedule has the default three rooms (Room 01–03) when empty.
        foreach ($schedules as $schedule) {
            if ((int) ($schedule->rooms_count ?? $schedule->rooms->count()) === 0) {
                app(\App\Services\ExamSessionService::class)->ensureDefaultRooms($schedule);
                $schedule->load(['rooms.proctor:id,name']);
                $schedule->loadCount([
                    'rooms',
                    'registrations',
                    'registrations as passkey_sent_count' => fn ($q) => $q->where('passkey_email_sent', true),
                    'registrations as passkey_with_gmail_count' => fn ($q) => $q->whereHas('applicant', function ($inner) {
                        $inner->where(function ($emailQ) {
                            $emailQ->whereNotNull('gmail')->where('gmail', '!=', '')
                                ->orWhere(function ($legacy) {
                                    $legacy->whereNotNull('email')->where('email', '!=', '');
                                });
                        });
                    }),
                ]);
            }
        }

        $mapped = $schedules->map(function (ExaminationSchedule $schedule) {
            $summary = $this->mapSummary($schedule);
            $total = (int) ($schedule->registrations_count ?? 0);
            $keysSent = (int) ($schedule->passkey_sent_count ?? 0);
            $withGmail = (int) ($schedule->passkey_with_gmail_count ?? 0);

            return [
                ...$summary,
                'passkey_total' => $total,
                'passkey_sent' => $keysSent,
                'passkey_with_gmail' => $withGmail,
                'passkeys_fully_sent' => $total > 0 && $keysSent >= $total,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mapped,
        ]);
    }

    public function show(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        Log::info('Admin schedule drawer lookup resolved.', [
            'requested_schedule_id' => $request->route('examinationSchedule') instanceof ExaminationSchedule
                ? $request->route('examinationSchedule')->getKey()
                : $request->route('examinationSchedule'),
            'found_schedule_id' => $examinationSchedule->getKey(),
        ]);

        $examinationSchedule->load([
            'rooms.proctor:id,name,email',
            'registrations.applicant',
            'registrations.previousSchedule',
            'registrations.assignedRoom',
        ]);
        $examinationSchedule->loadCount([
            'registrations',
            'registrations as passkey_sent_count' => fn ($query) => $query->where('passkey_email_sent', true),
        ]);

        $passkeys = app(\App\Services\ExaminationPasskeyService::class);
        $students = $examinationSchedule->registrations
            ->sortBy(fn ($reg) => $reg->applicant?->name)
            ->values()
            ->map(function ($reg) use ($passkeys) {
                $keyView = $passkeys->adminPasskeyView($reg);

                return [
                    'registration_id' => $reg->id,
                    'applicant_id' => $reg->applicant_id,
                    'applicant_code' => $reg->applicant?->applicant_code,
                    'name' => $reg->applicant?->name,
                    'email' => $reg->applicant?->email,
                    'gmail' => $reg->applicant?->gmail ?: $reg->applicant?->email,
                    'course_preference' => $reg->applicant?->course_applied,
                    'assigned_room_id' => $reg->assigned_room_id,
                    'assigned_room_name' => $reg->assignedRoom?->room_name,
                    ...$keyView,
                    'passkey_exists' => (bool) $reg->exam_passkey_hash,
                    'passkey_encrypted_exists' => (bool) $reg->exam_passkey_encrypted,
                    'passkey_status' => $reg->passkey_status ?: 'not_sent',
                    'passkey_sent_to_email' => $reg->passkey_sent_to_email,
                    'passkey_used_at' => optional($reg->passkey_used_at)?->toIso8601String(),
                    'passkey_expires_at' => optional($reg->passkey_expires_at)?->toIso8601String(),
                    'passkey_email_status' => $reg->passkey_email_status?->value ?? $reg->passkey_email_status,
                    'passkey_email_sent' => (bool) $reg->passkey_email_sent,
                    'join_status' => $reg->lobby_status ?: 'not_joined',
                    'attendance_status' => $reg->attendance_status?->value ?? $reg->attendance_status,
                    'result_status' => $reg->result_status?->value ?? $reg->result_status,
                    'score' => $reg->score,
                    'can_reschedule' => $reg->canReschedule(),
                    'was_rescheduled' => (bool) $reg->previous_schedule_id,
                    'reschedule_reason' => $reg->reschedule_reason,
                    'previous_batch_label' => $reg->previousSchedule
                        ? $this->batchLabel($reg->previousSchedule)
                        : null,
                    'rescheduled_at' => optional($reg->rescheduled_at)?->toIso8601String(),
                ];
            });

        ActivityLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'examination_passkeys_viewed',
            'description' => "Admin viewed examination passkeys for schedule {$examinationSchedule->id}.",
            'subject_type' => ExaminationSchedule::class,
            'subject_id' => $examinationSchedule->id,
        ]);

        $movedAway = ExaminationRegistration::query()
            ->with(['applicant', 'schedule'])
            ->where('previous_schedule_id', $examinationSchedule->id)
            ->orderByDesc('rescheduled_at')
            ->get()
            ->map(fn ($reg) => [
                'registration_id' => $reg->id,
                'applicant_code' => $reg->applicant?->applicant_code,
                'name' => $reg->applicant?->name,
                'email' => $reg->applicant?->email,
                'reason' => $reg->reschedule_reason,
                'moved_to_schedule_id' => $reg->examination_schedule_id,
                'moved_to_batch_label' => $reg->schedule ? $this->batchLabel($reg->schedule) : null,
                'rescheduled_at' => optional($reg->rescheduled_at)?->toIso8601String(),
            ]);

        $availableTargets = ExaminationSchedule::query()
            ->with(['rooms' => fn ($q) => $q->withCount('registrations')])
            ->where('id', '!=', $examinationSchedule->id)
            ->whereNotIn('status', [
                ExaminationStatus::Completed->value,
                ExaminationStatus::Cancelled->value,
            ])
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (ExaminationSchedule $schedule) => [
                'id' => $schedule->id,
                'label' => $this->batchLabel($schedule),
                'exam_date' => optional($schedule->exam_date)->toDateString(),
                'date_label' => optional($schedule->exam_date)->format('M j, Y'),
                'batch_code' => $schedule->batch_code,
                'time_slot' => $schedule->time_slot,
                'status' => $schedule->status?->value ?? $schedule->status,
                'rooms' => $schedule->rooms->map(fn ($r) => [
                    'id' => $r->id,
                    'room_name' => $r->room_name,
                    'capacity' => (int) $r->capacity,
                'reschedule_capacity' => (int) $r->reschedule_capacity,
                    'registrations_count' => (int) ($r->registrations_count ?? 0),
                'available_seats' => max(0, (int) $r->reschedule_capacity - $r->registrations()->whereNotNull('previous_schedule_id')->count()),
                ])->values()->all(),
            ]);

        $availableRooms = $examinationSchedule->rooms->map(fn ($room) => [
            'id' => $room->id,
            'room_name' => $room->room_name,
            'capacity' => (int) $room->capacity,
            'reschedule_capacity' => (int) $room->reschedule_capacity,
            'registrations_count' => (int) ($room->registrations_count ?? $room->registrations()->count()),
            'available_seats' => max(0, (int) $room->reschedule_capacity - $room->registrations()->whereNotNull('previous_schedule_id')->count()),
            'proctor' => $room->proctor ? [
                'id' => $room->proctor->id,
                'name' => $room->proctor->name,
                'email' => $room->proctor->email,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->mapSummary($examinationSchedule),
                'students' => $students,
                'passkey_counts' => app(\App\Services\ExaminationPasskeyService::class)->countsForSchedule($examinationSchedule),
                'moved_away' => $movedAway,
                'reschedule_targets' => $availableTargets,
                'available_rooms' => $availableRooms,
                'email_instructions' => $examinationSchedule->email_instructions,
                'default_instructions' => ExaminationSchedule::defaultInstructions(),
                'include_app_download' => (bool) $examinationSchedule->include_app_download,
                'app_download_name' => $examinationSchedule->app_download_name,
                'app_download_url' => $examinationSchedule->app_download_url,
                'app_download_instructions' => $examinationSchedule->app_download_instructions,
                'note' => 'Students are scheduled by time slot only. They may take the exam in any available classroom during this batch. Use Reschedule when an examinee cannot attend this day for a valid reason.',
            ],
        ]);
    }

    private function mapSummary(ExaminationSchedule $schedule): array
    {
        $rooms = $schedule->rooms ?? collect();
        $proctors = $rooms->map(fn ($room) => $room->proctor)
            ->filter()
            ->unique('id')
            ->values();

        $dateLabel = optional($schedule->exam_date)->format('M j, Y');
        $timeSlot = $schedule->time_slot
            ?: trim(($this->formatTime($schedule->start_time) ?? '').'–'.($this->formatTime($schedule->end_time) ?? ''), '–');

        return [
            'id' => $schedule->id,
            'schedule_id' => $schedule->id,
            'title' => $schedule->title ?: 'Entrance Examination',
            'exam_date' => optional($schedule->exam_date)->toDateString(),
            'date_label' => $dateLabel,
            'batch_code' => $schedule->batch_code,
            'batch_label' => trim(($dateLabel ?: '').', '.($schedule->batch_code ?: '')),
            'start_time' => $this->formatTime($schedule->start_time),
            'end_time' => $this->formatTime($schedule->end_time),
            'time_slot' => $timeSlot,
            'course' => $schedule->course ?: 'General Entrance Examination',
            'status' => $schedule->status?->value ?? $schedule->status,
            'expected_examinees' => (int) $schedule->expected_examinees,
            'registered_count' => (int) ($schedule->registrations_count ?? $schedule->registrations()->count()),
            'keys_sent_count' => (int) ($schedule->passkey_sent_count ?? $schedule->registrations()->where('passkey_email_sent', true)->count()),
            'keys_total_count' => (int) ($schedule->registrations_count ?? $schedule->registrations()->count()),
            'room_count' => (int) ($schedule->rooms_count ?? $rooms->count()),
            'rooms_label' => $rooms->pluck('room_name')->filter()->implode(', '),
            'rooms' => $rooms->map(fn ($r) => [
                'id' => $r->id,
                'room_name' => $r->room_name,
                'capacity' => (int) $r->capacity,
                'registrations_count' => (int) ($r->registrations_count ?? $r->registrations()->count()),
                'available_seats' => max(0, (int) $r->capacity - (int) ($r->registrations_count ?? $r->registrations()->count())),
            ])->values()->all(),
            'proctor_count' => $proctors->count(),
            'proctor_names' => $proctors->pluck('name')->all(),
            'display_name' => trim(($dateLabel ?: '').' · '.($schedule->batch_code ?: '').' · '.($timeSlot ?: '')),
            'email_instructions' => $schedule->email_instructions,
            'include_app_download' => (bool) $schedule->include_app_download,
            'app_download_name' => $schedule->app_download_name,
            'app_download_url' => $schedule->app_download_url,
            'app_download_instructions' => $schedule->app_download_instructions,
        ];
    }

    private function batchLabel(ExaminationSchedule $schedule): string
    {
        $dateLabel = optional($schedule->exam_date)->format('M j, Y');
        $timeSlot = $schedule->time_slot
            ?: trim(($this->formatTime($schedule->start_time) ?? '').'–'.($this->formatTime($schedule->end_time) ?? ''), '–');

        return trim(($dateLabel ?: '').', '.($schedule->batch_code ?: '').' · '.($timeSlot ?: ''));
    }

    private function formatTime(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        try {
            return Carbon::parse($time)->format('H:i');
        } catch (\Throwable) {
            return (string) $time;
        }
    }
}
