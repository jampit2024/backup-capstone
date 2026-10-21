<?php

namespace App\Services;

use App\Models\ExaminationAnswer;
use App\Models\ExaminationRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExaminationAnalyticsService
{
    /** Canonical violation buckets shown in Reports & Analytics. */
    public const VIOLATION_BUCKETS = [
        'tab_switch' => [
            'label' => 'Tab switch',
            'aliases' => [
                'tab_switch',
                'tab-switch',
                'app_background',
                'app_inactive',
                'app_blur',
                'leave_attempt',
                'screen_lock',
            ],
        ],
        'screenshot' => [
            'label' => 'Screenshot attempt',
            'aliases' => ['screenshot'],
        ],
        'screen_recording' => [
            'label' => 'Screen recording',
            'aliases' => ['screen_recording', 'screen-recording'],
        ],
        'split_screen' => [
            'label' => 'Split-screen detection',
            'aliases' => ['split_screen', 'split-screen', 'multi_window', 'multi-window'],
        ],
    ];

    public function overview(): array
    {
        $scored = ExaminationRegistration::query()
            ->with(['applicant:id,name,applicant_code,course_applied', 'schedule:id,batch_code,exam_date,time_slot,title'])
            ->whereNotNull('score')
            ->get();

        $highest = $scored->sortByDesc(fn ($r) => (float) $r->score)->first();
        $lowest = $scored->sortBy(fn ($r) => (float) $r->score)->first();

        $byCourse = $this->scoreAnalyticsByCourse($scored);
        $bySubject = $this->scoreAnalyticsBySubject();
        $violations = $this->violationAnalytics();

        $trend = $this->scoreTrend($scored);
        $passRate = $scored->count()
            ? round(($scored->filter(function (ExaminationRegistration $r) {
                $status = $r->result_status?->value ?? $r->result_status;
                return $status === 'passed';
            })->count() / $scored->count()) * 100, 1)
            : 0;

        return [
            'generated_at' => now()->toISOString(),
            'examination_report' => [
                'highest_overall' => $this->mapExamineeScore($highest),
                'lowest_overall' => $this->mapExamineeScore($lowest),
                'average_score' => $scored->count()
                    ? round((float) $scored->avg('score'), 1)
                    : 0,
                'pass_rate' => $passRate,
                'examinee_count' => $scored->count(),
                'highest_by_course' => collect($byCourse)
                    ->map(fn ($row) => [
                        'course' => $row['course'],
                        'score' => $row['highest'],
                        'examinee' => $row['highest_examinee'],
                    ])
                    ->sortByDesc('score')
                    ->values()
                    ->all(),
                'lowest_by_course' => collect($byCourse)
                    ->map(fn ($row) => [
                        'course' => $row['course'],
                        'score' => $row['lowest'],
                        'examinee' => $row['lowest_examinee'],
                    ])
                    ->sortBy('score')
                    ->values()
                    ->all(),
                'by_subject' => $bySubject,
                'by_course' => $byCourse,
                'score_trend' => $trend,
            ],
            'violation_report' => $violations,
        ];
    }

    /**
     * @param  Collection<int, ExaminationRegistration>  $scored
     */
    private function scoreAnalyticsByCourse(Collection $scored): array
    {
        return $scored
            ->groupBy(fn (ExaminationRegistration $r) => trim((string) ($r->applicant?->course_applied ?: 'Undeclared')) ?: 'Undeclared')
            ->map(function (Collection $group, string $course) {
                $scores = $group->map(fn ($r) => (float) $r->score);
                $highestReg = $group->sortByDesc(fn ($r) => (float) $r->score)->first();
                $lowestReg = $group->sortBy(fn ($r) => (float) $r->score)->first();

                return [
                    'course' => $course,
                    'examinees' => $group->count(),
                    'average' => round((float) $scores->avg(), 1),
                    'highest' => round((float) $scores->max(), 1),
                    'lowest' => round((float) $scores->min(), 1),
                    'highest_examinee' => $this->mapExamineeScore($highestReg),
                    'lowest_examinee' => $this->mapExamineeScore($lowestReg),
                ];
            })
            ->sortByDesc('average')
            ->values()
            ->all();
    }

    private function scoreAnalyticsBySubject(): array
    {
        // Per-registration subject percentage from graded answers, then aggregate.
        $rows = ExaminationAnswer::query()
                ->join('exam_questions', 'exam_questions.id', '=', 'exam_answers.exam_question_id')
            ->join('exam_subjects', 'exam_subjects.id', '=', 'exam_questions.exam_subject_id')
                ->join('exam_registrations', 'exam_registrations.id', '=', 'exam_answers.examination_registration_id')
                ->whereNotNull('exam_registrations.score')
            ->groupBy(
                'exam_answers.examination_registration_id',
                'exam_subjects.id',
                'exam_subjects.name',
                'exam_subjects.code',
            )
            ->select([
                    'exam_answers.examination_registration_id',
                'exam_subjects.id as subject_id',
                'exam_subjects.name as subject_name',
                'exam_subjects.code as subject_code',
                    DB::raw('SUM(CASE WHEN exam_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_count'),
                DB::raw('COUNT(*) as total_count'),
            ])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return $rows
            ->groupBy(fn ($row) => (string) $row->subject_id)
            ->map(function (Collection $group) {
                $percentages = $group->map(function ($row) {
                    $total = max(1, (int) $row->total_count);

                    return round(((int) $row->correct_count / $total) * 100, 1);
                });

                $first = $group->first();

                return [
                    'subject' => $first->subject_name ?: ($first->subject_code ?: 'Subject'),
                    'subject_code' => $first->subject_code,
                    'examinees' => $group->count(),
                    'average' => round((float) $percentages->avg(), 1),
                    'highest' => round((float) $percentages->max(), 1),
                    'lowest' => round((float) $percentages->min(), 1),
                ];
            })
            ->sortByDesc('average')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ExaminationRegistration>  $scored
     */
    private function scoreTrend(Collection $scored): array
    {
        return $scored
            ->groupBy(fn (ExaminationRegistration $r) => optional($r->schedule?->exam_date)->toDateString() ?: 'unknown')
            ->filter(fn ($_, $date) => $date !== 'unknown')
            ->map(function (Collection $group, string $date) {
                $carbon = Carbon::parse($date);

                return [
                    'date' => $date,
                    'label' => $carbon->format('D'),
                    'full_label' => $carbon->format('M j, Y'),
                    'average' => round((float) $group->avg('score'), 1),
                    'highest' => round((float) $group->max('score'), 1),
                    'lowest' => round((float) $group->min('score'), 1),
                    'examinees' => $group->count(),
                ];
            })
            ->sortBy('date')
            ->values()
            ->all();
    }

    private function violationAnalytics(): array
    {
        return [
            'total_incidents' => 0,
            'flagged_examinees' => 0,
            'two_strike_count' => 0,
            'by_type' => [],
            'by_examinee' => [],
            'by_batch' => [],
            'by_course' => [],
        ];
    }

    private function mapExamineeScore(?ExaminationRegistration $registration): ?array
    {
        if (! $registration) {
            return null;
        }

        return [
            'registration_id' => $registration->id,
            'student_name' => $registration->applicant?->name,
            'applicant_code' => $registration->applicant?->applicant_code,
            'course' => $registration->applicant?->course_applied ?: 'Undeclared',
            'batch_code' => $registration->schedule?->batch_code,
            'exam_date' => optional($registration->schedule?->exam_date)->toDateString(),
            'score' => $registration->score !== null ? round((float) $registration->score, 1) : null,
        ];
    }
}
