<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use App\Jobs\SendExaminationResultEmailJob;
use App\Mail\ExaminationResultMail;
use App\Models\ActivityLog;
use App\Models\ExaminationAnswer;
use App\Models\EmailNotification;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\ExamQuestion;
use App\Models\QuestionBank;
use App\Models\ResultEmailBatch;
use App\Models\User;
use App\Services\GradingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExaminationResultService
{
    /**
     * Persist in-progress answers without grading (LAN autosave).
     * Used so force-end / timer-end can grade real selections.
     *
     * @param  list<array{exam_question_id: int, selected_answer?: string|null}>  $answers
     */
    public function saveProgress(ExaminationRegistration $registration, array $answers): ExaminationRegistration
    {
        return DB::transaction(function () use ($registration, $answers) {
            $registration = ExaminationRegistration::query()
                ->with('examSession')
                ->lockForUpdate()
                ->findOrFail($registration->id);
            $this->ensureOnlineResultWritable($registration);
            $bankId = $registration->examSession?->question_bank_id
                ?? QuestionBank::query()->where('is_active', true)->value('id');

            $questionIds = collect($answers)->pluck('exam_question_id')->filter()->unique()->values();

            $questions = ExamQuestion::query()
                ->whereIn('id', $questionIds)
                ->when($bankId, function ($q) use ($bankId) {
                    $q->whereHas('subject', fn ($s) => $s->where('question_bank_id', $bankId));
                })
                ->where('is_selected_for_exam', true)
                ->get()
                ->keyBy('id');

            ExaminationAnswer::query()->where('examination_registration_id', $registration->id)->delete();

            foreach ($answers as $row) {
                $qid = (int) ($row['exam_question_id'] ?? 0);
                $selected = strtoupper(trim((string) ($row['selected_answer'] ?? '')));
                $question = $questions->get($qid);
                if (! $question) {
                    continue;
                }
                ExaminationAnswer::query()->create([
                    'examination_registration_id' => $registration->id,
                    'exam_question_id' => $qid,
                    'selected_answer' => $selected !== '' ? $selected : null,
                    'is_correct' => false,
                ]);
            }

            return $registration->fresh(['answers']);
        });
    }

    public function submitAnswers(ExaminationRegistration $registration, array $answers): ExaminationRegistration
    {
        return DB::transaction(function () use ($registration, $answers) {
            $registration = ExaminationRegistration::query()
                ->with(['examSession', 'applicant'])
                ->lockForUpdate()
                ->findOrFail($registration->id);
            $this->ensureOnlineResultWritable($registration);
            $bankId = $registration->examSession?->question_bank_id
                ?? QuestionBank::query()->where('is_active', true)->value('id');

            $questionIds = collect($answers)->pluck('exam_question_id')->filter()->unique()->values();

            $questions = ExamQuestion::query()
                ->whereIn('id', $questionIds)
                ->when($bankId, function ($q) use ($bankId) {
                    $q->whereHas('subject', fn ($s) => $s->where('question_bank_id', $bankId));
                })
                ->where('is_selected_for_exam', true)
                ->get()
                ->keyBy('id');

            $correct = 0;
            $total = max(
                1,
                ExamQuestion::query()
                    ->where('is_selected_for_exam', true)
                    ->where('status', 'active')
                    ->when($bankId, function ($q) use ($bankId) {
                        $q->whereHas('subject', fn ($s) => $s->where('question_bank_id', $bankId));
                    })
                    ->count()
            );

            ExaminationAnswer::query()->where('examination_registration_id', $registration->id)->delete();

            foreach ($answers as $row) {
                $qid = (int) ($row['exam_question_id'] ?? 0);
                $selected = strtoupper(trim((string) ($row['selected_answer'] ?? '')));
                $question = $questions->get($qid);
                if (! $question) {
                    continue;
                }
                $isCorrect = strtoupper((string) $question->correct_answer) === $selected;
                if ($isCorrect) {
                    $correct++;
                }
                ExaminationAnswer::query()->create([
                    'examination_registration_id' => $registration->id,
                    'exam_question_id' => $qid,
                    'selected_answer' => $selected ?: null,
                    'is_correct' => $isCorrect,
                ]);
            }

            $score = round(($correct / $total) * 100, 2);
            $course = $registration->applicant?->course_applied;
            $grading = app(GradingService::class);
            $gradePoint = $grading->percentageToGradePoint($score);
            $passed = $grading->isPassing($score, $course);

            $registration->forceFill([
                'attendance_status' => AttendanceStatus::Present,
                'result_status' => $passed ? ResultStatus::Passed : ResultStatus::Failed,
                'score' => $score,
                'items_correct' => $correct,
                'items_total' => $total,
                'grade_point' => $gradePoint,
                'sync_status' => 'pending',
                'synced_at' => null,
                'sync_error' => null,
            ])->save();

            $registration->applicant?->forceFill([
                'status' => \App\Enums\ApplicantStatus::Examined,
            ])->save();

            return $registration->fresh(['applicant', 'schedule', 'answers']);
        });
    }

    private function ensureOnlineResultWritable(ExaminationRegistration $registration): void
    {
        $status = $registration->result_status?->value ?? $registration->result_status;
        if ($registration->examination_method === 'manual') {
            throw ValidationException::withMessages([
                'registration' => 'This registration has a manual paper-based result and cannot accept online answers.',
            ]);
        }
        if ($registration->score !== null && in_array($status, [ResultStatus::Passed->value, ResultStatus::Failed->value], true)) {
            throw ValidationException::withMessages([
                'registration' => 'This examination result is already finalized.',
            ]);
        }
    }

    public function createBatch(User $admin, array $registrationIds, array $filters = []): ResultEmailBatch
    {
        $regs = ExaminationRegistration::query()
            ->with('applicant')
            ->whereIn('id', $registrationIds)
            ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
            ->whereNotNull('score')
            ->get();

        $eligible = $regs->filter(function (ExaminationRegistration $reg) {
            $email = $reg->applicant?->notificationEmail();
            $alreadySent = (bool) $reg->email_sent
                || ($reg->email_status?->value ?? $reg->email_status) === ResultEmailStatus::Sent->value;

            return $email && ! $alreadySent;
        })->values();

        $batch = ResultEmailBatch::query()->create([
            'requested_by' => $admin->id,
            'total' => $eligible->count(),
            'queued' => $eligible->count(),
            'sent' => 0,
            'failed' => 0,
            'status' => 'queued',
            'filters' => $filters,
            'started_at' => now(),
        ]);

        foreach ($eligible as $reg) {
            $reg->forceFill([
                'email_status' => ResultEmailStatus::Queued,
                'result_email_batch_id' => $batch->id,
                'email_error_message' => null,
            ])->save();

            SendExaminationResultEmailJob::dispatch($reg->id, $batch->id);
        }

        ActivityLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'batch_result_sent',
            'description' => "Queued result emails for {$eligible->count()} applicants.",
            'subject_type' => ResultEmailBatch::class,
            'subject_id' => $batch->id,
        ]);

        return $batch->fresh();
    }

    /**
     * Queue score emails for every completed examinee in a schedule batch.
     *
     * @param  int|string  $batch  Schedule ID or batch_code (e.g. "Batch 1")
     */
    public function createBatchBySchedule(User $admin, int|string $batch): ResultEmailBatch
    {
        $scheduleQuery = ExaminationSchedule::query();
        if (is_numeric($batch)) {
            $scheduleQuery->where('id', (int) $batch);
        } else {
            $scheduleQuery->where('batch_code', (string) $batch);
        }

        $scheduleIds = $scheduleQuery->pluck('id');
        if ($scheduleIds->isEmpty()) {
            abort(404, 'Batch not found.');
        }

        $ids = ExaminationRegistration::query()
            ->whereIn('examination_schedule_id', $scheduleIds)
            ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
            ->whereNotNull('score')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            abort(422, 'No completed examination results found for this batch.');
        }

        return $this->createBatch($admin, $ids, [
            'mode' => 'send_batch_email',
            'batch' => $batch,
            'schedule_ids' => $scheduleIds->all(),
        ]);
    }

    public function sendOne(ExaminationRegistration $registration): void
    {
        $applicant = $registration->applicant;
        $email = $applicant?->notificationEmail();
        if (! $email) {
            EmailNotification::query()->create([
                'registration_id' => $registration->id,
                'schedule_id' => $registration->examination_schedule_id,
                'recipient_name' => $applicant?->name,
                'recipient_email' => null,
                'type' => 'result',
                'status' => 'failed',
                'error_details' => 'Student has no registered email address.',
            ]);
            throw new \RuntimeException('Applicant has no registered email address.');
        }

        $registration->increment('email_attempts');
        $notification = EmailNotification::query()->create([
            'registration_id' => $registration->id,
            'schedule_id' => $registration->examination_schedule_id,
            'recipient_name' => $applicant?->name,
            'recipient_email' => $email,
            'type' => 'result',
            'status' => 'processing',
        ]);
        try {
            app(ConfiguredMailer::class)->send($email, new ExaminationResultMail($registration->fresh(['applicant', 'schedule'])));
            $notification->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        } catch (\Throwable $exception) {
            $classified = app(EmailFailureClassifier::class)->classify($exception);
            $notification->forceFill(['status' => 'failed', 'error_details' => $classified['message']])->save();
            throw $exception;
        }

        $registration->forceFill([
            'email_status' => ResultEmailStatus::Sent,
            'email_sent' => true,
            'email_sent_at' => now(),
            'email_error_message' => null,
        ])->save();
    }

    public function markFailed(ExaminationRegistration $registration, string $message): void
    {
        $registration->forceFill([
            'email_status' => ResultEmailStatus::Failed,
            'email_error_message' => Str::limit($message, 500),
        ])->save();
    }

    public function refreshBatchCounters(ResultEmailBatch $batch): ResultEmailBatch
    {
        $sent = ExaminationRegistration::query()
            ->where('result_email_batch_id', $batch->id)
            ->where('email_status', ResultEmailStatus::Sent)
            ->count();
        $failed = ExaminationRegistration::query()
            ->where('result_email_batch_id', $batch->id)
            ->where('email_status', ResultEmailStatus::Failed)
            ->count();
        $queued = ExaminationRegistration::query()
            ->where('result_email_batch_id', $batch->id)
            ->where('email_status', ResultEmailStatus::Queued)
            ->count();

        $batch->forceFill([
            'sent' => $sent,
            'failed' => $failed,
            'queued' => $queued,
            'status' => ($queued > 0) ? 'sending' : 'completed',
            'completed_at' => $queued > 0 ? null : now(),
        ])->save();

        return $batch->fresh();
    }

    public function emailStats(): array
    {
        return [
            'total_results' => ExaminationRegistration::query()
                ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
                ->count(),
            'results_sent' => ExaminationRegistration::query()->where('email_status', ResultEmailStatus::Sent)->count(),
            'pending_emails' => ExaminationRegistration::query()
                ->whereIn('email_status', [ResultEmailStatus::Pending, ResultEmailStatus::Queued])
                ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
                ->count(),
            'failed_emails' => ExaminationRegistration::query()->where('email_status', ResultEmailStatus::Failed)->count(),
        ];
    }

}
