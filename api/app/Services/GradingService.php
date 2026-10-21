<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\CourseGradingSetting;

/**
 * Percentage scores (0–100) plus the Philippine-style 5-point grade scale
 * where 1.00 is highest and 5.00 is failing.
 */
class GradingService
{
    public const DEFAULT_PASSING_PERCENTAGE = 75.0;

    public function settingForCourse(?string $course): CourseGradingSetting
    {
        $code = $this->normalizeCourse($course);

        $existing = CourseGradingSetting::query()
            ->where('course_code', $code)
            ->where('is_active', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        return new CourseGradingSetting([
            'course_code' => $code,
            'course_name' => $course ?: 'General',
            'passing_percentage' => self::DEFAULT_PASSING_PERCENTAGE,
            'failing_grade_point' => 5.0,
            'is_active' => true,
        ]);
    }

    public function settingForApplicant(?Applicant $applicant): CourseGradingSetting
    {
        return $this->settingForCourse($applicant?->course_applied);
    }

    public function isPassing(float $percentage, ?string $course = null): bool
    {
        $setting = $this->settingForCourse($course);

        return $percentage >= (float) $setting->passing_percentage;
    }

    /**
     * Convert a 0–100 percentage into a 5-point grade (1.00 best → 5.00 fail).
     */
    public function percentageToGradePoint(float $percentage): float
    {
        $score = max(0, min(100, $percentage));

        return match (true) {
            $score >= 97 => 1.00,
            $score >= 94 => 1.25,
            $score >= 91 => 1.50,
            $score >= 88 => 1.75,
            $score >= 85 => 2.00,
            $score >= 82 => 2.25,
            $score >= 79 => 2.50,
            $score >= 76 => 2.75,
            $score >= 75 => 3.00,
            $score >= 70 => 3.50,
            $score >= 65 => 4.00,
            default => 5.00,
        };
    }

    public function formatScoreOutOf100(?float $percentage): string
    {
        return $this->formatScore($percentage, null, null);
    }

    /**
     * Display raw item score (e.g. 45/50). Falls back to percentage/100 when item counts are missing.
     */
    public function formatScore(?float $percentage, ?int $correct = null, ?int $total = null): string
    {
        if ($correct !== null && $total !== null && $total > 0) {
            return $correct.'/'.$total;
        }

        if ($percentage === null) {
            return '—';
        }

        // Legacy rows: convert percentage back to items if total is known later, else show percent/100.
        $display = rtrim(rtrim(number_format((float) $percentage, 2, '.', ''), '0'), '.');

        return $display.'/100';
    }

    public function formatRegistrationScore(?\App\Models\ExaminationRegistration $registration): string
    {
        if (! $registration || $registration->score === null) {
            return '—';
        }

        return $this->formatScore(
            (float) $registration->score,
            $registration->items_correct !== null ? (int) $registration->items_correct : null,
            $registration->items_total !== null ? (int) $registration->items_total : null,
        );
    }

    public function normalizeCourse(?string $course): string
    {
        $trimmed = trim((string) $course);

        return $trimmed !== '' ? mb_strtoupper($trimmed) : 'GENERAL';
    }

    /**
     * Ensure a grading row exists for every distinct applicant course.
     *
     * @return list<CourseGradingSetting>
     */
    public function syncFromApplicants(): array
    {
        $courses = Applicant::query()
            ->whereNotNull('course_applied')
            ->where('course_applied', '!=', '')
            ->distinct()
            ->orderBy('course_applied')
            ->pluck('course_applied');

        $settings = [];
        foreach ($courses as $course) {
            $code = $this->normalizeCourse($course);
            $settings[] = CourseGradingSetting::query()->updateOrCreate(
                ['course_code' => $code],
                [
                    'course_name' => trim((string) $course),
                    'passing_percentage' => CourseGradingSetting::query()
                        ->where('course_code', $code)
                        ->value('passing_percentage') ?? self::DEFAULT_PASSING_PERCENTAGE,
                    'failing_grade_point' => 5.0,
                    'is_active' => true,
                ]
            );
        }

        // Always keep a General fallback.
        $settings[] = CourseGradingSetting::query()->firstOrCreate(
            ['course_code' => 'GENERAL'],
            [
                'course_name' => 'General / Unspecified',
                'passing_percentage' => self::DEFAULT_PASSING_PERCENTAGE,
                'failing_grade_point' => 5.0,
                'is_active' => true,
            ]
        );

        return $settings;
    }
}
