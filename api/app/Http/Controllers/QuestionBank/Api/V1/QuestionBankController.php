<?php

namespace App\Http\Controllers\QuestionBank\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\QuestionBankResource;
use App\Models\QuestionBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionBankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $banks = QuestionBank::query()
            ->withCount(['subjects', 'questions'])
            ->orderByDesc('is_active')
            ->orderByDesc('school_year')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => QuestionBankResource::collection($banks)->response()->getData(true)['data'] ?? QuestionBankResource::collection($banks),
            'meta' => [
                'current_page' => $banks->currentPage(),
                'last_page' => $banks->lastPage(),
                'per_page' => $banks->perPage(),
                'total' => $banks->total(),
            ],
        ]);
    }

    public function show(QuestionBank $questionBank): JsonResponse
    {
        $questionBank->load(['subjects.questions' => fn ($q) => $q->orderBy('sort_order')]);
        $questionBank->loadCount(['subjects', 'questions']);

        return response()->json([
            'success' => true,
            'data' => new QuestionBankResource($questionBank),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'school_year' => ['required', 'string', 'max:20', 'unique:exam_packs,school_year'],
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $bank = QuestionBank::query()->create([
            'school_year' => $validated['school_year'],
            'title' => $validated['title'] ?? QuestionBank::titleFromSchoolYear($validated['school_year']),
            'is_active' => false,
        ]);

        if (! empty($validated['is_active'])) {
            $bank->activate();
        }

        return response()->json(['success' => true, 'data' => new QuestionBankResource($bank->fresh())], 201);
    }

    public function update(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'school_year' => ['sometimes', 'string', 'max:20', 'unique:exam_packs,school_year,'.$questionBank->id],
            'title' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $questionBank->fill(collect($validated)->except('is_active')->all())->save();
        if (array_key_exists('is_active', $validated) && $validated['is_active']) {
            $questionBank->activate();
        } elseif (array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            $questionBank->forceFill(['is_active' => false])->save();
        }

        return response()->json(['success' => true, 'data' => new QuestionBankResource($questionBank->fresh())]);
    }

    public function destroy(Request $request, QuestionBank $questionBank): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $questionBank->delete();

        return response()->json(['success' => true, 'message' => 'Question bank deleted.']);
    }
}
