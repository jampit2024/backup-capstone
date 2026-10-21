<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Models\ActivityLog;
use App\Models\ExaminationAnswer;
use App\Models\ExaminationRegistration;
use App\Models\ExamSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pushes completed LAN exam results to the central admin API when internet is available.
 *
 * Operating model:
 * 1. Exam day: students + proctor use only the LAN Laravel host (no internet required).
 * 2. Results land in the local DB as students submit / proctor ends.
 * 3. When the proctor/PC has internet, call sync() to push pending results to ADMIN_SYNC_URL.
 */
class AdminResultSyncService
{
    public function pendingCount(?ExamSession $session = null): int
    {
        $query = ExaminationRegistration::query()
            ->whereNotNull('score')
            ->where(function ($q) {
                $q->whereNull('sync_status')
                    ->orWhereIn('sync_status', ['pending', 'failed']);
            });

        if ($session) {
            $query->where('exam_session_id', $session->id);
        }

        return $query->count();
    }

    /**
     * @return array{synced: int, failed: int, skipped: int, message: string}
     */
    public function sync(?ExamSession $session = null): array
    {
        $base = rtrim((string) config('services.admin_sync.url', env('ADMIN_SYNC_URL', '')), '/');
        $token = (string) config('services.admin_sync.token', env('ADMIN_SYNC_TOKEN', ''));

        if ($base === '' || $token === '') {
            throw new RuntimeException(
                'Admin sync is not configured. Set ADMIN_SYNC_URL and ADMIN_SYNC_TOKEN on this LAN server.'
            );
        }

        $query = ExaminationRegistration::query()
            ->with(['applicant', 'schedule', 'answers', 'examSession.room'])
            ->whereNotNull('score')
            ->where(function ($q) {
                $q->whereNull('sync_status')
                    ->orWhereIn('sync_status', ['pending', 'failed']);
            });

        if ($session) {
            $query->where('exam_session_id', $session->id);
        }

        $rows = $query->orderBy('id')->limit(200)->get();
        if ($rows->isEmpty()) {
            return [
                'synced' => 0,
                'failed' => 0,
                'skipped' => 0,
                'message' => 'No pending results to sync.',
            ];
        }

        $payload = [
            'source' => config('app.name', 'METCC-LAN'),
            'synced_at' => now()->toIso8601String(),
            'results' => $rows->map(fn (ExaminationRegistration $reg) => $this->mapRegistration($reg))->values()->all(),
        ];

        try {
            $response = Http::timeout(45)
                ->withToken($token)
                ->acceptJson()
                ->post($base.'/sync/exam-results', $payload);
        } catch (\Throwable $e) {
            Log::warning('Admin sync network error', ['error' => $e->getMessage()]);
            throw new RuntimeException(
                'Cannot reach the admin server. Connect to the internet and try again. ('.$e->getMessage().')'
            );
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?: 'Admin sync rejected the payload (HTTP '.$response->status().').';
            throw new RuntimeException($message);
        }

        $acceptedIds = collect($response->json('data.accepted_ids') ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();
        $rejectedRows = collect($response->json('data.rejected') ?? []);

        // If the admin API acknowledged success but omitted IDs, treat the whole batch as accepted.
        if ($acceptedIds->isEmpty() && $rejectedRows->isEmpty() && $response->json('success') !== false) {
            $acceptedIds = $rows->pluck('id')->map(fn ($id) => (int) $id);
        }

        $synced = 0;
        $failed = 0;
        foreach ($rows as $reg) {
            if ($acceptedIds->contains((int) $reg->id)) {
                $reg->forceFill([
                    'sync_status' => 'synced',
                    'synced_at' => now(),
                    'sync_error' => null,
                ])->save();
                $synced++;
            } else {
                $reg->forceFill([
                    'sync_status' => 'failed',
                    'sync_error' => 'Not accepted by admin server (applicant/schedule mismatch?).',
                ])->save();
                $failed++;
            }
        }

        if ($session) {
            $stillPending = $this->pendingCount($session);
            $session->forceFill([
                'sync_status' => $stillPending === 0 ? 'synced' : 'pending',
                'synced_at' => $stillPending === 0 ? now() : null,
            ])->save();
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'skipped' => 0,
            'message' => "Synced {$synced} result(s) to the administrator.",
        ];
    }

    /**
     * Ingest results on the central admin instance.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array{accepted_ids: list<int>, accepted_client_ids: list<string>, accepted_keys: list<string>, rejected: list<array<string, mixed>>, created: int, updated: int}
     */
    public function ingest(array $results): array
    {
        $accepted = [];
        $acceptedClientIds = [];
        $acceptedKeys = [];
        $rejected = [];
        $created = 0;
        $updated = 0;

        foreach ($results as $row) {
            $localId = (int) ($row['lan_registration_id'] ?? $row['id'] ?? 0);
            $clientLocalId = trim((string) ($row['client_local_id'] ?? ''));
            $applicantCode = trim((string) ($row['applicant_code'] ?? ''));
            $scheduleId = (int) ($row['examination_schedule_id'] ?? 0);

            // FIX 4: Never silently skip data with continue; without auditing
            if ($applicantCode === '' || $scheduleId < 1) {
                $reason = $scheduleId < 1
                    ? "Invalid examination_schedule_id ({$scheduleId}). Must be a positive integer."
                    : "Missing applicant_code.";

                Log::warning('Admin sync ingest rejected record', [
                    'client_local_id' => $clientLocalId,
                    'applicant_code' => $applicantCode,
                    'schedule_id' => $scheduleId,
                    'reason' => $reason,
                ]);

                $rejected[] = [
                    'client_local_id' => $clientLocalId,
                    'applicant_code' => $applicantCode,
                    'examination_schedule_id' => $scheduleId,
                    'reason' => $reason,
                ];
                continue;
            }

            $applicant = \App\Models\Applicant::query()
                ->where('applicant_code', $applicantCode)
                ->first();

            if (! $applicant) {
                $reason = "Applicant with code '{$applicantCode}' not found in database.";

                Log::warning('Admin sync ingest rejected record', [
                    'client_local_id' => $clientLocalId,
                    'applicant_code' => $applicantCode,
                    'schedule_id' => $scheduleId,
                    'reason' => $reason,
                ]);

                $rejected[] = [
                    'client_local_id' => $clientLocalId,
                    'applicant_code' => $applicantCode,
                    'examination_schedule_id' => $scheduleId,
                    'reason' => $reason,
                ];
                continue;
            }

            $rescheduledRegistration = ExaminationRegistration::query()
                ->where('applicant_id', $applicant->id)
                ->where(function ($query) {
                    $query->whereNotNull('previous_schedule_id')
                        ->orWhereNotNull('rescheduled_at');
                })
                ->where('examination_schedule_id', '!=', $scheduleId)
                ->orderByDesc('rescheduled_at')
                ->latest('id')
                ->first();

            if ($rescheduledRegistration) {
                $reason = 'Result schedule does not match the current registration after rescheduling.';

                ActivityLog::query()->create([
                    'action' => 'rescheduled_result_sync_rejected',
                    'description' => sprintf(
                        'Rejected result sync for registration %d: payload schedule %d does not match current schedule %d after rescheduling.',
                        $rescheduledRegistration->id,
                        $scheduleId,
                        $rescheduledRegistration->examination_schedule_id,
                    ),
                    'subject_type' => ExaminationRegistration::class,
                    'subject_id' => $rescheduledRegistration->id,
                ]);

                Log::warning('Admin sync rejected result for rescheduled registration', [
                    'client_local_id' => $clientLocalId,
                    'applicant_code' => $applicantCode,
                    'registration_id' => $rescheduledRegistration->id,
                    'payload_schedule_id' => $scheduleId,
                    'current_schedule_id' => $rescheduledRegistration->examination_schedule_id,
                ]);

                $rejected[] = [
                    'client_local_id' => $clientLocalId,
                    'applicant_code' => $applicantCode,
                    'examination_schedule_id' => $scheduleId,
                    'reason' => $reason,
                ];
                continue;
            }

            // Sync session metadata (if provided)
            $session = ExamSession::query()
                ->where('examination_schedule_id', $scheduleId)
                ->whereIn('status', ['lobby_open', 'in_progress', 'ended'])
                ->latest('id')
                ->first();

            if ($session) {
                $session->forceFill([
                    'wifi_ssid' => $row['wifi_ssid'] ?? $session->wifi_ssid,
                    'local_server_ip' => $row['local_server_ip'] ?? $session->local_server_ip,
                    'sync_mode' => $row['sync_mode'] ?? 'offline_lan',
                ])->save();
            }

            $registration = ExaminationRegistration::query()
                ->where('examination_schedule_id', $scheduleId)
                ->where('applicant_id', $applicant->id)
                ->first();

            if (! $registration) {
                $registration = ExaminationRegistration::query()->create([
                    'examination_schedule_id' => $scheduleId,
                    'applicant_id' => $applicant->id,
                    'attendance_status' => $row['attendance_status'] ?? 'present',
                    'result_status' => $row['result_status'] ?? 'pending',
                ]);
                $created++;
            } else {
                if ($registration->examination_method === 'manual') {
                    $rejected[] = [
                        'client_local_id' => $clientLocalId,
                        'applicant_code' => $applicantCode,
                        'examination_schedule_id' => $scheduleId,
                        'reason' => 'A manual paper-based result already exists for this examination registration.',
                    ];
                    continue;
                }
                $updated++;
            }

            $score = array_key_exists('score', $row) ? $row['score'] : $registration->score;
            $score = $score !== null ? (float) $score : null;

            $incomingStatus = strtolower(trim((string) ($row['result_status'] ?? '')));
            $resultStatus = match ($incomingStatus) {
                'passed' => ResultStatus::Passed,
                'failed' => ResultStatus::Failed,
                'pending' => ResultStatus::Pending,
                default => null,
            };

            // Derive pass/fail from score when the payload omitted result_status
            // (older clients / narrow validation used to strip it).
            if ($resultStatus === null || $resultStatus === ResultStatus::Pending) {
                if ($score !== null) {
                    $passed = app(GradingService::class)->isPassing($score, $applicant->course_applied);
                    $resultStatus = $passed ? ResultStatus::Passed : ResultStatus::Failed;
                } else {
                    $resultStatus = $registration->result_status ?? ResultStatus::Pending;
                }
            }

            $gradePoint = array_key_exists('grade_point', $row)
                ? $row['grade_point']
                : $registration->grade_point;
            if ($gradePoint === null && $score !== null) {
                $gradePoint = app(GradingService::class)->percentageToGradePoint($score);
            }

            $registration->forceFill([
                'attendance_status' => $row['attendance_status'] ?? $registration->attendance_status,
                'result_status' => $resultStatus,
                'score' => $score ?? $registration->score,
                'items_correct' => $row['items_correct'] ?? $registration->items_correct,
                'items_total' => $row['items_total'] ?? $registration->items_total,
                'grade_point' => $gradePoint,
                'lobby_status' => 'finished',
                'sync_status' => 'synced',
                'synced_at' => now(),
                'sync_error' => null,
            ])->save();

            if (! empty($row['answers']) && is_array($row['answers'])) {
                ExaminationAnswer::query()->where('examination_registration_id', $registration->id)->delete();
                foreach ($row['answers'] as $answer) {
                    ExaminationAnswer::query()->create([
                        'examination_registration_id' => $registration->id,
                        'exam_question_id' => (int) ($answer['exam_question_id'] ?? 0),
                        'selected_answer' => $answer['selected_answer'] ?? null,
                        'is_correct' => (bool) ($answer['is_correct'] ?? false),
                    ]);
                }
            }

            if ($applicant) {
                $status = $applicant->status?->value ?? $applicant->status;
                if ($status !== 'examined' && $status !== \App\Enums\ApplicantStatus::Examined) {
                    $applicant->forceFill([
                        'status' => \App\Enums\ApplicantStatus::Examined,
                    ])->save();
                }
            }

            $accepted[] = $localId > 0 ? $localId : $registration->id;
            if ($clientLocalId !== '') {
                $acceptedClientIds[] = $clientLocalId;
            }
            $acceptedKeys[] = $applicantCode.'|'.$scheduleId;
        }

        Log::info('Admin sync ingest complete', [
            'total' => count($results),
            'created' => $created,
            'updated' => $updated,
            'rejected' => count($rejected),
        ]);

        return [
            'accepted_ids' => $accepted,
            'accepted_client_ids' => $acceptedClientIds,
            'accepted_keys' => $acceptedKeys,
            'rejected' => $rejected,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    private function mapRegistration(ExaminationRegistration $reg): array
    {
        return [
            'lan_registration_id' => $reg->id,
            'id' => $reg->id,
            'examination_schedule_id' => $reg->examination_schedule_id,
            'exam_session_id' => $reg->exam_session_id,
            'applicant_code' => $reg->applicant?->applicant_code,
            'applicant_name' => $reg->applicant?->name,
            'course_applied' => $reg->applicant?->course_applied,
            'gmail' => $reg->applicant?->gmail,
            'attendance_status' => $reg->attendance_status?->value ?? $reg->attendance_status,
            'result_status' => $reg->result_status?->value ?? $reg->result_status,
            'score' => $reg->score,
            'items_correct' => $reg->items_correct,
            'items_total' => $reg->items_total,
            'grade_point' => $reg->grade_point,
            'room_name' => $reg->examSession?->room?->room_name,
            'batch_code' => $reg->schedule?->batch_code,
            'exam_date' => optional($reg->schedule?->exam_date)?->toDateString(),
            'wifi_ssid' => $reg->examSession?->wifi_ssid,
            'local_server_ip' => $reg->examSession?->local_server_ip,
            'sync_mode' => $reg->examSession?->sync_mode,
            'answers' => $reg->answers->map(fn (ExaminationAnswer $a) => [
                'exam_question_id' => $a->exam_question_id,
                'selected_answer' => $a->selected_answer,
                'is_correct' => (bool) $a->is_correct,
            ])->values()->all(),
        ];
    }
}
