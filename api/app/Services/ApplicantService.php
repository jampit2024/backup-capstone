<?php

namespace App\Services;

use App\Enums\ApplicantStatus;
use App\Enums\AttendanceStatus;
use App\Enums\ExaminationStatus;
use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplicantService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Applicant::query()
            ->with([
                'latestRegistration.schedule:id,title,exam_date,start_time,end_time,time_slot,batch_code,status',
            ])
            ->orderBy('name', 'asc');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('applicant_code', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhere('gmail', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('course_applied', 'like', "%{$search}%");
            });
        }

        if ($program = $filters['program'] ?? null) {
            if ($program !== 'all' && $program !== '') {
                $query->where('course_applied', $program);
            }
        }

        if ($status = $filters['status'] ?? null) {
            if ($status !== 'all' && $status !== '') {
                $this->applyDisplayStatusFilter($query, (string) $status);
            }
        }

        if ($date = $filters['exam_date'] ?? $filters['application_date'] ?? null) {
            if ($date !== 'all' && $date !== '') {
                $query->where(function ($q) use ($date) {
                    $q->whereDate('application_date', $date)
                        ->orWhereHas('latestRegistration.schedule', fn ($sq) => $sq->whereDate('exam_date', $date));
                });
            }
        }

        $sort = $filters['sort'] ?? 'name';
        $dir = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowed = ['id', 'name', 'course_applied', 'created_at', 'status', 'application_date'];
        if (in_array($sort, $allowed, true)) {
            $query->reorder($sort, $dir);
        }

        return $query->paginate(max(1, min(5000, $perPage)));
    }

    public function create(array $data): Applicant
    {
        return DB::transaction(function () use ($data) {
            $seq = (int) Applicant::query()->count() + 1;
            $code = $data['applicant_code'] ?? sprintf('APP-%s-%04d', now()->format('Y'), $seq);

            $applicant = Applicant::query()->create([
                'applicant_code' => $code,
                'reference_number' => $data['reference_number'] ?? sprintf('REF-%s-%04d', now()->format('Y'), $seq),
                'name' => $data['name'],
                'email' => null,
                'gmail' => ! empty($data['gmail']) ? Str::lower(trim((string) $data['gmail'])) : null,
                'gmail_submitted_at' => ! empty($data['gmail']) ? now() : null,
                'course_applied' => $data['course_applied'] ?? $data['desired_program'] ?? null,
                'preferred_exam_time' => $data['preferred_exam_time'] ?? $data['time'] ?? null,
                'application_date' => $data['application_date'] ?? now()->toDateString(),
                'status' => ApplicantStatus::Registered,
            ]);

            if (! empty($data['application_date'])) {
                $applicant->forceFill([
                    'created_at' => \Illuminate\Support\Carbon::parse($data['application_date'])->startOfDay(),
                ])->save(['timestamps' => false]);
            }

            // Link Student List → Examination Schedule (Application Date + Time).
            $this->assignToImportSchedule($applicant);

            ActivityLog::query()->create([
                'user_id' => Auth::id(),
                'action' => 'applicant_registered',
                'description' => "Applicant {$applicant->name} registered.",
                'subject_type' => Applicant::class,
                'subject_id' => $applicant->id,
            ]);

            return $applicant->fresh(['latestRegistration.schedule']);
        });
    }

    public function update(Applicant $applicant, array $data): Applicant
    {
        $applicant->fill([
            'name' => $data['name'] ?? $applicant->name,
            'course_applied' => $data['course_applied'] ?? $data['desired_program'] ?? $applicant->course_applied,
            'preferred_exam_time' => $data['preferred_exam_time'] ?? $data['time'] ?? $applicant->preferred_exam_time,
            'application_date' => $data['application_date'] ?? $applicant->application_date,
            'gmail' => array_key_exists('gmail', $data) ? $data['gmail'] : $applicant->gmail,
        ])->save();

        return $applicant->fresh(['latestRegistration.schedule']);
    }

    public function submitGmail(Applicant $applicant, string $gmail): Applicant
    {
        if ($applicant->gmail_submitted_at && $applicant->gmail) {
            abort(422, 'Gmail has already been submitted for this applicant.');
        }

        $gmail = Str::lower(trim($gmail));
        if (! preg_match('/^[a-z0-9._%+\-]+@gmail\.com$/i', $gmail)) {
            abort(422, 'Please enter a valid Gmail address.');
        }

        // Same Gmail may be shared (siblings / one household account).
        $applicant->forceFill([
            'gmail' => $gmail,
            'gmail_submitted_at' => now(),
        ])->save();

        return $applicant->fresh();
    }

    public function mapRow(Applicant $applicant): array
    {
        $registration = $applicant->latestRegistration;
        $schedule = $registration?->schedule;
        $applicationDate = $applicant->application_date ?: $applicant->created_at;
        $examDate = optional($schedule?->exam_date)?->toDateString()
            ?: ($applicationDate
                ? \Illuminate\Support\Carbon::parse($applicationDate)->toDateString()
                : null);
        $examTime = $schedule?->time_slot
            ?: trim(($this->formatTime($schedule?->start_time) ?? '').'–'.($this->formatTime($schedule?->end_time) ?? ''), '–');
        if ($examTime === '' || $examTime === null) {
            $examTime = $applicant->preferred_exam_time;
        }
        $dateLabel = $examDate
            ? \Illuminate\Support\Carbon::parse($examDate)->timezone(config('app.timezone'))->format('M j, Y')
            : null;

        return [
            'id' => $applicant->id,
            'applicant_code' => $applicant->applicant_code,
            'reference_number' => $applicant->reference_number,
            'name' => $applicant->name,
            'student_name' => $applicant->name,
            'desired_program' => $applicant->course_applied,
            'program_desire' => $applicant->course_applied,
            'course_applied' => $applicant->course_applied,
            'application_date' => $applicationDate
                ? \Illuminate\Support\Carbon::parse($applicationDate)->toDateString()
                : null,
            'application_date_label' => $applicationDate
                ? \Illuminate\Support\Carbon::parse($applicationDate)->timezone(config('app.timezone'))->format('M j, Y')
                : null,
            'examination_date' => $examDate,
            'examination_date_label' => $dateLabel,
            'date_label' => $dateLabel,
            'examination_time' => $examTime ?: null,
            'preferred_exam_time' => $applicant->preferred_exam_time,
            'status' => $this->displayStatus($applicant, $registration),
            'score' => $registration?->score !== null ? (float) $registration->score : null,
            'examination_method' => $registration?->examination_method ?: 'online',
            'manual_score' => $registration?->manual_score !== null ? (float) $registration->manual_score : null,
            'manual_max_score' => $registration?->manual_max_score !== null ? (float) $registration->manual_max_score : null,
            'display_score' => app(\App\Services\GradingService::class)->formatRegistrationScore($registration),
            'grade_point' => $registration?->grade_point !== null ? (float) $registration->grade_point : null,
            'gmail' => $applicant->gmail,
            'gmail_submitted_at' => optional($applicant->gmail_submitted_at)?->toISOString(),
            'registration_id' => $registration?->id,
            'schedule_id' => $schedule?->id,
            'batch_code' => $schedule?->batch_code,
            'passkey_status' => $registration?->passkey_status ?: 'not_sent',
        ];
    }

    /**
     * Import applicants from spreadsheet into Student List and Examination Schedule.
     *
     * Existing students (same name, case-insensitive) are updated (Gmail / program /
     * date / time + schedule). Duplicate rows within the same file are ignored.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{
     *   imported:int,
     *   updated:int,
     *   gmails_updated:int,
     *   skipped:int,
     *   duplicates:int,
     *   duplicate_applicants:list<array<string, mixed>>,
     *   errors:array<int, string>,
     *   student_list_total:int
     * }
     */
    public function importRows(array $rows): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $duplicates = 0;
        $duplicateApplicants = [];
        $errors = [];
        $seenInFile = [];
        $scheduleIdsTouched = [];
        $gmailsUpdated = 0;

        $state = $this->loadImportState();
        $scheduleCache = [];
        $passkeys = app(ExaminationPasskeyService::class);

        DB::transaction(function () use (
            $rows, &$state, &$scheduleCache, $passkeys,
            &$imported, &$updated, &$skipped, &$duplicates, &$gmailsUpdated,
            &$duplicateApplicants, &$errors, &$seenInFile, &$scheduleIdsTouched
        ) {
            $now = now();

            foreach ($rows as $index => $row) {
                $line = $index + 2; // header is line 1
                $name = trim((string) ($row['applicant_name'] ?? $row['name'] ?? ''));
                $program = trim((string) ($row['program_desire'] ?? $row['desired_program'] ?? $row['program'] ?? ''));
                $applicationDate = trim((string) ($row['application_date'] ?? ''));
                $time = trim((string) ($row['time'] ?? $row['preferred_exam_time'] ?? $row['examination_time'] ?? ''));
                $gmail = trim((string) ($row['gmail'] ?? $row['email'] ?? ''));

                if ($name === '') {
                    $skipped++;
                    $errors[] = "Row {$line}: missing Applicant Name.";
                    continue;
                }
                if ($program === '') {
                    $skipped++;
                    $errors[] = "Row {$line}: missing Program Desire.";
                    continue;
                }

                $nameKey = $this->normalizeApplicantName($name);

                if (isset($seenInFile[$nameKey])) {
                    $duplicates++;
                    $duplicateApplicants[] = [
                        'row' => $line,
                        'name' => $name,
                        'reason' => 'Duplicate row in this file (same applicant name).',
                        'existing_id' => null,
                    ];
                    continue;
                }

                $seenInFile[$nameKey] = true;

                try {
                    $isExisting = isset($state['byName'][$nameKey]);
                    $parsedDate = ($isExisting && $applicationDate === '')
                        ? null
                        : $this->parseImportDate($applicationDate);

                    $scheduleId = null;
                    $schedule = null;
                    if ($parsedDate !== null || (! $isExisting)) {
                        $scheduleDate = $parsedDate ?? $this->parseImportDate('');
                        $schedule = $this->resolveImportSchedule($scheduleDate, $time, $scheduleCache);
                        $scheduleId = $schedule->id;
                        $scheduleIdsTouched[$scheduleId] = true;
                    } elseif ($time !== '') {
                        $existingDate = $state['byName'][$nameKey]['application_date'] ?? null;
                        if ($existingDate) {
                            $schedule = $this->resolveImportSchedule(
                                \Illuminate\Support\Carbon::parse($existingDate)->toDateString(),
                                $time,
                                $scheduleCache
                            );
                            $scheduleId = $schedule->id;
                            $scheduleIdsTouched[$scheduleId] = true;
                        }
                    }

                    // Blank Time column falls back to the default first slot.
                    $storedTime = $time !== '' ? $time : ($scheduleId ? '08:00-09:00' : null);

                    if ($isExisting) {
                        $existing = $state['byName'][$nameKey];
                        $applicantId = $existing['id'];

                        $payload = ['course_applied' => $program];
                        if ($storedTime !== null) {
                            $payload['preferred_exam_time'] = $storedTime;
                        }
                        if ($parsedDate !== null) {
                            $payload['application_date'] = $parsedDate;
                        }
                        if ($gmail !== '') {
                            $normalizedGmail = Str::lower($gmail);
                            if (($existing['gmail'] ?? null) !== $normalizedGmail) {
                                $payload['gmail'] = $normalizedGmail;
                                $payload['gmail_submitted_at'] = $existing['gmail_submitted_at'] ?: $now;
                                $gmailsUpdated++;
                                $state['byName'][$nameKey]['gmail'] = $normalizedGmail;
                            }
                        }
                        if ($scheduleId && $existing['status'] === ApplicantStatus::Registered->value) {
                            $payload['status'] = ApplicantStatus::Confirmed->value;
                            $state['byName'][$nameKey]['status'] = ApplicantStatus::Confirmed->value;
                        }

                        $payload['updated_at'] = $now;
                        DB::table('applicants')->where('id', $applicantId)->update($payload);
                        $updated++;
                    } else {
                        [$code, $reference] = $this->nextApplicantCode($state);

                        $applicantId = DB::table('applicants')->insertGetId([
                            'applicant_code' => $code,
                            'reference_number' => $reference,
                            'name' => $name,
                            'email' => null,
                            'gmail' => $gmail !== '' ? Str::lower($gmail) : null,
                            'gmail_submitted_at' => $gmail !== '' ? $now : null,
                            'course_applied' => $program,
                            'preferred_exam_time' => $storedTime,
                            'application_date' => $parsedDate,
                            'status' => $scheduleId
                                ? ApplicantStatus::Confirmed->value
                                : ApplicantStatus::Registered->value,
                            // Student List sorts by import date, not upload time.
                            'created_at' => $parsedDate
                                ? \Illuminate\Support\Carbon::parse($parsedDate)->startOfDay()
                                : $now,
                            'updated_at' => $now,
                        ]);

                        $state['byName'][$nameKey] = [
                            'id' => $applicantId,
                            'gmail' => $gmail !== '' ? Str::lower($gmail) : null,
                            'gmail_submitted_at' => $gmail !== '' ? $now : null,
                            'application_date' => $parsedDate,
                            'status' => $scheduleId
                                ? ApplicantStatus::Confirmed->value
                                : ApplicantStatus::Registered->value,
                        ];
                        $imported++;
                    }

                    $pairKey = $applicantId.'|'.$scheduleId;
                    if ($scheduleId && ! isset($state['registered'][$pairKey])) {
                        $passkeyCode = $passkeys->generateAgainst($state['passkeys']);
                        ExaminationRegistration::query()->create([
                            'examination_schedule_id' => $scheduleId,
                            'applicant_id' => $applicantId,
                            'attendance_status' => AttendanceStatus::Pending->value,
                            'result_status' => ResultStatus::Pending->value,
                            'score' => null,
                            'exam_passkey_hash' => $passkeys->hashPasskey($passkeyCode),
                            'exam_passkey_encrypted' => $passkeys->encryptPasskey($passkeyCode),
                            'passkey_status' => 'not_sent',
                            'passkey_expires_at' => $schedule?->exam_date
                                ? \Illuminate\Support\Carbon::parse($schedule->exam_date->toDateString().' '.($schedule->end_time ?: '23:59:59'))->toDateTimeString()
                                : null,
                            'passkey_email_status' => ResultEmailStatus::Pending->value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $state['registered'][$pairKey] = true;
                    }
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = "Row {$line}: ".$e->getMessage();
                }
            }

            $this->refreshExpectedExaminees(array_keys($scheduleIdsTouched));
        });

        if ($imported > 0 || $updated > 0 || $duplicates > 0) {
            ActivityLog::query()->create([
                'user_id' => Auth::id(),
                'action' => 'applicants_imported',
                'description' => "Imported {$imported} new, updated {$updated} existing"
                    .($gmailsUpdated ? " ({$gmailsUpdated} Gmail updates)" : '')
                    .($duplicates ? ", {$duplicates} duplicate rows in file" : '')
                    .' from spreadsheet.',
                'subject_type' => Applicant::class,
                'subject_id' => null,
            ]);
        }

        $touchedIds = array_keys($scheduleIdsTouched);
        $touchedCounts = $touchedIds === []
            ? collect()
            : DB::table('exam_registrations')
                ->selectRaw('examination_schedule_id, count(*) as total')
                ->whereIn('examination_schedule_id', $touchedIds)
                ->groupBy('examination_schedule_id')
                ->pluck('total', 'examination_schedule_id');

        $schedulesTouched = ExaminationSchedule::query()
            ->whereIn('id', $touchedIds)
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (ExaminationSchedule $s) => [
                'id' => $s->id,
                'exam_date' => optional($s->exam_date)?->toDateString(),
                'date_label' => optional($s->exam_date)?->format('M j, Y'),
                'time_slot' => $s->time_slot,
                'batch_code' => $s->batch_code,
                'registered_count' => (int) ($touchedCounts[$s->id] ?? 0),
            ])
            ->values()
            ->all();

        $datesTouched = collect($schedulesTouched)
            ->pluck('exam_date')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'imported' => $imported,
            'updated' => $updated,
            'gmails_updated' => $gmailsUpdated,
            'skipped' => $skipped,
            'duplicates' => $duplicates,
            'duplicate_applicants' => $duplicateApplicants,
            'errors' => $errors,
            'schedules_touched' => $schedulesTouched,
            'dates_touched' => $datesTouched,
            'total_rows' => count($rows),
            'student_list_total' => Applicant::query()->count(),
        ];
    }

    private function normalizeApplicantName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_replace('/\s+/u', ' ', $name) ?? $name;
    }

    /**
     * Snapshot the lookups a bulk import needs, as plain arrays.
     *
     * Hydrating Eloquent models per row exhausts memory on large files, so the
     * import works against ids and scalars only.
     *
     * @return array{byName:array<string, array<string, mixed>>, codes:array<string, true>, passkeys:array<string, true>, registered:array<string, true>, seq:int}
     */
    private function loadImportState(): array
    {
        $byName = [];
        foreach (
            DB::table('applicants')
                ->select(['id', 'name', 'gmail', 'gmail_submitted_at', 'application_date', 'status'])
                ->cursor() as $applicant
        ) {
            $byName[$this->normalizeApplicantName((string) $applicant->name)] = [
                'id' => (int) $applicant->id,
                'gmail' => $applicant->gmail,
                'gmail_submitted_at' => $applicant->gmail_submitted_at,
                'application_date' => $applicant->application_date,
                'status' => $applicant->status,
            ];
        }

        $codes = [];
        foreach (DB::table('applicants')->pluck('applicant_code') as $code) {
            $codes[(string) $code] = true;
        }

        $passkeys = [];
        foreach (
            DB::table('exam_registrations')
                ->whereNotNull('exam_passkey_hash')
                ->pluck('exam_passkey_hash') as $passkeyHash
        ) {
            $passkeys[(string) $passkeyHash] = true;
        }

        $registered = [];
        foreach (
            DB::table('exam_registrations')
                ->select(['applicant_id', 'examination_schedule_id'])
                ->cursor() as $registration
        ) {
            $registered[$registration->applicant_id.'|'.$registration->examination_schedule_id] = true;
        }

        return [
            'byName' => $byName,
            'codes' => $codes,
            'passkeys' => $passkeys,
            'registered' => $registered,
            'seq' => count($codes),
        ];
    }

    /**
     * @param  array{codes:array<string, true>, seq:int}  $state
     * @return array{0:string, 1:string}
     */
    private function nextApplicantCode(array &$state): array
    {
        $year = now()->format('Y');

        do {
            $state['seq']++;
            $code = sprintf('APP-%s-%04d', $year, $state['seq']);
        } while (isset($state['codes'][$code]));

        $state['codes'][$code] = true;

        return [$code, sprintf('REF-%s-%04d', $year, $state['seq'])];
    }

    /**
     * Resolve (and cache) the schedule for an import row's date + time slot.
     *
     * @param  array<string, ExaminationSchedule>  $cache
     */
    private function resolveImportSchedule(string $date, string $timeRaw, array &$cache): ExaminationSchedule
    {
        $timeRaw = trim($timeRaw) !== '' ? trim($timeRaw) : '08:00-09:00';
        $slot = $this->parseTimeSlot($timeRaw);
        $key = $date.'|'.$slot['start_time'].'|'.$slot['end_time'];

        return $cache[$key] ??= $this->findOrCreateScheduleForSlot($date, $slot);
    }

    /**
     * @param  array<int, int>  $scheduleIds
     */
    private function refreshExpectedExaminees(array $scheduleIds): void
    {
        if ($scheduleIds === []) {
            return;
        }

        $counts = DB::table('exam_registrations')
            ->selectRaw('examination_schedule_id, count(*) as total')
            ->whereIn('examination_schedule_id', $scheduleIds)
            ->groupBy('examination_schedule_id')
            ->pluck('total', 'examination_schedule_id');

        $current = DB::table('exam_schedules')
            ->whereIn('id', $scheduleIds)
            ->pluck('expected_examinees', 'id');

        foreach ($counts as $scheduleId => $total) {
            $expected = max((int) $total, (int) ($current[$scheduleId] ?? 0));
            DB::table('exam_schedules')
                ->where('id', $scheduleId)
                ->update(['expected_examinees' => $expected, 'updated_at' => now()]);
        }
    }

    /**
     * Attach applicants who have import date/time but no schedule registration yet.
     * Keeps Student List and Examination Schedule in sync for earlier imports.
     */
    public function syncUnscheduledApplicants(): int
    {
        $synced = 0;

        Applicant::query()
            ->whereDoesntHave('registrations')
            ->whereNotNull('application_date')
            ->orderBy('name')
            ->each(function (Applicant $applicant) use (&$synced) {
                if ($this->assignToImportSchedule($applicant)) {
                    $synced++;
                }
            });

        return $synced;
    }

    /**
     * Find/create schedule from Application Date + Time, then register the applicant.
     */
    public function assignToImportSchedule(Applicant $applicant): bool
    {
        $date = $applicant->application_date
            ? \Illuminate\Support\Carbon::parse($applicant->application_date)->toDateString()
            : null;
        $timeRaw = trim((string) ($applicant->preferred_exam_time ?? ''));

        if (! $date) {
            return false;
        }

        // Default time slot when Excel Time column is blank.
        if ($timeRaw === '') {
            $timeRaw = '08:00-09:00';
            $applicant->forceFill(['preferred_exam_time' => $timeRaw])->save();
        }

        $slot = $this->parseTimeSlot($timeRaw);
        $schedule = $this->findOrCreateScheduleForSlot($date, $slot);

        $exists = ExaminationRegistration::query()
            ->where('applicant_id', $applicant->id)
            ->where('examination_schedule_id', $schedule->id)
            ->exists();

        if (! $exists) {
            $registration = ExaminationRegistration::query()->create([
                'examination_schedule_id' => $schedule->id,
                'applicant_id' => $applicant->id,
                'attendance_status' => AttendanceStatus::Pending,
                'result_status' => ResultStatus::Pending,
                'score' => null,
            ]);
            app(ExaminationPasskeyService::class)->ensurePasskey($registration);
        }

        $schedule->forceFill([
            'expected_examinees' => max(
                (int) $schedule->expected_examinees,
                $schedule->registrations()->count()
            ),
        ])->save();

        if ($applicant->status === ApplicantStatus::Registered) {
            $applicant->forceFill(['status' => ApplicantStatus::Confirmed])->save();
        }

        return true;
    }

    /**
     * @param  array{time_slot:string,start_time:string,end_time:string,label?:string}  $slot
     */
    private function findOrCreateScheduleForSlot(string $examDate, array $slot): ExaminationSchedule
    {
        $existing = ExaminationSchedule::query()
            ->whereDate('exam_date', $examDate)
            ->where('start_time', $slot['start_time'])
            ->where('end_time', $slot['end_time'])
            ->first();

        if ($existing) {
            // Keep display label aligned with Excel when possible.
            $label = $slot['label'] ?? $slot['time_slot'];
            if ($label && $existing->time_slot !== $label) {
                $existing->forceFill(['time_slot' => $label])->save();
            }

            return $existing->fresh();
        }

        $batchNumber = ExaminationSchedule::query()
            ->whereDate('exam_date', $examDate)
            ->count() + 1;

        return ExaminationSchedule::query()->create([
            'title' => 'Entrance Examination',
            'exam_date' => $examDate,
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'time_slot' => $slot['label'] ?? $slot['time_slot'],
            'venue' => null,
            'batch_code' => 'Batch '.$batchNumber,
            'course' => 'General Entrance Examination',
            'proctor_id' => null,
            'status' => ExaminationStatus::Scheduled,
            'expected_examinees' => 0,
        ]);
    }

    /**
     * @return array{time_slot:string,start_time:string,end_time:string}
     */
    private function parseTimeSlot(string $value): array
    {
        $original = trim($value);
        $normalized = trim(str_replace(['–', '—', ' to ', ' TO '], ['-', '-', '-', '-'], $original));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        // Excel style: "01:00 - 02:00 PM" — meridian often applies to the whole range.
        $rangeMeridian = null;
        if (preg_match('/\b(AM|PM)\s*$/i', $normalized, $m)) {
            $rangeMeridian = strtoupper($m[1]);
        }

        $parts = preg_split('/\s*-\s*/', $normalized, 2) ?: [$normalized];
        $startRaw = trim((string) ($parts[0] ?? '08:00'));
        $endRaw = trim((string) ($parts[1] ?? ''));

        $startHasMeridian = (bool) preg_match('/\b(AM|PM)\b/i', $startRaw);
        $endHasMeridian = (bool) preg_match('/\b(AM|PM)\b/i', $endRaw);

        if (! $startHasMeridian && $rangeMeridian) {
            $startHour = $this->extractClockHour($startRaw);
            $endHour = $this->extractClockHour(preg_replace('/\b(AM|PM)\b/i', '', $endRaw) ?? $endRaw);

            // e.g. "11:00 - 1:00 PM" → start is AM, end is PM
            if (
                $rangeMeridian === 'PM'
                && $startHour !== null
                && $endHour !== null
                && $startHour > $endHour
                && $startHour <= 11
            ) {
                $startRaw .= ' AM';
            } else {
                $startRaw .= ' '.$rangeMeridian;
            }
        }

        if ($endRaw !== '' && ! $endHasMeridian && $rangeMeridian) {
            $endRaw = trim((preg_replace('/\b(AM|PM)\b/i', '', $endRaw) ?? $endRaw)).' '.$rangeMeridian;
        }

        try {
            $start = \Illuminate\Support\Carbon::parse($startRaw);
        } catch (\Throwable) {
            $start = \Illuminate\Support\Carbon::parse('08:00');
        }

        try {
            $end = $endRaw !== ''
                ? \Illuminate\Support\Carbon::parse($endRaw)
                : $start->copy()->addHour();
        } catch (\Throwable) {
            $end = $start->copy()->addHour();
        }

        // If end is before/equal start after parse, assume end is next period (+12h) once.
        if ($end->lessThanOrEqualTo($start) && $endRaw !== '') {
            $end = $end->copy()->addHours(12);
        }

        $timeSlot = $start->format('H:i').'–'.$end->format('H:i');

        return [
            'time_slot' => $timeSlot,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'label' => $original !== '' ? $original : $timeSlot,
        ];
    }

    private function extractClockHour(string $value): ?int
    {
        if (preg_match('/\b(\d{1,2})\b/', trim($value), $m)) {
            $hour = (int) $m[1];

            return ($hour >= 0 && $hour <= 23) ? $hour : null;
        }

        return null;
    }

    /**
     * Rebuild schedule slots from each applicant's imported Excel time text.
     * Fixes bad parses like "01:00 - 02:00 PM" → 01:00–14:00.
     */
    public function repairImportedSchedules(): array
    {
        $moved = 0;
        $deleted = 0;

        return DB::transaction(function () use (&$moved, &$deleted) {
            $applicants = Applicant::query()
                ->with('latestRegistration')
                ->whereNotNull('preferred_exam_time')
                ->whereNotNull('application_date')
                ->orderBy('id')
                ->get();

            $keepScheduleIds = [];

            foreach ($applicants as $applicant) {
                $date = \Illuminate\Support\Carbon::parse($applicant->application_date)->toDateString();
                $slot = $this->parseTimeSlot((string) $applicant->preferred_exam_time);
                $schedule = $this->findOrCreateScheduleForSlot($date, $slot);
                $keepScheduleIds[$schedule->id] = true;

                $label = $slot['label'] ?? $slot['time_slot'];
                $schedule->forceFill([
                    'time_slot' => $label,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                ])->save();

                $registration = $applicant->latestRegistration;
                if ($registration && (int) $registration->examination_schedule_id !== (int) $schedule->id) {
                    $registration->forceFill([
                        'examination_schedule_id' => $schedule->id,
                    ])->save();
                    $moved++;
                } elseif (! $registration) {
                    $this->assignToImportSchedule($applicant);
                    $moved++;
                }
            }

            // Refresh expected counts and normalize batch codes per day.
            $dates = ExaminationSchedule::query()->select('exam_date')->distinct()->pluck('exam_date');
            foreach ($dates as $examDate) {
                $daySchedules = ExaminationSchedule::query()
                    ->whereDate('exam_date', $examDate)
                    ->orderBy('start_time')
                    ->get();

                $batch = 1;
                foreach ($daySchedules as $schedule) {
                    $count = $schedule->registrations()->count();
                    if ($count === 0 && ! isset($keepScheduleIds[$schedule->id])) {
                        $schedule->delete();
                        $deleted++;
                        continue;
                    }

                    $schedule->forceFill([
                        'batch_code' => 'Batch '.$batch,
                        'expected_examinees' => $count,
                    ])->save();
                    $batch++;
                }
            }

            // Remove empty leftover schedules from the bad parse.
            $empty = ExaminationSchedule::query()
                ->whereDoesntHave('registrations')
                ->get();
            foreach ($empty as $schedule) {
                $schedule->delete();
                $deleted++;
            }

            return ['moved' => $moved, 'deleted' => $deleted];
        });
    }

    private function parseImportDate(string $value): string
    {
        if ($value === '') {
            return now()->toDateString();
        }

        // Excel serial date (days since 1899-12-30)
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 20000 && $serial < 80000) {
                $unix = (int) round(($serial - 25569) * 86400);

                return gmdate('Y-m-d', $unix);
            }
        }

        return \Illuminate\Support\Carbon::parse($value)->toDateString();
    }

    private function displayStatus(Applicant $applicant, ?ExaminationRegistration $registration): string
    {
        if (! $registration) {
            return 'Pending';
        }

        $result = $registration->result_status?->value ?? $registration->result_status;
        $attendance = $registration->attendance_status?->value ?? $registration->attendance_status;

        if ($result === ResultStatus::Passed->value) {
            return 'Passed';
        }
        if ($result === ResultStatus::Failed->value) {
            return 'Failed';
        }
        if ($attendance === AttendanceStatus::Present->value) {
            return 'Completed';
        }

        return 'Scheduled';
    }

    /**
     * @param  Builder<Applicant>  $query
     */
    private function applyDisplayStatusFilter(Builder $query, string $status): void
    {
        $status = strtolower($status);
        match ($status) {
            'pending' => $query->whereDoesntHave('registrations'),
            'scheduled' => $query->whereHas('latestRegistration', function (Builder $q) {
                $q->where('result_status', ResultStatus::Pending)
                    ->where('attendance_status', '!=', AttendanceStatus::Present);
            }),
            'completed' => $query->whereHas('latestRegistration', function (Builder $q) {
                $q->where('attendance_status', AttendanceStatus::Present)
                    ->where('result_status', ResultStatus::Pending);
            }),
            'passed' => $query->whereHas('latestRegistration', fn (Builder $q) => $q->where('result_status', ResultStatus::Passed)),
            'failed' => $query->whereHas('latestRegistration', fn (Builder $q) => $q->where('result_status', ResultStatus::Failed)),
            default => null,
        };
    }

    private function formatTime(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($time)->format('H:i');
        } catch (\Throwable) {
            return (string) $time;
        }
    }
}
