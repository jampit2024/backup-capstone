<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Models\CourseGradingSetting;
use App\Services\GradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseGradingSettingController extends Controller
{
    public function __construct(private GradingService $grading)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        if ($request->boolean('sync')) {
            $this->grading->syncFromApplicants();
        }

        $items = CourseGradingSetting::query()
            ->orderBy('course_name')
            ->get()
            ->map(fn (CourseGradingSetting $row) => $this->map($row));

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'scale' => '5-point (1.00 highest → 5.00 failing)',
                'default_passing_percentage' => GradingService::DEFAULT_PASSING_PERCENTAGE,
                'score_display' => 'percentage out of 100 (e.g. 67/100)',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'course_code' => ['nullable', 'string', 'max:100'],
            'course_name' => ['required', 'string', 'max:150'],
            'passing_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'failing_grade_point' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $code = $this->grading->normalizeCourse(
            $validated['course_code'] ?? $validated['course_name']
        );

        $row = CourseGradingSetting::query()->updateOrCreate(
            ['course_code' => $code],
            [
                'course_name' => trim($validated['course_name']),
                'passing_percentage' => $validated['passing_percentage'],
                'failing_grade_point' => $validated['failing_grade_point'] ?? 5.0,
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Course grading setting saved.',
            'data' => $this->map($row),
        ], 201);
    }

    public function update(Request $request, CourseGradingSetting $courseGradingSetting): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'course_name' => ['sometimes', 'required', 'string', 'max:150'],
            'course_code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('course_grading_settings', 'course_code')->ignore($courseGradingSetting->id),
            ],
            'passing_percentage' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'failing_grade_point' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['course_code'])) {
            $validated['course_code'] = $this->grading->normalizeCourse($validated['course_code']);
        }

        $courseGradingSetting->forceFill($validated)->save();

        return response()->json([
            'success' => true,
            'message' => 'Course grading setting updated.',
            'data' => $this->map($courseGradingSetting->fresh()),
        ]);
    }

    public function destroy(Request $request, CourseGradingSetting $courseGradingSetting): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        if ($courseGradingSetting->course_code === 'GENERAL') {
            return response()->json([
                'success' => false,
                'message' => 'The General grading setting cannot be deleted.',
            ], 422);
        }

        $courseGradingSetting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Course grading setting deleted.',
        ]);
    }

    private function map(CourseGradingSetting $row): array
    {
        return [
            'id' => $row->id,
            'course_code' => $row->course_code,
            'course_name' => $row->course_name,
            'passing_percentage' => (float) $row->passing_percentage,
            'failing_grade_point' => (float) $row->failing_grade_point,
            'is_active' => (bool) $row->is_active,
        ];
    }
}
