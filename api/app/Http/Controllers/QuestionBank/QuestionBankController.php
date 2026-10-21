<?php

namespace App\Http\Controllers\QuestionBank;

use App\Http\Controllers\Controller;

use App\Models\ExamQuestion;
use App\Models\ExamSubject;
use App\Models\QuestionBank;
use App\Services\QuestionImportParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class QuestionBankController extends Controller
{
    public function __construct(
        private QuestionImportParser $importParser,
    ) {
    }

    public function indexBanks(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $banks = QuestionBank::query()
            ->withCount([
                'subjects',
                'subjects as ready_subjects_count' => fn ($q) => $q->has('questions', '>=', 100),
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
            ])
            ->orderByDesc('is_active')
            ->orderByDesc('school_year')
            ->get()
            ->map(fn (QuestionBank $bank) => $this->mapBank($bank));

        return response()->json(['success' => true, 'data' => $banks]);
    }

    public function storeBank(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'school_year' => ['required', 'string', 'max:20', 'unique:exam_packs,school_year'],
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        $schoolYear = trim($validated['school_year']);
        $bank = QuestionBank::query()->create([
            'school_year' => $schoolYear,
            'title' => trim($validated['title'] ?? '') ?: QuestionBank::titleFromSchoolYear($schoolYear),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Question bank created.',
            'data' => $this->mapBank($bank->loadCount([
                'subjects',
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
            ])),
        ], 201);
    }

    public function showBank(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $questionBank->loadCount([
            'subjects',
            'questions',
            'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
        ]);

        $subjects = $questionBank->subjects()
            ->withCount([
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
                'questions as easy_questions_count' => fn ($q) => $q->where('difficulty', 'easy'),
                'questions as medium_questions_count' => fn ($q) => $q->where('difficulty', 'medium'),
                'questions as hard_questions_count' => fn ($q) => $q->where('difficulty', 'hard'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ExamSubject $subject) => $this->mapSubject($subject));

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->mapBank($questionBank),
                'subjects' => $subjects,
            ],
        ]);
    }

    public function updateBank(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'school_year' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('exam_packs', 'school_year')->ignore($questionBank->id),
            ],
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        if (isset($validated['school_year'])) {
            $questionBank->school_year = trim($validated['school_year']);
            if (! array_key_exists('title', $validated) || ! trim((string) ($validated['title'] ?? ''))) {
                $questionBank->title = QuestionBank::titleFromSchoolYear($questionBank->school_year);
            }
        }

        if (array_key_exists('title', $validated) && trim((string) $validated['title']) !== '') {
            $questionBank->title = trim($validated['title']);
        }

        $questionBank->save();

        return response()->json([
            'success' => true,
            'message' => 'Question bank updated.',
            'data' => $this->mapBank($questionBank->loadCount([
                'subjects',
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
            ])),
        ]);
    }

    public function duplicateBank(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'school_year' => ['required', 'string', 'max:20', 'unique:exam_packs,school_year'],
        ]);

        $copy = DB::transaction(function () use ($request, $questionBank, $validated): QuestionBank {
            $schoolYear = trim($validated['school_year']);
            $copy = QuestionBank::query()->create([
                'school_year' => $schoolYear,
                'title' => QuestionBank::titleFromSchoolYear($schoolYear),
                'is_active' => false,
            ]);

            foreach ($questionBank->subjects()->orderBy('sort_order')->get() as $subject) {
                $copiedSubject = $copy->subjects()->create([
                    'name' => $subject->name,
                    'code' => $subject->code,
                    'description' => $subject->description,
                    'sort_order' => $subject->sort_order,
                    'is_active' => $subject->is_active,
                    'selection_limit' => $subject->selection_limit,
                ]);

                $subject->questions()->with('choices')->orderBy('id')->chunkById(100, function ($questions) use ($copiedSubject, $request): void {
                    foreach ($questions as $question) {
                        $copiedQuestion = $copiedSubject->questions()->create([
                            'created_by' => $request->user()->id,
                            'stem' => $question->stem,
                            'question_type' => $question->question_type,
                            'difficulty' => $question->difficulty,
                            'status' => 'draft',
                            'is_selected_for_exam' => false,
                            'selected_at' => null,
                            'sort_order' => $question->sort_order,
                        ]);
                        $copiedQuestion->syncChoices($question->options, $question->correct_answer);
                    }
                });
            }

            return $copy;
        });

        return response()->json([
            'success' => true,
            'message' => 'Question bank duplicated as a draft.',
            'data' => $this->mapBank($copy->loadCount(['subjects', 'questions'])),
        ], 201);
    }

    public function importQuestions(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'file_type' => ['required', 'string', Rule::in(QuestionImportParser::FILE_TYPES)],
            'exam_subject_id' => [
                'required',
                'integer',
                Rule::exists('exam_subjects', 'id')->where(fn ($q) => $q->where('question_bank_id', $questionBank->id)),
            ],
            'replace_existing' => ['sometimes'],
        ]);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $subject = ExamSubject::query()
            ->where('question_bank_id', $questionBank->id)
            ->whereKey($validated['exam_subject_id'])
            ->firstOrFail();

        $replace = filter_var($request->input('replace_existing', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $parseReport = $this->importParser->parseWithReport($validated['file'], $validated['file_type']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $parsed = $parseReport['questions'];
        if ($parsed === []) {
            return response()->json([
                'success' => false,
                'message' => 'No valid questions were found in the uploaded file.',
                'errors' => $parseReport['errors'],
            ], 422);
        }

        $imported = 0;

        DB::transaction(function () use ($request, $subject, $parsed, $replace, &$imported) {
            if ($replace) {
                $subject->questions()->delete();
            }

            $sortOrder = (int) $subject->questions()->max('sort_order');

            foreach (collect($parsed)->chunk(100) as $batch) {
                foreach ($batch as $item) {
                    $options = array_values(array_map('trim', $item['options']));
                    $labels = range('A', chr(64 + count($options)));
                    $correct = strtoupper(trim((string) ($item['correct_answer'] ?? 'A')));
                    if (! in_array($correct, $labels, true)) {
                        $correct = $labels[0] ?? 'A';
                    }

                    $sortOrder++;
                    $question = $subject->questions()->create([
                        'created_by' => $request->user()->id,
                        'stem' => $item['stem'],
                        'question_type' => 'multiple_choice',
                        'difficulty' => $item['difficulty'] ?? 'medium',
                        'status' => 'active',
                        'is_selected_for_exam' => false,
                        'selected_at' => null,
                        'sort_order' => $sortOrder,
                    ]);
                    $question->syncChoices(collect($options)->map(fn ($text, $index) => [
                        'key' => $labels[$index],
                        'text' => $text,
                    ])->all(), $correct);
                    $imported++;
                }
            }
        });

        $questionBank->loadCount([
            'subjects',
            'questions',
            'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
        ]);

        $subjects = $questionBank->subjects()
            ->withCount([
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ExamSubject $s) => $this->mapSubject($s));

        $summary = [[
            'subject' => $subject->name,
            'imported' => $imported,
            'skipped' => count($parseReport['errors']),
            'total_rows' => $imported + count($parseReport['errors']),
            'file_type' => $validated['file_type'],
            'replaced' => $replace,
            'errors' => $parseReport['errors'],
        ]];

        return response()->json([
            'success' => true,
            'message' => $replace
                ? "Replaced category questions and imported {$imported} question(s) into {$subject->name}; ".count($parseReport['errors'])." row(s) skipped."
                : "Imported {$imported} question(s) into {$subject->name}; ".count($parseReport['errors'])." row(s) skipped.",
            'data' => [
                ...$this->mapBank($questionBank),
                'subjects' => $subjects,
                'import_summary' => $summary,
            ],
        ]);
    }

    public function activateBank(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $questionBank->activate();

        return response()->json([
            'success' => true,
            'message' => "{$questionBank->title} is now the active question bank.",
            'data' => $this->mapBank($questionBank->fresh()->loadCount([
                'subjects',
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
            ])),
        ]);
    }

    public function destroyBank(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $deleted = DB::transaction(function () use ($questionBank): bool {
            $lockedBank = QuestionBank::query()->whereKey($questionBank->id)->lockForUpdate()->firstOrFail();
            if ($lockedBank->is_active) {
                return false;
            }
            $lockedBank->delete();

            return true;
        }, 3);

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'The active question bank cannot be deleted. Set another bank active first.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Question bank deleted.']);
    }

    public function storeSubject(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable',
                'string',
                'max:40',
                Rule::unique('exam_subjects', 'code')->where(fn ($q) => $q->where('question_bank_id', $questionBank->id)),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'selection_limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $subject = $questionBank->subjects()->create([
            'name' => trim($validated['name']),
            'code' => isset($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'description' => $validated['description'] ?? null,
            'sort_order' => (int) $questionBank->subjects()->max('sort_order') + 1,
            'is_active' => true,
            'selection_limit' => (int) ($validated['selection_limit'] ?? 5),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subject created.',
            'data' => $this->mapSubject($subject->loadCount([
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
            ])),
        ], 201);
    }

    public function showSubject(Request $request, ExamSubject $examSubject): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $examSubject->load(['questionBank']);
        $examSubject->loadCount([
            'questions',
            'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
        ]);

        $questions = $examSubject->questions()
            ->orderByDesc('is_selected_for_exam')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ExamQuestion $question) => $this->mapQuestion($question));

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->mapSubject($examSubject),
                'question_bank' => $examSubject->questionBank
                    ? $this->mapBank($examSubject->questionBank)
                    : null,
                'questions' => $questions,
            ],
        ]);
    }

    public function subjectQuestions(Request $request, ExamSubject $examSubject): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'difficulty' => ['nullable', 'string', 'in:easy,medium,hard'],
            'exam_filter' => ['nullable', 'string', 'in:all,in_exam,not_in_exam'],
            'sort' => ['nullable', 'string', 'in:id,stem,correct_answer,difficulty,is_selected_for_exam'],
            'dir' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $examSubject->questions()->with('choices');
        if (! empty($validated['difficulty'])) {
            $query->where('difficulty', $validated['difficulty']);
        }
        if (($validated['exam_filter'] ?? 'all') === 'in_exam') {
            $query->where('is_selected_for_exam', true);
        } elseif (($validated['exam_filter'] ?? 'all') === 'not_in_exam') {
            $query->where('is_selected_for_exam', false);
        }
        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($matches) use ($search): void {
                $matches->where('stem', 'like', "%{$search}%")
                    ->orWhere('difficulty', 'like', "%{$search}%")
                    ->orWhereHas('choices', fn ($choice) => $choice->where('choice_text', 'like', "%{$search}%"));
            });
        }

        $sort = $validated['sort'] ?? 'id';
        $direction = $validated['dir'] ?? 'asc';
        if ($sort === 'correct_answer') {
            $query->orderByRaw(
                '(select choice_key from exam_question_choices where exam_question_choices.exam_question_id = exam_questions.id and exam_question_choices.is_correct = 1 limit 1) '.$direction
            );
        } else {
            $query->orderBy($sort, $direction);
        }
        if ($sort !== 'id') {
            $query->orderBy('id');
        }

        $items = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data' => collect($items->items())->map(fn (ExamQuestion $question) => $this->mapQuestion($question))->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function exportSubjectQuestions(Request $request, ExamSubject $examSubject)
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        return response()->streamDownload(function () use ($examSubject): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['question', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'level', 'in_exam']);

            $examSubject->questions()
                ->with('choices')
                ->orderBy('id')
                ->chunkById(500, function ($questions) use ($output): void {
                    foreach ($questions as $question) {
                        $options = [];
                        foreach ($question->options as $option) {
                            $options[$option['key']] = $option['text'];
                        }
                        fputcsv($output, [
                            $question->stem,
                            $options['A'] ?? '',
                            $options['B'] ?? '',
                            $options['C'] ?? '',
                            $options['D'] ?? '',
                            $question->correct_answer,
                            $question->difficulty,
                            $question->is_selected_for_exam ? 'yes' : 'no',
                        ]);
                    }
                });

            fclose($output);
        }, 'question-bank-category-'.$examSubject->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function fillExamSlots(Request $request, ExamSubject $examSubject): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $affected = DB::transaction(function () use ($examSubject): int {
            $subject = ExamSubject::query()->whereKey($examSubject->id)->lockForUpdate()->firstOrFail();
            $selectedCount = $subject->questions()->where('is_selected_for_exam', true)->count();
            $availableSlots = max(0, (int) $subject->selection_limit - $selectedCount);
            if ($availableSlots === 0) {
                return 0;
            }

            $ids = $subject->questions()
                ->where('is_selected_for_exam', false)
                ->whereIn('status', ['active', 'draft'])
                ->inRandomOrder()
                ->limit($availableSlots)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            $subject->questions()->whereIn('id', $ids)->where('status', 'draft')->update(['status' => 'active']);

            return $subject->questions()->whereIn('id', $ids)->update([
                'is_selected_for_exam' => true,
                'selected_at' => now(),
            ]);
        }, 3);

        return response()->json([
            'success' => true,
            'message' => "{$affected} question(s) added to the exam.",
            'data' => ['affected' => $affected],
        ]);
    }

    public function updateSubject(Request $request, ExamSubject $examSubject): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => [
                'nullable',
                'string',
                'max:40',
                Rule::unique('exam_subjects', 'code')
                    ->where(fn ($q) => $q->where('question_bank_id', $examSubject->question_bank_id))
                    ->ignore($examSubject->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'selection_limit' => ['sometimes', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($examSubject, $validated): void {
            $lockedSubject = ExamSubject::query()->whereKey($examSubject->id)->lockForUpdate()->firstOrFail();
            if (isset($validated['name'])) {
                $lockedSubject->name = trim($validated['name']);
            }
            if (array_key_exists('code', $validated)) {
                $lockedSubject->code = $validated['code'] !== null ? strtoupper(trim($validated['code'])) : null;
            }
            if (array_key_exists('description', $validated)) {
                $lockedSubject->description = $validated['description'];
            }
            if (array_key_exists('is_active', $validated)) {
                $lockedSubject->is_active = (bool) $validated['is_active'];
            }
            if (array_key_exists('selection_limit', $validated)) {
                $lockedSubject->selection_limit = (int) $validated['selection_limit'];
            }
            $lockedSubject->save();

            if (array_key_exists('selection_limit', $validated)) {
                $this->trimSelectedQuestionsToLimit($lockedSubject);
            }
        });

        $examSubject->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Subject updated.',
            'data' => $this->mapSubject($examSubject->loadCount([
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
            ])),
        ]);
    }

    public function destroySubject(Request $request, ExamSubject $examSubject): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $examSubject->delete();

        return response()->json(['success' => true, 'message' => 'Subject deleted.']);
    }

    public function storeQuestion(Request $request, ExamSubject $examSubject): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'stem' => ['required', 'string', 'min:5', 'max:5000'],
            'question_type' => ['nullable', Rule::in(['multiple_choice', 'true_false'])],
            'options' => ['required', 'array', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'max:500'],
            'correct_answer' => ['required', 'string', 'max:10'],
            'difficulty' => ['nullable', Rule::in(['easy', 'medium', 'hard'])],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'is_selected_for_exam' => ['sometimes', 'boolean'],
        ]);

        $options = array_values(array_map('trim', $validated['options']));
        $correct = strtoupper(trim($validated['correct_answer']));
        $labels = range('A', chr(64 + count($options)));

        if (! in_array($correct, $labels, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Correct answer must match one of the option letters (A, B, C...).',
                'errors' => ['correct_answer' => ['Correct answer must be a valid option letter.']],
            ], 422);
        }

        $selected = (bool) ($validated['is_selected_for_exam'] ?? false);

        $question = DB::transaction(function () use ($examSubject, $request, $validated, $selected, $options, $labels, $correct) {
            if ($selected) {
                $this->makeRoomForSelection($examSubject);
            }
            $question = $examSubject->questions()->create([
                'created_by' => $request->user()->id,
                'stem' => trim($validated['stem']),
                'question_type' => $validated['question_type'] ?? 'multiple_choice',
                'difficulty' => $validated['difficulty'] ?? 'medium',
                'status' => $validated['status'] ?? 'draft',
                'is_selected_for_exam' => $selected,
                'selected_at' => $selected ? now() : null,
                'sort_order' => (int) $examSubject->questions()->max('sort_order') + 1,
            ]);
            $question->syncChoices(collect($options)->map(fn ($text, $index) => [
                'key' => $labels[$index],
                'text' => $text,
            ])->all(), $correct);

            return $question->load('choices');
        });

        return response()->json([
            'success' => true,
            'message' => 'Question added.',
            'data' => $this->mapQuestion($question),
        ], 201);
    }

    public function updateQuestion(Request $request, ExamQuestion $examQuestion): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'stem' => ['sometimes', 'required', 'string', 'min:5', 'max:5000'],
            'options' => ['sometimes', 'required', 'array', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'max:500'],
            'correct_answer' => ['sometimes', 'required', 'string', 'max:10'],
            'difficulty' => ['sometimes', Rule::in(['easy', 'medium', 'hard'])],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'is_selected_for_exam' => ['sometimes', 'boolean'],
        ]);

        $payload = [];

        if (isset($validated['stem'])) {
            $payload['stem'] = trim($validated['stem']);
        }
        if (isset($validated['difficulty'])) {
            $payload['difficulty'] = $validated['difficulty'];
        }
        if (isset($validated['status'])) {
            $payload['status'] = $validated['status'];
        }
        if (array_key_exists('is_selected_for_exam', $validated)) {
            $wantSelected = (bool) $validated['is_selected_for_exam'];
            $payload['is_selected_for_exam'] = $wantSelected;
            $payload['selected_at'] = $wantSelected ? now() : null;
        }

        $optionsChanged = isset($validated['options']);
        $correctAnswerChanged = isset($validated['correct_answer']);
        $options = null;
        $choices = [];
        $correct = null;
        if ($optionsChanged) {
            $options = array_values(array_map('trim', $validated['options']));
            $labels = range('A', chr(64 + count($options)));
            $choices = collect($options)->map(fn ($text, $index) => [
                'key' => $labels[$index],
                'text' => $text,
            ])->all();

            $correct = strtoupper(trim($validated['correct_answer'] ?? $examQuestion->correct_answer));
            if (! in_array($correct, $labels, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Correct answer must match one of the option letters.',
                ], 422);
            }
        } elseif ($correctAnswerChanged) {
            $correct = strtoupper(trim($validated['correct_answer']));
            $choices = $examQuestion->options;
        }

        DB::transaction(function () use ($examQuestion, $payload, $optionsChanged, $correctAnswerChanged, $choices, $correct): void {
            if (($payload['is_selected_for_exam'] ?? false) && ! $examQuestion->is_selected_for_exam) {
                $subject = ExamSubject::query()->whereKey($examQuestion->exam_subject_id)->lockForUpdate()->firstOrFail();
                $this->makeRoomForSelection($subject, $examQuestion);
            }
            $examQuestion->update($payload);
            if ($optionsChanged || $correctAnswerChanged) {
                $examQuestion->syncChoices($choices, $correct);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Question updated.',
            'data' => $this->mapQuestion($examQuestion->fresh()),
        ]);
    }

    public function toggleQuestionSelection(Request $request, ExamQuestion $examQuestion): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $next = ! $examQuestion->is_selected_for_exam;
        DB::transaction(function () use ($examQuestion, $next): void {
            $subject = ExamSubject::query()->whereKey($examQuestion->exam_subject_id)->lockForUpdate()->firstOrFail();
            if ($next) {
                $this->makeRoomForSelection($subject, $examQuestion);
            }
            $examQuestion->update([
                'is_selected_for_exam' => $next,
                'selected_at' => $next ? now() : null,
                'status' => $next && $examQuestion->status === 'draft' ? 'active' : $examQuestion->status,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => $next ? 'Question selected for exam.' : 'Question removed from exam selection.',
            'data' => $this->mapQuestion($examQuestion->fresh()),
        ]);
    }

    public function destroyQuestion(Request $request, ExamQuestion $examQuestion): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $examQuestion->delete();

        return response()->json(['success' => true, 'message' => 'Question deleted.']);
    }

    /**
     * Paginated question review table across a bank (or all banks).
     */
    public function reviewQuestions(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'question_bank_id' => ['nullable', 'integer', 'exists:exam_packs,id'],
            'exam_subject_id' => ['nullable', 'integer', 'exists:exam_subjects,id'],
            'status' => ['nullable', 'string', 'in:draft,active,archived'],
            'selected_only' => ['nullable', 'boolean'],
            'active_only' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'in:id,stem,status,difficulty,correct_answer,category'],
            'dir' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $query = ExamQuestion::query()
            ->with(['subject:id,name,code,question_bank_id,is_active'])
            ->whereHas('subject', function ($q) use ($validated) {
                if (! empty($validated['question_bank_id'])) {
                    $q->where('question_bank_id', $validated['question_bank_id']);
                }
            });

        if (! empty($validated['exam_subject_id'])) {
            $query->where('exam_subject_id', $validated['exam_subject_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if ($request->boolean('selected_only')) {
            $query->where('is_selected_for_exam', true);
        }
        if ($request->boolean('active_only')) {
            $query->where('status', 'active');
        }
        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('stem', 'like', "%{$search}%")
                    ->orWhereHas('choices', fn ($choice) => $choice->where('is_correct', true)->where('choice_key', 'like', "%{$search}%"))
                    ->orWhere('difficulty', 'like', "%{$search}%")
                    ->orWhereHas('subject', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            });
        }

        $sort = $validated['sort'] ?? 'id';
        $dir = $validated['dir'] ?? 'desc';
        if ($sort === 'category') {
            $query->leftJoin('exam_subjects', 'exam_questions.exam_subject_id', '=', 'exam_subjects.id')
                ->orderBy('exam_subjects.name', $dir)
                ->select('exam_questions.*');
        } elseif ($sort === 'correct_answer') {
            $query->leftJoin('exam_question_choices as correct_choice', function ($join) {
                $join->on('correct_choice.exam_question_id', '=', 'exam_questions.id')
                    ->where('correct_choice.is_correct', true);
            })->orderBy('correct_choice.choice_key', $dir)->select('exam_questions.*');
        } else {
            $query->orderBy($sort, $dir);
        }

        $page = $query->paginate($validated['per_page'] ?? 25);

        $categories = ExamSubject::query()
            ->when(! empty($validated['question_bank_id']), fn ($q) => $q->where('question_bank_id', $validated['question_bank_id']))
            ->withCount('questions')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ExamSubject $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'is_active' => (bool) $s->is_active,
                'questions_count' => (int) $s->questions_count,
                'question_bank_id' => $s->question_bank_id,
            ]);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (ExamQuestion $q) => $this->mapQuestionReview($q))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'categories' => $categories,
        ]);
    }

    public function examPreview(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $settings = \App\Models\ExaminationSetting::current();

        $subjects = $questionBank->subjects()
            ->withCount([
                'questions',
                'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $questions = ExamQuestion::query()
            ->with(['subject:id,name,code,sort_order', 'choices'])
            ->where('is_selected_for_exam', true)
            ->whereHas('subject', fn ($q) => $q->where('question_bank_id', $questionBank->id))
            ->orderBy('exam_subject_id')
            ->orderBy('sort_order')
            ->get();

        $questionsBySubject = $questions->groupBy('exam_subject_id');
        $categories = $subjects->map(function (ExamSubject $subject) use ($questionsBySubject): array {
            $selectedCount = (int) $subject->selected_questions_count;
            $limit = (int) $subject->selection_limit;
            $questionCount = (int) $subject->questions_count;

            return [
                'id' => $subject->id,
                'category' => $subject->name,
                'code' => $subject->code,
                'questions_count' => $questionCount,
                'selected_count' => $selectedCount,
                'selection_limit' => $limit,
                'unfilled_slots' => max(0, $limit - $selectedCount),
                'questions' => ($questionsBySubject->get($subject->id) ?? collect())
                    ->map(fn (ExamQuestion $question) => $this->mapQuestionReview($question))
                    ->values(),
            ];
        })->values();

        $issues = $categories->flatMap(function (array $category): array {
            $categoryIssues = [];
            if ($category['questions_count'] < 100) {
                $categoryIssues[] = [
                    'type' => 'category_below_minimum',
                    'category_id' => $category['id'],
                    'category' => $category['category'],
                    'questions_short' => 100 - $category['questions_count'],
                ];
            }
            if ($category['unfilled_slots'] > 0) {
                $categoryIssues[] = [
                    'type' => 'exam_slots_unfilled',
                    'category_id' => $category['id'],
                    'category' => $category['category'],
                    'unfilled_slots' => $category['unfilled_slots'],
                ];
            }

            return $categoryIssues;
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'bank' => $this->mapBank($questionBank->loadCount([
                    'subjects',
                    'questions',
                    'questions as selected_questions_count' => fn ($q) => $q->where('is_selected_for_exam', true),
                ])),
                'total_questions_stored' => (int) $questionBank->questions()->count(),
                'total_used' => $questions->count(),
                'total_selected' => $questions->count(),
                'needs_attention_categories' => $categories->where('questions_count', '<', 100)->count(),
                'issues' => $issues,
                'category_breakdown' => $categories->map(fn ($row) => [
                    'category' => $row['category'],
                    'count' => $row['selected_count'],
                ])->values(),
                'categories' => $categories,
                'randomization' => [
                    'shuffle_questions' => (bool) $settings->shuffle_questions,
                    'shuffle_categories' => (bool) $settings->shuffle_categories,
                    'shuffle_both' => (bool) $settings->shuffle_both,
                ],
            ],
        ]);
    }

    public function bulkQuestions(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'all_matching' => ['sometimes', 'boolean'],
            'ids' => ['required_unless:all_matching,true', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:exam_questions,id'],
            'action' => ['required', 'string', 'in:activate,deactivate,delete,move_category,export,select_for_exam,deselect_from_exam'],
            'exam_subject_id' => ['required_if:action,move_category', 'nullable', 'integer', 'exists:exam_subjects,id'],
            'filters' => ['sometimes', 'array'],
            'filters.search' => ['nullable', 'string', 'max:200'],
            'filters.difficulty' => ['nullable', 'string', 'in:easy,medium,hard'],
            'filters.exam_filter' => ['nullable', 'string', 'in:all,in_exam,not_in_exam'],
        ]);

        $allMatching = (bool) ($validated['all_matching'] ?? false);
        if ($allMatching && empty($validated['exam_subject_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'exam_subject_id' => ['A category is required when applying an action to all matching questions.'],
            ]);
        }

        $action = $validated['action'];
        $affected = 0;
        $targets = ExamQuestion::query();
        if ($allMatching) {
            $filters = $validated['filters'] ?? [];
            $targets->where('exam_subject_id', $validated['exam_subject_id']);
            if (! empty($filters['difficulty'])) {
                $targets->where('difficulty', $filters['difficulty']);
            }
            if (($filters['exam_filter'] ?? 'all') === 'in_exam') {
                $targets->where('is_selected_for_exam', true);
            } elseif (($filters['exam_filter'] ?? 'all') === 'not_in_exam') {
                $targets->where('is_selected_for_exam', false);
            }
            if ($search = trim((string) ($filters['search'] ?? ''))) {
                $targets->where(function ($matches) use ($search): void {
                    $matches->where('stem', 'like', "%{$search}%")
                        ->orWhereHas('choices', fn ($choice) => $choice->where('choice_text', 'like', "%{$search}%"));
                });
            }
        } else {
            $targets->whereIn('id', $validated['ids']);
        }

        if ($action === 'export') {
            $rows = (clone $targets)
                ->with('subject:id,name,code')
                ->with('choices')
                ->get()
                ->map(fn (ExamQuestion $q) => $this->mapQuestionReview($q));

            return response()->json([
                'success' => true,
                'message' => 'Export ready.',
                'data' => $rows,
            ]);
        }

        if ($action === 'delete') {
            $affected = $targets->delete();
        } elseif ($action === 'select_for_exam') {
            DB::transaction(function () use ($targets, $allMatching, $validated, &$affected): void {
                if ($allMatching) {
                    $subject = ExamSubject::query()->whereKey($validated['exam_subject_id'])->lockForUpdate()->firstOrFail();
                    $selectedCount = $subject->questions()->where('is_selected_for_exam', true)->count();
                    $available = max(0, (int) $subject->selection_limit - $selectedCount);
                    $pending = (clone $targets)->where('is_selected_for_exam', false)->count();
                    if ($pending > $available) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'filters' => ["{$subject->name} has {$available} exam slot(s) available for {$pending} matching questions."],
                        ]);
                    }
                    (clone $targets)->where('status', 'draft')->update(['status' => 'active']);
                    $affected = (clone $targets)->where('is_selected_for_exam', false)->update([
                        'is_selected_for_exam' => true,
                        'selected_at' => now(),
                    ]);

                    return;
                }

                $questions = (clone $targets)->orderBy('id')->get();
                foreach ($questions->groupBy('exam_subject_id') as $subjectId => $subjectQuestions) {
                    $subject = ExamSubject::query()->whereKey($subjectId)->lockForUpdate()->firstOrFail();
                    $pending = $subjectQuestions->reject(fn (ExamQuestion $question) => $question->is_selected_for_exam);
                    $selectedCount = $subject->questions()->where('is_selected_for_exam', true)->count();
                    $available = max(0, (int) $subject->selection_limit - $selectedCount);
                    if ($pending->count() > $available) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'ids' => ["{$subject->name} has {$available} selection slot(s) available."],
                        ]);
                    }
                    foreach ($pending as $question) {
                        $question->update([
                            'is_selected_for_exam' => true,
                            'selected_at' => now(),
                            'status' => $question->status === 'draft' ? 'active' : $question->status,
                        ]);
                        $affected++;
                    }
                }
            }, 3);
        } elseif ($action === 'deselect_from_exam') {
            $affected = $targets->where('is_selected_for_exam', true)
                ->update(['is_selected_for_exam' => false, 'selected_at' => null]);
        } elseif ($action === 'activate') {
            $affected = $targets->update(['status' => 'active']);
        } elseif ($action === 'deactivate') {
            $affected = $targets->update([
                'status' => 'archived',
                'is_selected_for_exam' => false,
                'selected_at' => null,
            ]);
        } elseif ($action === 'move_category') {
            $affected = $targets->update([
                'exam_subject_id' => $validated['exam_subject_id'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => match ($action) {
                'select_for_exam' => "{$affected} question(s) selected for the exam.",
                'deselect_from_exam' => "{$affected} question(s) removed from the exam selection.",
                default => "Bulk {$action} completed.",
            },
            'data' => ['affected' => $affected],
        ]);
    }

    private function mapBank(QuestionBank $bank): array
    {
        return [
            'id' => $bank->id,
            'school_year' => $bank->school_year,
            'title' => $bank->title,
            'is_active' => (bool) $bank->is_active,
            'subjects_count' => (int) ($bank->subjects_count ?? $bank->subjects()->count()),
            'ready_subjects_count' => (int) ($bank->ready_subjects_count ?? $bank->subjects()->has('questions', '>=', 100)->count()),
            'questions_count' => (int) ($bank->questions_count ?? $bank->questions()->count()),
            'selected_questions_count' => (int) ($bank->selected_questions_count ?? $bank->questions()->where('is_selected_for_exam', true)->count()),
            'updated_at' => optional($bank->updated_at)?->toIso8601String(),
            'created_at' => optional($bank->created_at)?->toIso8601String(),
        ];
    }

    private function mapSubject(ExamSubject $subject): array
    {
        return [
            'id' => $subject->id,
            'question_bank_id' => $subject->question_bank_id,
            'name' => $subject->name,
            'code' => $subject->code,
            'description' => $subject->description,
            'is_active' => (bool) $subject->is_active,
            'selection_limit' => (int) ($subject->selection_limit ?? 5),
            'questions_count' => (int) ($subject->questions_count ?? $subject->questions()->count()),
            'selected_questions_count' => (int) ($subject->selected_questions_count ?? $subject->questions()->where('is_selected_for_exam', true)->count()),
            'easy_questions_count' => (int) ($subject->easy_questions_count ?? $subject->questions()->where('difficulty', 'easy')->count()),
            'medium_questions_count' => (int) ($subject->medium_questions_count ?? $subject->questions()->where('difficulty', 'medium')->count()),
            'hard_questions_count' => (int) ($subject->hard_questions_count ?? $subject->questions()->where('difficulty', 'hard')->count()),
        ];
    }

    private function makeRoomForSelection(ExamSubject $subject, ?ExamQuestion $incoming = null): void
    {
        $subject = ExamSubject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
        $limit = (int) ($subject->selection_limit ?? 5);

        if ($limit === 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'is_selected_for_exam' => ['This category has a selection limit of zero.'],
            ]);
        }

        $selected = $subject->questions()
            ->where('is_selected_for_exam', true)
            ->when($incoming, fn ($query) => $query->where('exam_questions.id', '!=', $incoming->id));
        $removeCount = max(0, $selected->count() - $limit + 1);
        if ($removeCount > 0) {
            $idsToRemove = (clone $selected)
                ->orderBy('selected_at')
                ->orderBy('id')
                ->limit($removeCount)
                ->pluck('id');
            $subject->questions()->whereIn('id', $idsToRemove)->update([
                'is_selected_for_exam' => false,
                'selected_at' => null,
            ]);
        }
    }

    private function trimSelectedQuestionsToLimit(ExamSubject $subject): void
    {
        $limit = (int) ($subject->selection_limit ?? 5);
        $keep = $subject->questions()
            ->where('is_selected_for_exam', true)
            ->orderBy('selected_at')
            ->orderBy('id')
            ->limit($limit)
            ->select('id');

        $subject->questions()
            ->where('is_selected_for_exam', true)
            ->whereNotIn('id', $keep)
            ->update([
                'is_selected_for_exam' => false,
                'selected_at' => null,
            ]);
    }

    private function mapQuestion(ExamQuestion $question): array
    {
        return [
            'id' => $question->id,
            'exam_subject_id' => $question->exam_subject_id,
            'stem' => $question->stem,
            'question_type' => $question->question_type,
            'options' => $question->options ?? [],
            'correct_answer' => $question->correct_answer,
            'difficulty' => $question->difficulty,
            'status' => $question->status,
            'is_selected_for_exam' => (bool) $question->is_selected_for_exam,
            'selected_at' => optional($question->selected_at)?->toIso8601String(),
        ];
    }


    private function mapQuestionReview(ExamQuestion $question): array
    {
        $options = is_array($question->options) ? $question->options : [];
        $byKey = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $letter = strtoupper((string) ($value['key'] ?? ''));
                $text = (string) ($value['text'] ?? $value['label'] ?? '');
                if (isset($byKey[$letter])) {
                    $byKey[$letter] = $text;
                }
            } elseif (is_string($key) && isset($byKey[strtoupper($key)])) {
                $byKey[strtoupper($key)] = (string) $value;
            }
        }

        return [
            'id' => $question->id,
            'exam_subject_id' => $question->exam_subject_id,
            'category' => $question->subject?->name ?? '—',
            'category_code' => $question->subject?->code ?? '',
            'question_bank_id' => $question->subject?->question_bank_id,
            'stem' => $question->stem,
            'option_a' => $byKey['A'],
            'option_b' => $byKey['B'],
            'option_c' => $byKey['C'],
            'option_d' => $byKey['D'],
            'options' => $options,
            'correct_answer' => $question->correct_answer,
            'difficulty' => $question->difficulty,
            'status' => $question->status,
            'is_selected_for_exam' => (bool) $question->is_selected_for_exam,
        ];
    }
}
