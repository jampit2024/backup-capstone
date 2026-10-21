<?php

namespace App\Http\Controllers\QuestionBank\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\QuestionResource;
use App\Models\ExamQuestion;
use App\Models\ExamSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ExamQuestion::query()->with(['subject:id,name,code,question_bank_id', 'choices'])->orderBy('sort_order');

        if ($bankId = $request->query('question_bank_id')) {
            $query->whereHas('subject', fn ($q) => $q->where('question_bank_id', $bankId));
        }
        if ($subjectId = $request->query('exam_subject_id')) {
            $query->where('exam_subject_id', $subjectId);
        }
        if ($request->boolean('selected_only')) {
            $query->where('is_selected_for_exam', true);
        }

        // Mobile examinees: hide unpublished/draft unless admin
        if (! $request->user()?->isAdmin()) {
            $query->where('status', 'active')->where('is_selected_for_exam', true);
        }

        $items = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => QuestionResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function show(Request $request, ExamQuestion $examQuestion): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new QuestionResource($examQuestion->load('subject')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'exam_subject_id' => ['required', 'exists:exam_subjects,id'],
            'stem' => ['required', 'string'],
            'options' => ['required', 'array', 'min:2'],
            'correct_answer' => ['required', 'string', 'max:10'],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:20'],
            'is_selected_for_exam' => ['sometimes', 'boolean'],
        ]);

        $subject = ExamSubject::query()->findOrFail($validated['exam_subject_id']);
        $question = DB::transaction(function () use ($validated, $request, $subject) {
            $question = ExamQuestion::query()->create([
                ...collect($validated)->except(['options', 'correct_answer'])->all(),
                'question_type' => 'multiple_choice',
                'created_by' => $request->user()->id,
                'sort_order' => ((int) $subject->questions()->max('sort_order')) + 1,
                'selected_at' => ! empty($validated['is_selected_for_exam']) ? now() : null,
            ]);
            $question->syncChoices($validated['options'], $validated['correct_answer']);

            return $question->load('choices');
        });

        return response()->json(['success' => true, 'data' => new QuestionResource($question)], 201);
    }

    public function update(Request $request, ExamQuestion $examQuestion): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'stem' => ['sometimes', 'string'],
            'options' => ['sometimes', 'array', 'min:2'],
            'correct_answer' => ['sometimes', 'string', 'max:10'],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:20'],
            'is_selected_for_exam' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($validated, $examQuestion): void {
            $options = $validated['options'] ?? null;
            $correctAnswer = $validated['correct_answer'] ?? null;
            unset($validated['options'], $validated['correct_answer']);

            if (array_key_exists('is_selected_for_exam', $validated)) {
                $validated['selected_at'] = $validated['is_selected_for_exam'] ? now() : null;
            }

            $examQuestion->fill($validated)->save();
            if ($options !== null || $correctAnswer !== null) {
                $examQuestion->syncChoices(
                    $options ?? $examQuestion->options,
                    $correctAnswer ?? $examQuestion->correct_answer,
                );
            }
        });

        return response()->json(['success' => true, 'data' => new QuestionResource($examQuestion->fresh('choices'))]);
    }

    public function destroy(Request $request, ExamQuestion $examQuestion): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $examQuestion->delete();

        return response()->json(['success' => true, 'message' => 'Question deleted.']);
    }
}
