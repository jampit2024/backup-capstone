<?php

namespace App\Http\Controllers\Result;

use App\Enums\AttendanceStatus;
use App\Enums\ApplicantStatus;
use App\Enums\ExaminationStatus;
use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\ManualScoreHistory;
use App\Models\QuestionBank;
use App\Services\GradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualScoreController extends Controller
{
    public function __construct(private GradingService $grading)
    {
    }

    public function schedules(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $schedules = ExaminationSchedule::query()
            ->withCount('registrations')
            ->orderByDesc('exam_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (ExaminationSchedule $schedule): array => [
                'id' => $schedule->id,
                'title' => $schedule->title ?: 'Entrance Examination',
                'course' => $schedule->course ?: 'General Entrance Examination',
                'exam_date' => optional($schedule->exam_date)->toDateString(),
                'time_slot' => $schedule->time_slot,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'batch_code' => $schedule->batch_code,
                'status' => $schedule->status?->value ?? $schedule->status,
                'maximum_score' => $this->maximumScoreForSchedule($schedule->id),
                'registered_count' => (int) $schedule->registrations_count,
                'passing_percentage' => (float) $this->grading->settingForCourse($schedule->course)->passing_percentage,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $schedules]);
    }

    public function students(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'schedule_id' => ['sometimes', 'nullable', 'integer', 'exists:exam_schedules,id'],
            'search' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $search = trim($validated['search']);
        if (mb_strlen($search) < 2) {
            throw ValidationException::withMessages(['search' => 'Enter at least two characters to search for a student.']);
        }

        $query = ExaminationRegistration::query()
            ->with([
                'applicant:id,applicant_code,name,course_applied',
                'schedule:id,title,course,exam_date,time_slot,start_time,end_time,batch_code,status',
                'examSession:id',
            ])
            ->withCount('answers')
            ->whereHas('applicant')
            ->whereHas('schedule')
            ->when(! empty($validated['schedule_id']), fn ($registrations) => $registrations->where('examination_schedule_id', $validated['schedule_id']));

        $query->whereHas('applicant', function ($applicants) use ($search): void {
            $applicants->where('name', 'like', "%{$search}%")
                ->orWhere('applicant_code', 'like', "%{$search}%");
        });

        $maximumScores = [];
        $rows = $query->join('applicants as roster_applicants', 'roster_applicants.id', '=', 'exam_registrations.applicant_id')
            ->select('exam_registrations.*')
            ->orderBy('roster_applicants.name')
            ->orderByDesc('exam_registrations.examination_schedule_id')
            ->limit(50)
            ->get()
            ->map(function (ExaminationRegistration $registration) use (&$maximumScores): array {
                $applicant = $registration->applicant;
                $schedule = $registration->schedule;
                $scheduleId = (int) $registration->examination_schedule_id;
                if (! array_key_exists($scheduleId, $maximumScores)) {
                    $maximumScores[$scheduleId] = $this->maximumScoreForSchedule($scheduleId);
                }
                $maximumScore = $maximumScores[$scheduleId];
                $method = $registration->examination_method ?: 'online';
                $manualResult = $method === 'manual';
                $status = $registration->result_status?->value ?? $registration->result_status;
                $onlineActivity = $registration->exam_session_id !== null
                    || (int) $registration->answers_count > 0
                    || $registration->passkey_used_at !== null;
                $cancelled = ($schedule?->status?->value ?? $schedule?->status) === ExaminationStatus::Cancelled->value;
                $eligible = ! $cancelled && (($manualResult && ! $onlineActivity)
                    || ($registration->score === null && $status === ResultStatus::Pending->value && ! $onlineActivity));
                $reason = null;

                if (! $eligible) {
                    $reason = $cancelled
                        ? 'This examination schedule is cancelled.'
                        : ($registration->score !== null
                        ? 'An official online result already exists for this student and schedule.'
                        : 'This student has an online exam session or submitted answers for this schedule.');
                }

                return [
                    'registration_id' => $registration->id,
                    'applicant_id' => $applicant->id,
                    'student_id' => $applicant->applicant_code,
                    'student_name' => $applicant->name,
                    'course' => $applicant->course_applied,
                    'schedule_id' => $scheduleId,
                    'schedule' => [
                        'id' => $scheduleId,
                        'title' => $schedule?->title ?: 'Entrance Examination',
                        'course' => $schedule?->course ?: 'General Entrance Examination',
                        'exam_date' => optional($schedule?->exam_date)->toDateString(),
                        'time_slot' => $schedule?->time_slot,
                        'start_time' => $schedule?->start_time,
                        'end_time' => $schedule?->end_time,
                        'batch_code' => $schedule?->batch_code,
                        'status' => $schedule?->status?->value ?? $schedule?->status,
                        'maximum_score' => $maximumScore,
                        'passing_percentage' => (float) $this->grading->settingForCourse($schedule?->course)->passing_percentage,
                    ],
                    'eligible' => $eligible,
                    'ineligible_reason' => $reason,
                    'examination_method' => $method,
                    'result_status' => $status,
                    'score' => $registration->score !== null ? (float) $registration->score : null,
                    'manual_score' => $registration->manual_score !== null ? (float) $registration->manual_score : null,
                    'manual_max_score' => $registration->manual_max_score !== null
                        ? (float) $registration->manual_max_score
                        : (float) $maximumScore,
                    'manual_remarks' => $registration->manual_remarks,
                    'manual_recorded_at' => optional($registration->manual_recorded_at)?->toISOString(),
                    'passing_percentage' => (float) $this->grading->settingForCourse($applicant->course_applied)->passing_percentage,
                ];
            })
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate($this->scoreRules(requireConfirmation: true, requireReason: false) + [
            'schedule_id' => ['required', 'integer', 'exists:exam_schedules,id'],
            'applicant_id' => ['required', 'integer', 'exists:applicants,id'],
        ]);

        $result = DB::transaction(function () use ($request, $validated): array {
            $registration = ExaminationRegistration::query()
                ->where('examination_schedule_id', $validated['schedule_id'])
                ->where('applicant_id', $validated['applicant_id'])
                ->lockForUpdate()
                ->with(['applicant', 'schedule'])
                ->first();

            if (! $registration) {
                throw ValidationException::withMessages([
                    'applicant_id' => 'The selected student is not registered for this examination schedule.',
                ]);
            }

            if ($registration->examination_method === 'manual') {
                return ['conflict' => true, 'message' => 'A manual result already exists for this student and schedule. Edit that score instead.'];
            }

            $resultStatus = $registration->result_status?->value ?? $registration->result_status;
            if ($registration->score !== null
                || $resultStatus !== ResultStatus::Pending->value
                || $registration->exam_session_id !== null
                || $registration->passkey_used_at !== null
                || $registration->answers()->exists()) {
                return ['conflict' => true, 'message' => 'This registration already has an exam attempt/result or is not pending. The official record was left unchanged.'];
            }

            if (($registration->schedule?->status?->value ?? $registration->schedule?->status) === ExaminationStatus::Cancelled->value) {
                throw ValidationException::withMessages(['schedule_id' => 'Scores cannot be recorded for a cancelled examination.']);
            }

            $maximumScore = $this->maximumScoreForSchedule($registration->examination_schedule_id);
            if ((float) $validated['score'] > $maximumScore) {
                throw ValidationException::withMessages(['score' => "Score cannot exceed the examination maximum of {$maximumScore}."]);
            }

            return $this->saveManualResult($registration, $request, $validated, 'created', null, $maximumScore);
        });

        if (! empty($result['conflict'])) {
            return response()->json(['success' => false, 'message' => $result['message']], 409);
        }

        return response()->json(['success' => true, 'data' => $result, 'message' => 'Manual examination score saved.'], 201);
    }

    public function update(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate($this->scoreRules(requireConfirmation: true, requireReason: true));

        $result = DB::transaction(function () use ($request, $registration, $validated): array {
            $locked = ExaminationRegistration::query()
                ->whereKey($registration->getKey())
                ->lockForUpdate()
                ->with(['applicant', 'schedule'])
                ->firstOrFail();

            if ($locked->examination_method !== 'manual') {
                return ['conflict' => true, 'message' => 'Only an existing manual paper-based score can be corrected. Online scores are protected.'];
            }

            if ($locked->exam_session_id !== null || $locked->passkey_used_at !== null || $locked->answers()->exists()) {
                return ['conflict' => true, 'message' => 'An online exam attempt is linked to this registration. The result was left unchanged.'];
            }
            if (($locked->schedule?->status?->value ?? $locked->schedule?->status) === ExaminationStatus::Cancelled->value) {
                throw ValidationException::withMessages(['schedule_id' => 'Scores cannot be corrected for a cancelled examination.']);
            }

            $maximumScore = (float) $locked->manual_max_score;
            if ((float) $validated['score'] > $maximumScore) {
                throw ValidationException::withMessages(['score' => "Score cannot exceed the original examination maximum of {$maximumScore}."]);
            }

            return $this->saveManualResult($locked, $request, $validated, 'updated', (float) $locked->manual_score, $maximumScore);
        });

        if (! empty($result['conflict'])) {
            return response()->json(['success' => false, 'message' => $result['message']], 409);
        }

        return response()->json(['success' => true, 'data' => $result, 'message' => 'Manual score correction saved.']);
    }

    public function history(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        if ($registration->examination_method !== 'manual') {
            return response()->json(['success' => false, 'message' => 'This registration has no manual score history.'], 404);
        }

        $rows = ManualScoreHistory::query()
            ->with('actor:id,name')
            ->where('examination_registration_id', $registration->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ManualScoreHistory $history): array => [
                'id' => $history->id,
                'operation' => $history->operation,
                'previous_score' => $history->previous_score !== null ? (float) $history->previous_score : null,
                'new_score' => (float) $history->new_score,
                'maximum_score' => (float) $history->maximum_score,
                'reason' => $history->reason,
                'recorded_by' => $history->actor?->name ?? 'Deleted user',
                'recorded_at' => optional($history->created_at)?->toISOString(),
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function scoreRules(bool $requireConfirmation, bool $requireReason): array
    {
        return [
            'score' => ['required', 'numeric', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reason' => [$requireReason ? 'required' : 'nullable', 'string', 'max:1000'],
            'paper_based_confirmed' => [$requireConfirmation ? 'accepted' : 'sometimes'],
            'maximum_score' => ['prohibited'],
            'manual_max_score' => ['prohibited'],
        ];
    }

    private function saveManualResult(
        ExaminationRegistration $registration,
        Request $request,
        array $validated,
        string $operation,
        ?float $previousScore,
        float $maximumScore
    ): array {
        $score = round((float) $validated['score'], 2);
        $percentage = round(($score / $maximumScore) * 100, 2);
        $passed = $this->grading->isPassing($percentage, $registration->applicant?->course_applied);
        $registration->forceFill([
            'examination_method' => 'manual',
            'manual_score' => $score,
            'manual_max_score' => $maximumScore,
            'manual_remarks' => $validated['remarks'] ?? null,
            'manual_recorded_by' => $registration->manual_recorded_by ?: $request->user()->id,
            'manual_recorded_at' => $registration->manual_recorded_at ?: now(),
            'manual_last_modified_by' => $request->user()->id,
            'attendance_status' => AttendanceStatus::Present,
            'score' => $percentage,
            'items_correct' => (int) $score,
            'items_total' => (int) $maximumScore,
            'grade_point' => $this->grading->percentageToGradePoint($percentage),
            'result_status' => $passed ? ResultStatus::Passed : ResultStatus::Failed,
        ])->save();

        if ($registration->schedule && empty($registration->schedule->academic_year) && $registration->schedule->exam_date) {
            $registration->schedule->academic_year = \App\Support\AcademicYear::forDate($registration->schedule->exam_date);
            $registration->schedule->saveQuietly();
        }

        $registration->applicant?->forceFill(['status' => ApplicantStatus::Examined])->save();

        ManualScoreHistory::query()->create([
            'examination_registration_id' => $registration->id,
            'recorded_by' => $request->user()->id,
            'operation' => $operation,
            'previous_score' => $previousScore,
            'new_score' => $score,
            'maximum_score' => $maximumScore,
            'reason' => $validated['reason'] ?? $validated['remarks'] ?? null,
        ]);

        $actorName = $request->user()->name;
        $studentName = $registration->applicant?->name ?: 'the scheduled student';
        $examTitle = $registration->schedule?->title ?: 'examination';
        $description = $operation === 'created'
            ? "{$actorName} recorded a manual paper-based exam score of {$score}/{$maximumScore} for {$studentName} in {$examTitle}."
            : "{$actorName} updated {$studentName}'s manual score from {$previousScore}/{$maximumScore} to {$score}/{$maximumScore} in {$examTitle}.";

        ActivityLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'manual_exam_score_'.$operation,
            'description' => $description,
            'subject_type' => ExaminationRegistration::class,
            'subject_id' => $registration->id,
        ]);

        return $this->resultData($registration->fresh(['applicant', 'schedule', 'manualRecordedBy', 'manualLastModifiedBy']));
    }

    private function resultData(ExaminationRegistration $registration): array
    {
        $score = (float) $registration->manual_score;
        $percentage = (float) $registration->score;

        return [
            'registration_id' => $registration->id,
            'applicant_id' => $registration->applicant_id,
            'student_id' => $registration->applicant?->applicant_code,
            'student_name' => $registration->applicant?->name,
            'schedule_id' => $registration->examination_schedule_id,
            'exam_title' => $registration->schedule?->title,
            'score' => $score,
            'maximum_score' => (float) $registration->manual_max_score,
            'percentage' => $percentage,
            'result_status' => $registration->result_status?->value ?? $registration->result_status,
            'examination_method' => $registration->examination_method,
            'remarks' => $registration->manual_remarks,
            'recorded_by' => $registration->manualRecordedBy?->name,
            'last_modified_by' => $registration->manualLastModifiedBy?->name,
            'recorded_at' => optional($registration->manual_recorded_at)?->toISOString(),
        ];
    }

    private function maximumScoreForSchedule(int $scheduleId): int
    {
        $questionBankId = ExamSession::query()
            ->where('examination_schedule_id', $scheduleId)
            ->orderByDesc('id')
            ->value('question_bank_id');

        if (! $questionBankId) {
            $questionBankId = QuestionBank::query()->where('is_active', true)->value('id');
        }

        $questions = ExamQuestion::query()
            ->where('status', 'active')
            ->where('is_selected_for_exam', true)
            ->when($questionBankId, fn ($query) => $query->whereHas('subject', fn ($subjects) => $subjects->where('question_bank_id', $questionBankId)));

        return max(1, (int) $questions->count());
    }
}
