<?php

namespace App\Http\Controllers\Result;

use App\Http\Controllers\Controller;

use App\Enums\AttendanceStatus;
use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Services\GradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExaminationResultController extends Controller
{
    public function __construct(private GradingService $grading)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        // Repair rows that received a score from offline sync but kept result_status=pending
        // because older ingest validation stripped result_status from the payload.
        $this->repairPendingScoredRows();

        $rows = $this->filteredRows($request);

        $grouped = $rows
            ->groupBy(fn (array $row) => (string) ($row['schedule_id'] ?: ($row['exam_date'].'|'.$row['batch_code'])))
            ->map(function ($items) {
                $first = $items->first();
                $batchCode = $first['batch_code'] ?? 'Unassigned';

                return [
                    'batch_code' => $batchCode,
                    'batch_label' => $first['batch_label'] ?? $batchCode,
                    'schedule_id' => $first['schedule_id'] ?? null,
                    'exam_date' => $first['exam_date'] ?? null,
                    'date_label' => $first['date_label'] ?? null,
                    'time_slot' => $first['time_slot'] ?? null,
                    'passed' => $items->where('outcome', 'passed')->count(),
                    'failed' => $items->where('outcome', 'failed')->count(),
                    'total' => $items->count(),
                    'sent_count' => $items->where('email_sent', true)->count(),
                    'not_sent_count' => $items->where('email_sent', false)->count(),
                    'students' => $items->values(),
                ];
            })
            ->sortBy(fn ($b) => ($b['exam_date'] ?? '').'|'.($b['time_slot'] ?? ''))
            ->values();

        $summary = [
            'total' => $rows->count(),
            'passed' => $rows->where('outcome', 'passed')->count(),
            'failed' => $rows->where('outcome', 'failed')->count(),
            'absent' => 0,
            'pending' => 0,
            'average_score' => round((float) $rows->whereNotNull('score')->avg('score'), 1),
            'email_sent' => $rows->where('email_sent', true)->count(),
            'email_not_sent' => $rows->where('email_sent', false)->count(),
        ];

        $completedScheduleIds = ExaminationRegistration::query()
            ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
            ->whereNotNull('score')
            ->distinct()
            ->pluck('examination_schedule_id');

        $courses = ExaminationRegistration::query()
            ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
            ->whereNotNull('score')
            ->whereHas('applicant', fn ($q) => $q->whereNotNull('course_applied')->where('course_applied', '!=', ''))
            ->with('applicant:id,course_applied')
            ->get()
            ->pluck('applicant.course_applied')
            ->concat(
                \App\Models\Applicant::query()
                    ->whereNotNull('course_applied')
                    ->where('course_applied', '!=', '')
                    ->distinct()
                    ->pluck('course_applied')
            )
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $filters = [
            'dates' => ExaminationSchedule::query()
                ->whereIn('id', $completedScheduleIds)
                ->select('exam_date')
                ->distinct()
                ->orderByDesc('exam_date')
                ->pluck('exam_date')
                ->map(fn ($date) => Carbon::parse($date)->toDateString())
                ->values(),
            'batches' => ExaminationSchedule::query()
                ->whereIn('id', $completedScheduleIds)
                ->select('id', 'batch_code', 'exam_date', 'time_slot', 'start_time', 'end_time')
                ->orderBy('batch_code')
                ->orderByDesc('exam_date')
                ->get()
                ->map(fn (ExaminationSchedule $schedule) => [
                    'id' => $schedule->id,
                    'batch_code' => $schedule->batch_code,
                    'exam_date' => optional($schedule->exam_date)->toDateString(),
                    'date_label' => optional($schedule->exam_date)->format('M j, Y'),
                    'time_slot' => $schedule->time_slot,
                    'label' => trim(($schedule->batch_code ?: '').' · '.optional($schedule->exam_date)->format('M j, Y').' · '.$schedule->time_slot),
                ]),
            'courses' => $courses,
        ];

        return response()->json([
            'success' => true,
            'data' => $rows,
            'batches' => $grouped,
            'meta' => [
                'summary' => $summary,
                'filters' => $filters,
            ],
        ]);
    }

    /**
     * Applicant detail for admin result lookup.
     */
    public function show(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $registration->load([
            'applicant:id,applicant_code,name,email,gmail,course_applied',
            'schedule:id,title,exam_date,start_time,end_time,time_slot,batch_code,status,proctor_id',
            'schedule.proctor:id,name,email',
            'examSession.room:id,room_name,proctor_id',
            'examSession.room.proctor:id,name,email',
            'examSession.proctor:id,name,email',
        ]);

        $row = $this->mapRow($registration);
        $row['violations'] = [];

        return response()->json([
            'success' => true,
            'data' => $row,
        ]);
    }

    /**
     * Global applicant lookup across completed results (name / applicant code).
     */
    public function lookup(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $rows = ExaminationRegistration::query()
            ->with([
                'applicant:id,applicant_code,name,email,gmail,course_applied',
                'schedule:id,title,exam_date,start_time,end_time,time_slot,batch_code,status,proctor_id',
                'schedule.proctor:id,name,email',
                'examSession.room:id,room_name,proctor_id',
                'examSession.room.proctor:id,name,email',
                'examSession.proctor:id,name,email',
            ])
            ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
            ->whereNotNull('score')
            ->whereHas('applicant', function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('applicant_code', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('gmail', 'like', "%{$q}%");
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ExaminationRegistration $reg) => $this->mapRow($reg))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Export results as CSV.
     * Query: scope=all|overall|date|batch|course
     * Optional: date=, schedule_id= or batch=, course=
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $scope = $request->query('scope', 'all');
        if ($scope === 'overall') {
            $scope = 'all';
        }
        if (! in_array($scope, ['all', 'date', 'batch', 'course'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid export scope.'], 422);
        }

        if ($scope === 'date' && ! $request->query('date')) {
            return response()->json(['success' => false, 'message' => 'Select an examination date to export.'], 422);
        }

        if ($scope === 'batch' && ! $request->query('schedule_id') && ! $request->query('batch')) {
            return response()->json(['success' => false, 'message' => 'Select a batch to export.'], 422);
        }

        if ($scope === 'course' && ! trim((string) $request->query('course', ''))) {
            return response()->json(['success' => false, 'message' => 'Select a course/program to export.'], 422);
        }

        $rows = $this->filteredRows($request);

        // For overall and course exports, ensure strict A-Z alphabetical sorting by student name
        if ($scope === 'all' || $scope === 'course' || $request->query('sort') === 'az' || ! $request->has('sort')) {
            $rows = $rows->sort(function (array $a, array $b) {
                return strcasecmp((string) ($a['student_name'] ?? ''), (string) ($b['student_name'] ?? ''));
            })->values();
        }

        $stamp = now()->format('Ymd_His');
        $courseSlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $request->query('course', 'course'));
        $filename = match ($scope) {
            'date' => 'exam_results_'.$request->query('date').'_'.$stamp.'.csv',
            'batch' => 'exam_results_batch_'.($request->query('batch') ?: $request->query('schedule_id')).'_'.$stamp.'.csv',
            'course' => 'exam_results_course_'.$courseSlug.'_'.$stamp.'.csv',
            default => 'exam_results_overall_'.$stamp.'.csv',
        };

        return response()->streamDownload(function () use ($rows, $scope) {
            $out = fopen('php://output', 'w');

            // Overall & course exports: strictly list of student, course, with score, sorted A-Z
            if ($scope === 'all' || $scope === 'course') {
                fputcsv($out, [
                    'Student Name',
                    'Course',
                    'Score',
                    'Percentage',
                    'Examination Method',
                ]);

                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['student_name'] ?? '',
                        $row['program_desire'] ?? $row['course_preference'] ?? '',
                        $row['display_score'] ?? ($row['score'] !== null ? $row['score'] : ''),
                        $row['score'] !== null ? $row['score'].'%' : '',
                        $row['examination_method'] ?? 'online',
                    ]);
                }
            } else {
                // Batch and administrative date exports retain detailed operations fields
                fputcsv($out, [
                    'Applicant Code',
                    'Student Name',
                    'Program',
                    'Exam Date',
                    'Batch',
                    'Time Slot',
                    'Room',
                    'Proctor',
                    'Score',
                    'Percentage',
                    'Examination Method',
                    'Grade Point (5.0 scale)',
                    'Status',
                    'Gmail',
                    'Email Sent',
                ]);

                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['applicant_code'] ?? '',
                        $row['student_name'] ?? '',
                        $row['program_desire'] ?? '',
                        $row['exam_date'] ?? '',
                        $row['batch_code'] ?? '',
                        $row['time_slot'] ?? '',
                        $row['room_name'] ?? '',
                        $row['proctor_name'] ?? '',
                        $row['display_score'] ?? '',
                        $row['score'] !== null ? $row['score'].'%' : '',
                        $row['examination_method'] ?? 'online',
                        $row['grade_point'] !== null ? number_format((float) $row['grade_point'], 2) : '',
                        $row['outcome_label'] ?? '',
                        $row['gmail'] ?? $row['email'] ?? '',
                        ! empty($row['email_sent']) ? 'Yes' : 'No',
                    ]);
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function repairPendingScoredRows(): void
    {
        $rows = ExaminationRegistration::query()
            ->with('applicant:id,course_applied')
            ->whereNotNull('score')
            ->where(function ($q) {
                $q->whereNull('result_status')
                    ->orWhere('result_status', ResultStatus::Pending);
            })
            ->limit(500)
            ->get();

        foreach ($rows as $reg) {
            $score = (float) $reg->score;
            $passed = $this->grading->isPassing($score, $reg->applicant?->course_applied);
            $reg->forceFill([
                'result_status' => $passed ? ResultStatus::Passed : ResultStatus::Failed,
                'grade_point' => $reg->grade_point ?? $this->grading->percentageToGradePoint($score),
            ])->save();
        }
    }

    private function filteredRows(Request $request)
    {
        $query = ExaminationRegistration::query()
            ->with([
                'applicant:id,applicant_code,name,email,gmail,course_applied',
                'schedule:id,title,exam_date,start_time,end_time,time_slot,batch_code,status,proctor_id',
                'schedule.proctor:id,name,email',
                'examSession.room:id,room_name,proctor_id',
                'examSession.room.proctor:id,name,email',
                'examSession.proctor:id,name,email',
            ])
            ->whereIn('result_status', [ResultStatus::Passed, ResultStatus::Failed])
            ->whereNotNull('score');

        if ($scheduleId = $request->query('schedule_id')) {
            $query->where('examination_schedule_id', $scheduleId);
        }

        if (in_array($request->query('method'), ['online', 'manual'], true)) {
            $query->where('examination_method', $request->query('method'));
        }

        if ($date = $request->query('date')) {
            $query->whereHas('schedule', fn ($q) => $q->whereDate('exam_date', $date));
        }

        if ($batch = $request->query('batch')) {
            $query->whereHas('schedule', fn ($q) => $q->where('batch_code', $batch));
        }

        if ($course = trim((string) $request->query('course', ''))) {
            $query->whereHas('applicant', fn ($q) => $q->where('course_applied', $course));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->whereHas('applicant', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('applicant_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('gmail', 'like', "%{$search}%");
            });
        }

        if ($outcome = $request->query('outcome')) {
            $this->applyOutcomeFilter($query, (string) $outcome);
        }

        $sort = strtolower((string) $request->query('sort', 'default'));

        $mapped = $query->get()->map(fn (ExaminationRegistration $reg) => $this->mapRow($reg));

        return $mapped
            ->sort(function (array $a, array $b) use ($sort) {
                if ($sort === 'name_asc' || $sort === 'az') {
                    return strcasecmp((string) ($a['student_name'] ?? ''), (string) ($b['student_name'] ?? ''));
                }
                if ($sort === 'name_desc' || $sort === 'za') {
                    return strcasecmp((string) ($b['student_name'] ?? ''), (string) ($a['student_name'] ?? ''));
                }

                $dateCmp = strcmp((string) ($b['exam_date'] ?? ''), (string) ($a['exam_date'] ?? ''));
                if ($dateCmp !== 0) {
                    return $dateCmp;
                }
                $timeCmp = strcmp((string) ($a['time_slot'] ?? ''), (string) ($b['time_slot'] ?? ''));
                if ($timeCmp !== 0) {
                    return $timeCmp;
                }

                return strcasecmp((string) ($a['student_name'] ?? ''), (string) ($b['student_name'] ?? ''));
            })
            ->values();
    }

    private function applyOutcomeFilter($query, string $outcome): void
    {
        match ($outcome) {
            'absent' => $query->where('attendance_status', AttendanceStatus::Absent),
            'passed' => $query->where('result_status', ResultStatus::Passed),
            'failed' => $query->where('result_status', ResultStatus::Failed),
            'pending' => $query->whereRaw('1 = 0'),
            default => null,
        };
    }

    private function mapRow(ExaminationRegistration $registration): array
    {
        $schedule = $registration->schedule;
        $applicant = $registration->applicant;
        $attendance = $registration->attendance_status?->value ?? $registration->attendance_status;
        $result = $registration->result_status?->value ?? $registration->result_status;
        $emailStatus = $registration->email_status?->value ?? $registration->email_status ?? 'pending';
        $emailSent = (bool) ($registration->email_sent
            || $emailStatus === ResultEmailStatus::Sent->value
            || $registration->email_sent_at);

        $outcome = $result ?: 'pending';
        $dateLabel = optional($schedule?->exam_date)->format('M j, Y');
        $room = $registration->examSession?->room;
        $score = $registration->score !== null ? (float) $registration->score : null;
        $gradePoint = $registration->grade_point !== null
            ? (float) $registration->grade_point
            : ($score !== null ? $this->grading->percentageToGradePoint($score) : null);

        $proctor = $registration->examSession?->proctor
            ?? $room?->proctor
            ?? $schedule?->proctor;

        $startTime = $schedule?->start_time;
        $endTime = $schedule?->end_time;
        $sessionStarted = optional($registration->examSession?->started_at)?->format('g:i A');
        $sessionEnded = optional($registration->examSession?->ended_at)?->format('g:i A');

        return [
            'id' => $registration->id,
            'schedule_id' => $registration->examination_schedule_id,
            'exam_session_id' => $registration->exam_session_id,
            'applicant_code' => $applicant?->applicant_code,
            'student_name' => $applicant?->name,
            'email' => $applicant?->gmail ?: $applicant?->email,
            'gmail' => $applicant?->gmail,
            'course_preference' => $applicant?->course_applied,
            'program_desire' => $applicant?->course_applied,
            'exam_title' => $schedule?->title ?: 'Entrance Examination',
            'exam_date' => optional($schedule?->exam_date)->toDateString(),
            'date_label' => $dateLabel,
            'batch_code' => $schedule?->batch_code,
            'batch_label' => $schedule?->batch_code ?: ($dateLabel ?: 'Unassigned'),
            'batch_number' => $schedule?->batch_code,
            'time_slot' => $schedule?->time_slot,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'taken_at_label' => trim(($dateLabel ?: '').' · '.($schedule?->time_slot ?: trim(($startTime ?: '').' - '.($endTime ?: '')))),
            'session_started_at' => $sessionStarted,
            'session_ended_at' => $sessionEnded,
            'room_id' => $room?->id,
            'room_name' => $room?->room_name,
            'proctor_id' => $proctor?->id,
            'proctor_name' => $proctor?->name,
            'proctor_email' => $proctor?->email,
            'violation_count' => 0,
            'attendance_status' => $attendance,
            'result_status' => $result,
            'examination_status' => $result,
            'email_status' => $emailStatus,
            'email_sent' => $emailSent,
            'email_sent_at' => optional($registration->email_sent_at)?->toISOString(),
            'email_attempts' => (int) ($registration->email_attempts ?? 0),
            'outcome' => $outcome,
            'outcome_label' => match ($outcome) {
                'passed' => 'Pass',
                'failed' => 'Fail',
                'absent' => 'Absent',
                default => 'Pending',
            },
            'score' => $score,
            'examination_method' => $registration->examination_method ?: 'online',
            'manual_score' => $registration->manual_score !== null ? (float) $registration->manual_score : null,
            'manual_max_score' => $registration->manual_max_score !== null ? (float) $registration->manual_max_score : null,
            'items_correct' => $registration->items_correct !== null ? (int) $registration->items_correct : null,
            'items_total' => $registration->items_total !== null ? (int) $registration->items_total : null,
            'grade_point' => $gradePoint,
            'display_score' => $registration->examination_method === 'manual'
                && $registration->manual_score !== null
                ? $this->formatManualScore((float) $registration->manual_score, (float) ($registration->manual_max_score ?: 100))
                : $this->grading->formatRegistrationScore($registration),
            'display_grade_point' => $gradePoint !== null
                ? number_format($gradePoint, 2)
                : '—',
        ];
    }

    private function formatManualScore(float $score, float $maximum): string
    {
        $format = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $format($score).'/'.$format($maximum);
    }
}
