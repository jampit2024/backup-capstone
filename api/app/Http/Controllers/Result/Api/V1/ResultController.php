<?php

namespace App\Http\Controllers\Result\Api\V1;

use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExaminationResultResource;
use App\Models\ExaminationRegistration;
use App\Models\ResultEmailBatch;
use App\Services\ExaminationResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    public function __construct(private ExaminationResultService $results)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = ExaminationRegistration::query()
            ->with(['applicant', 'schedule', 'examSession.room'])
            ->whereHas('applicant')
            ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
            ->whereNotNull('score')
            ->latest('id');

        if ($scheduleId = $request->query('schedule_id')) {
            $query->where('examination_schedule_id', $scheduleId);
        }
        if (in_array($request->query('method'), ['online', 'manual'], true)) {
            $query->where('examination_method', $request->query('method'));
        }
        if ($date = $request->query('date')) {
            $query->whereHas('schedule', fn ($q) => $q->whereDate('exam_date', $date));
        }
        if ($program = $request->query('program')) {
            $query->whereHas('applicant', fn ($q) => $q->where('course_applied', $program));
        }
        if ($status = $request->query('status')) {
            $query->where('result_status', $status);
        }
        if ($emailStatus = $request->query('email_status')) {
            $query->where('email_status', $emailStatus);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->whereHas('applicant', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('applicant_code', 'like', "%{$search}%")
                    ->orWhere('gmail', 'like', "%{$search}%");
            });
        }
        if ($request->filled('score_min')) {
            $query->where('score', '>=', (float) $request->query('score_min'));
        }
        if ($request->filled('score_max')) {
            $query->where('score', '<=', (float) $request->query('score_max'));
        }

        $items = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ExaminationResultResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'email_stats' => $this->results->emailStats(),
        ]);
    }

    public function show(ExaminationRegistration $result): JsonResponse
    {
        $result->load(['applicant', 'schedule', 'answers.question']);

        return response()->json([
            'success' => true,
            'data' => new ExaminationResultResource($result),
        ]);
    }

    public function submitAnswers(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.exam_question_id' => ['required', 'integer', 'exists:exam_questions,id'],
            'answers.*.selected_answer' => ['nullable', 'string', 'max:10'],
        ]);

        $registration = $this->results->submitAnswers($registration, $validated['answers']);

        return response()->json([
            'success' => true,
            'data' => new ExaminationResultResource($registration),
            'message' => 'Examination submitted. Please provide your Gmail to receive the result.',
            'requires_gmail' => ! $registration->applicant?->gmail,
        ]);
    }

    public function sendBatch(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'registration_ids' => ['required', 'array', 'min:1'],
            'registration_ids.*' => ['integer', 'exists:exam_registrations,id'],
            'filters' => ['sometimes', 'array'],
        ]);

        $batch = $this->results->createBatch(
            $request->user(),
            $validated['registration_ids'],
            $validated['filters'] ?? []
        );

        return response()->json([
            'success' => true,
            'data' => $batch,
            'message' => "Queued {$batch->total} result emails.",
        ]);
    }

    /**
     * Send score emails to all completed students in a schedule batch.
     * {batch} may be schedule ID or batch_code (e.g. Batch 1).
     */
    public function sendBatchEmail(Request $request, string $batch): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $emailBatch = $this->results->createBatchBySchedule($request->user(), $batch);

        return response()->json([
            'success' => true,
            'data' => $emailBatch,
            'message' => $emailBatch->total > 0
                ? "Queued {$emailBatch->total} score emails for batch {$batch}."
                : 'No eligible students to email in this batch (already sent or missing Gmail).',
        ]);
    }

    public function resendFailed(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $ids = ExaminationRegistration::query()
            ->where('email_status', ResultEmailStatus::Failed)
            ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
            ->pluck('id')
            ->all();

        $batch = $this->results->createBatch($request->user(), $ids, ['mode' => 'resend_failed']);

        return response()->json(['success' => true, 'data' => $batch]);
    }

    public function emailStatus(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->results->emailStats(),
        ]);
    }

    public function batchProgress(Request $request, ResultEmailBatch $batch): JsonResponse
    {
        $batch = $this->results->refreshBatchCounters($batch);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $batch->id,
                'status' => $batch->status,
                'total' => $batch->total,
                'sent' => $batch->sent,
                'failed' => $batch->failed,
                'queued' => $batch->queued,
                'remaining' => max(0, $batch->total - $batch->sent - $batch->failed),
                'progress' => $batch->total > 0
                    ? round((($batch->sent + $batch->failed) / $batch->total) * 100, 1)
                    : 100,
            ],
        ]);
    }
}
