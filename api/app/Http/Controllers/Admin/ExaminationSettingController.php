<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Models\ExaminationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExaminationSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $settings = ExaminationSetting::current();

        return response()->json([
            'success' => true,
            'data' => $this->map($settings),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'allow_late_entry' => ['required', 'boolean'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'shuffle_categories' => ['sometimes', 'boolean'],
            'shuffle_both' => ['sometimes', 'boolean'],
            'disconnect_grace_seconds' => ['sometimes', 'integer', 'min:10', 'max:1800'],
            'room_student_limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'default_start_at' => ['nullable', 'date'],
            'default_end_at' => ['nullable', 'date', 'after_or_equal:default_start_at'],
        ]);

        $settings = ExaminationSetting::current();
        $settings->forceFill($validated)->save();

        return response()->json([
            'success' => true,
            'message' => 'Examination settings saved.',
            'data' => $this->map($settings->fresh()),
        ]);
    }

    private function map(ExaminationSetting $settings): array
    {
        return [
            'id' => $settings->id,
            'duration_minutes' => (int) ($settings->duration_minutes ?: 60),
            'allow_late_entry' => (bool) $settings->allow_late_entry,
            'shuffle_questions' => (bool) $settings->shuffle_questions,
            'shuffle_categories' => (bool) $settings->shuffle_categories,
            'shuffle_both' => (bool) $settings->shuffle_both,
            'disconnect_grace_seconds' => (int) ($settings->disconnect_grace_seconds ?? 120),
            'room_student_limit' => (int) ($settings->room_student_limit ?? 60),
            'default_start_at' => optional($settings->default_start_at)?->toISOString(),
            'default_end_at' => optional($settings->default_end_at)?->toISOString(),
            'updated_at' => optional($settings->updated_at)?->toISOString(),
        ];
    }
}
