<?php

namespace App\Services;

use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use App\Jobs\SendExaminationResultEmailJob;
use App\Models\ActivityLog;
use App\Models\ExaminationRegistration;
use App\Models\ResultEmailBatch;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

class ResultsDashboardService
{
    public static function academicYearSql(string $column = 'schedules.academic_year', string $dateColumn = 'schedules.exam_date'): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return "COALESCE(NULLIF({$column}, ''), CASE WHEN {$dateColumn} IS NOT NULL AND CAST(strftime('%m', {$dateColumn}) AS INTEGER) >= 8 THEN strftime('%Y', {$dateColumn}) || '-' || (CAST(strftime('%Y', {$dateColumn}) AS INTEGER) + 1) WHEN {$dateColumn} IS NOT NULL THEN (CAST(strftime('%Y', {$dateColumn}) AS INTEGER) - 1) || '-' || strftime('%Y', {$dateColumn}) ELSE '" . AcademicYear::current() . "' END)";
        }

        return "COALESCE(NULLIF({$column}, ''), CASE WHEN {$dateColumn} IS NOT NULL AND MONTH({$dateColumn}) >= 8 THEN CONCAT(YEAR({$dateColumn}), '-', YEAR({$dateColumn}) + 1) WHEN {$dateColumn} IS NOT NULL THEN CONCAT(YEAR({$dateColumn}) - 1, '-', YEAR({$dateColumn})) ELSE '" . AcademicYear::current() . "' END)";
    }

    public function years(): array
    {
        $aySql = self::academicYearSql();

        $counts = (clone $this->completedQuery())
            ->selectRaw("{$aySql} AS academic_year")
            ->selectRaw('COUNT(DISTINCT registrations.id) AS examinee_count')
            ->groupBy(DB::raw($aySql))
            ->get()
            ->keyBy('academic_year');

        $activity = (clone $this->completedQuery())
            ->selectRaw("{$aySql} AS academic_year")
            ->select('schedules.exam_date')
            ->selectRaw('COUNT(DISTINCT registrations.id) AS examinee_count')
            ->groupBy(DB::raw($aySql), 'schedules.exam_date')
            ->orderByDesc('schedules.exam_date')
            ->get()
            ->groupBy('academic_year');

        $years = $counts->map(function ($row, string $year) use ($activity): array {
            return [
                'year' => $year,
                'isCurrent' => $year === AcademicYear::current(),
                'examineeCount' => (int) $row->examinee_count,
                'activity' => ($activity->get($year) ?? collect())
                    ->take(5)
                    ->pluck('examinee_count')
                    ->map(fn ($count) => (int) $count)
                    ->values()
                    ->all(),
            ];
        })->values();

        if (! $years->contains(fn (array $row) => $row['year'] === AcademicYear::current())) {
            $years->push([
                'year' => AcademicYear::current(),
                'isCurrent' => true,
                'examineeCount' => 0,
                'activity' => [],
            ]);
        }

        return $years->sortByDesc('year')->values()->all();
    }

    public function results(string $year, ?string $date = null, ?string $method = null): array
    {
        $aySql = self::academicYearSql();
        $query = $this->selectResultRows($this->completedQuery())
            ->whereRaw("{$aySql} = ?", [$year]);
        if (in_array($method, ['online', 'manual'], true)) {
            $query->where('registrations.examination_method', $method);
        }

        $yearRows = $query
            ->orderBy('applicants.name')
            ->orderBy('registrations.id')
            ->get()
            ->map(fn ($row) => $this->mapResult($row))
            ->values();

        $dateRows = $yearRows->groupBy('examDate')
            ->map(fn ($items, $examDate) => [
                'date' => $examDate,
                'batchCount' => $items->pluck('scheduleId')->unique()->count(),
                'examineeCount' => $items->count(),
                'passed' => $items->where('status', ResultStatus::Passed->value)->count(),
                'failed' => $items->where('status', ResultStatus::Failed->value)->count(),
                'sendableCount' => $items->filter(fn (array $item) => $this->isSendable($item))->count(),
            ])
            ->sortByDesc('date')
            ->values()
            ->all();

        $filtered = $date === null
            ? $yearRows
            : $yearRows->filter(fn (array $item) => Carbon::parse($item['examDate'])->toDateString() === $date)->values();

        $batches = $filtered->groupBy('scheduleId')
            ->map(function ($items): array {
                $first = $items->first();

                return [
                    'scheduleId' => $first['scheduleId'],
                    'batch' => $first['batch'],
                    'date' => $first['examDate'],
                    'time' => $first['time'],
                    'total' => $items->count(),
                    'passed' => $items->where('status', ResultStatus::Passed->value)->count(),
                    'failed' => $items->where('status', ResultStatus::Failed->value)->count(),
                    'sent' => $items->where('sent', true)->count(),
                    'queued' => $items->where('emailStatus', ResultEmailStatus::Queued->value)->count(),
                    'sendable' => $items->filter(fn (array $item) => $this->isSendable($item))->count(),
                ];
            })
            ->sortBy(fn (array $batch) => $batch['date'].'|'.$batch['time'].'|'.$batch['batch'])
            ->values()
            ->all();

        $stats = [
            'examDays' => $filtered->pluck('examDate')->unique()->count(),
            'batches' => $filtered->pluck('scheduleId')->unique()->count(),
            'examinees' => $filtered->count(),
            'passed' => $filtered->where('status', ResultStatus::Passed->value)->count(),
            'failed' => $filtered->where('status', ResultStatus::Failed->value)->count(),
            'sendable' => $filtered->filter(fn (array $item) => $this->isSendable($item))->count(),
        ];

        return [
            'stats' => $stats,
            'dates' => $dateRows,
            'batches' => $batches,
            'applicants' => $filtered->all(),
        ];
    }

    public function search(string $query, ?string $method = null): array
    {
        $results = $this->selectResultRows($this->completedQuery())
            ->where(function (Builder $builder) use ($query): void {
                $term = '%'.$query.'%';
                $builder->where('applicants.name', 'like', $term)
                    ->orWhere('applicants.applicant_code', 'like', $term)
                    ->orWhere('applicants.course_applied', 'like', $term)
                    ->orWhere('schedules.title', 'like', $term)
                    ->orWhere('schedules.batch_code', 'like', $term);
            });

        if (in_array($method, ['online', 'manual'], true)) {
            $results->where('registrations.examination_method', $method);
        }

        return $results->orderByDesc('schedules.exam_date')
            ->orderBy('applicants.name')
            ->limit(6)
            ->get()
            ->map(fn ($row) => $this->mapResult($row))
            ->all();
    }

    public function applicant(string $applicantId, ?string $year = null, ?int $scheduleId = null): array
    {
        $query = $this->selectResultRows($this->completedQuery());
        if ($year !== null) {
            $aySql = self::academicYearSql();
            $query->whereRaw("{$aySql} = ?", [$year]);
        }
        if ($scheduleId !== null) {
            $query->where('schedules.id', $scheduleId);
        }
        $query->where(function (Builder $builder) use ($applicantId): void {
            if (ctype_digit($applicantId)) {
                $builder->where('applicants.id', (int) $applicantId)
                    ->orWhere('registrations.id', (int) $applicantId)
                    ->orWhere('applicants.applicant_code', $applicantId);
            } else {
                $builder->where('applicants.applicant_code', $applicantId);
            }
        });

        $row = $query->orderByDesc('schedules.exam_date')
            ->orderByDesc('registrations.id')
            ->first();

        if (! $row) {
            throw (new ModelNotFoundException())->setModel(ExaminationRegistration::class);
        }

        return $this->mapResult($row);
    }

    public function queueResults(User $admin, string $year, string $mode, array $filters): array
    {
        return DB::transaction(function () use ($admin, $year, $mode, $filters): array {
            $aySql = self::academicYearSql();
            $query = $this->sendableQuery()->whereRaw("{$aySql} = ?", [$year]);

            if ($mode === 'batch') {
                $query->whereIn('schedules.id', $filters['batches']);
            } elseif ($mode === 'date') {
                $query->whereDate('schedules.exam_date', $filters['date']);
            } elseif (! empty($filters['date'])) {
                $query->whereDate('schedules.exam_date', $filters['date']);
            }

            $ids = $query->orderBy('registrations.id')
                ->lockForUpdate()
                ->pluck('registrations.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            return $this->queueIds($admin, $ids, [
                'year' => $year,
                'mode' => $mode,
                'filters' => $filters,
            ]);
        });
    }

    public function queueApplicant(User $admin, string $applicantId, ?string $year = null, ?int $scheduleId = null): array
    {
        return DB::transaction(function () use ($admin, $applicantId, $year, $scheduleId): array {
            $query = $this->selectResultRows($this->completedQuery());
            if ($year !== null) {
                $aySql = self::academicYearSql();
                $query->whereRaw("{$aySql} = ?", [$year]);
            }
            if ($scheduleId !== null) {
                $query->where('schedules.id', $scheduleId);
            }
            $query->where(function (Builder $builder) use ($applicantId): void {
                if (ctype_digit($applicantId)) {
                    $builder->where('applicants.id', (int) $applicantId)
                        ->orWhere('registrations.id', (int) $applicantId)
                        ->orWhere('applicants.applicant_code', $applicantId);
                } else {
                    $builder->where('applicants.applicant_code', $applicantId);
                }
            });

            $result = $query->orderByDesc('schedules.exam_date')
                ->orderByDesc('registrations.id')
                ->lockForUpdate()
                ->first();

            if (! $result) {
                throw (new ModelNotFoundException())->setModel(ExaminationRegistration::class);
            }
            if (empty($result->notification_email)) {
                throw new RuntimeException('Applicant has no email address on file.');
            }
            if (($result->email_status ?? null) === ResultEmailStatus::Queued->value) {
                return ['count' => 0, 'batchId' => null];
            }

            return $this->queueIds($admin, [(int) $result->registration_id], [
                'year' => $result->academic_year,
                'mode' => 'applicant',
                'applicant_id' => $applicantId,
            ]);
        });
    }

    public function exportQuery(string $year, ?string $date = null, ?string $method = null): Builder
    {
        $aySql = self::academicYearSql();
        $query = $this->selectResultRows($this->completedQuery())
            ->whereRaw("{$aySql} = ?", [$year]);
        if ($date !== null) {
            $query->whereDate('schedules.exam_date', $date);
        }
        if (in_array($method, ['online', 'manual'], true)) {
            $query->where('registrations.examination_method', $method);
        }

        return $query->orderBy('applicants.name')->orderBy('registrations.id');
    }

    public function mapForExport(object $row): array
    {
        return $this->mapResult($row);
    }

    private function completedQuery(): Builder
    {
        return DB::table('exam_registrations as registrations')
            ->join('applicants', 'applicants.id', '=', 'registrations.applicant_id')
            ->join('exam_schedules as schedules', 'schedules.id', '=', 'registrations.examination_schedule_id')
            ->leftJoin('exam_sessions as sessions', 'sessions.id', '=', 'registrations.exam_session_id')
            ->leftJoin('exam_rooms as rooms', 'rooms.id', '=', 'sessions.examination_room_id')
            ->whereNotNull('registrations.score')
            ->whereIn('registrations.result_status', [ResultStatus::Passed->value, ResultStatus::Failed->value]);
    }

    private function selectResultRows(Builder $query): Builder
    {
        $aySql = self::academicYearSql();

        return $query->addSelect([
            'registrations.id as registration_id',
            'registrations.applicant_id',
            'registrations.examination_schedule_id as schedule_id',
            'registrations.score',
            'registrations.items_correct',
            'registrations.items_total',
            'registrations.examination_method',
            'registrations.manual_score',
            'registrations.manual_max_score',
            'registrations.manual_remarks',
            'registrations.manual_recorded_at',
            'registrations.result_status',
            'registrations.email_sent',
            'registrations.email_status',
            'registrations.email_sent_at',
            'schedules.title as exam_title',
            'schedules.course as schedule_course',
            'schedules.venue as schedule_venue',
            'schedules.exam_date',
            'schedules.batch_code',
            'schedules.time_slot',
            'schedules.start_time',
            'schedules.end_time',
            'rooms.room_name',
            'applicants.applicant_code',
            'applicants.name as applicant_name',
            'applicants.course_applied as program',
            'applicants.gmail',
            'applicants.email',
        ])
        ->selectRaw("{$aySql} AS academic_year")
        ->selectRaw("COALESCE(NULLIF(applicants.gmail, ''), NULLIF(applicants.email, '')) AS notification_email");
    }

    private function sendableQuery(): Builder
    {
        return $this->completedQuery()
            ->whereNull('registrations.email_sent_at')
            ->where(function (Builder $query): void {
                $query->whereNull('registrations.email_sent')
                    ->orWhere('registrations.email_sent', false);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('registrations.email_status')
                    ->orWhereIn('registrations.email_status', [
                        ResultEmailStatus::Pending->value,
                        ResultEmailStatus::Failed->value,
                    ]);
            })
            ->where(function (Builder $query): void {
                $query->where(function (Builder $email): void {
                    $email->whereNotNull('applicants.gmail')->where('applicants.gmail', '!=', '');
                })->orWhere(function (Builder $email): void {
                    $email->whereNotNull('applicants.email')->where('applicants.email', '!=', '');
                });
            });
    }

    private function isSendable(array $result): bool
    {
        return ! empty($result['email'])
            && ! $result['sent']
            && in_array($result['emailStatus'], [
                ResultEmailStatus::Pending->value,
                ResultEmailStatus::Failed->value,
            ], true);
    }

    private function queueIds(User $admin, array $ids, array $filters): array
    {
        if ($ids === []) {
            return ['count' => 0, 'batchId' => null];
        }

        $batch = ResultEmailBatch::query()->create([
            'requested_by' => $admin->id,
            'total' => count($ids),
            'queued' => count($ids),
            'sent' => 0,
            'failed' => 0,
            'status' => 'queued',
            'filters' => $filters,
            'started_at' => now(),
        ]);

        ExaminationRegistration::query()->whereIn('id', $ids)->update([
            'email_status' => ResultEmailStatus::Queued->value,
            'result_email_batch_id' => $batch->id,
            'email_error_message' => null,
        ]);

        ActivityLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'batch_result_sent',
            'description' => 'Queued result emails for '.count($ids).' applicants.',
            'subject_type' => ResultEmailBatch::class,
            'subject_id' => $batch->id,
        ]);

        $batchId = (int) $batch->id;
        DB::afterCommit(function () use ($ids, $batchId): void {
            foreach ($ids as $id) {
                SendExaminationResultEmailJob::dispatch($id, $batchId);
            }
        });

        return ['count' => count($ids), 'batchId' => $batchId];
    }

    private function mapResult(object $row): array
    {
        $emailStatus = $row->email_status ?: ResultEmailStatus::Pending->value;
        $sent = $row->email_sent_at !== null
            || (bool) $row->email_sent
            || $emailStatus === ResultEmailStatus::Sent->value;
        $formatNumber = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        $score = (float) $row->score;
        $manual = ($row->examination_method ?: 'online') === 'manual';
        $maxScore = $row->manual_max_score !== null ? (float) $row->manual_max_score : ($row->items_total !== null ? (float) $row->items_total : 100.0);
        $displayScore = $manual && $row->manual_score !== null
            ? $formatNumber((float) $row->manual_score).'/'.$formatNumber($maxScore)
            : ($row->items_correct !== null && $row->items_total !== null
                ? ((int) $row->items_correct).'/'.((int) $row->items_total)
                : $formatNumber($score).'/100');

        $program = $row->program ?: ($row->schedule_course ?: 'General Examination');
        $examTitle = $row->exam_title ?: 'Entrance Examination';
        $room = $row->room_name ?: ($row->schedule_venue ?: ($manual ? 'Paper-Based Examination Venue' : 'Assigned Examination Room'));

        return [
            'id' => (int) $row->applicant_id,
            'registrationId' => (int) $row->registration_id,
            'applicantCode' => $row->applicant_code,
            'name' => $row->applicant_name,
            'program' => $program,
            'course' => $program,
            'examTitle' => $examTitle,
            'academicYear' => $row->academic_year,
            'batch' => $row->batch_code ?: 'Unassigned',
            'scheduleId' => (int) $row->schedule_id,
            'examDate' => Carbon::parse((string) $row->exam_date)->toDateString(),
            'time' => $row->time_slot ?: trim(($row->start_time ?: '').' - '.($row->end_time ?: '')),
            'room' => $room,
            'score' => $score,
            'displayScore' => $displayScore,
            'percentage' => $formatNumber($score).'%',
            'itemsCorrect' => $row->items_correct !== null ? (int) $row->items_correct : null,
            'itemsTotal' => $row->items_total !== null ? (int) $row->items_total : null,
            'totalPossibleScore' => $maxScore,
            'rawScore' => $manual ? ($row->manual_score !== null ? (float) $row->manual_score : null) : ($row->items_correct !== null ? (int) $row->items_correct : null),
            'examinationMethod' => $row->examination_method ?: 'online',
            'manualScore' => $row->manual_score !== null ? (float) $row->manual_score : null,
            'manualMaximumScore' => $row->manual_max_score !== null ? (float) $row->manual_max_score : null,
            'manualRemarks' => $row->manual_remarks,
            'manualRecordedAt' => $row->manual_recorded_at,
            'status' => $row->result_status,
            'email' => $row->notification_email,
            'sent' => $sent,
            'emailStatus' => $emailStatus,
            'sentAt' => $row->email_sent_at,
        ];
    }
}
