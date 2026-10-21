<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\CourseGradingSetting;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationRoom;
use App\Models\ExaminationSchedule;
use App\Models\ExaminationSetting;
use App\Models\ExamQuestion;
use App\Models\ExamSubject;
use App\Models\QuestionBank;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds / applies the Option B "exam day pack":
 * central admin exports → LAN host imports before exam day.
 * IDs are preserved so later result sync matches schedule_id + applicant_code.
 */
class ExamDayPackService
{
    /**
     * @param  bool  $includeAuthSecrets  When true, includes bcrypt password hashes for offline proctor login on trusted devices.
     * @return array<string, mixed>
     */
    public function export(
        ?string $examDate = null,
        bool $includeAuthSecrets = false,
        ?int $scheduleId = null,
    ): array
    {
        $scheduleQuery = ExaminationSchedule::query()
            ->inCurrentCalendarYear()
            ->inCurrentAcademicYear()
            ->with('rooms')
            ->orderBy('id')
            ->when($scheduleId, fn ($query) => $query->whereKey($scheduleId));
        if ($examDate) {
            $scheduleQuery->whereDate('exam_date', $examDate);
        }

        $schedules = $scheduleQuery->get();
        $scheduleIds = $schedules->pluck('id');

        $proctorRoleId = Role::query()->where('slug', 'proctor')->value('id');
        $proctors = User::query()
            ->withTrashed()
            ->when($proctorRoleId, fn ($q) => $q->where('role_id', $proctorRoleId))
            ->get(['id', 'role_id', 'name', 'email', 'password', 'status', 'phone', 'address']);

        $registrations = ExaminationRegistration::query()
            ->whereIn('examination_schedule_id', $scheduleIds)
            ->get([
                'id',
                'examination_schedule_id',
                'applicant_id',
                'assigned_room_id',
                'attendance_status',
                'result_status',
                'exam_passkey_hash',
                'passkey_status',
                'passkey_sent_to_email',
                'passkey_used_at',
                'passkey_expires_at',
                'previous_schedule_id',
                'reschedule_reason',
                'rescheduled_at',
            ]);

        $applicantIds = $registrations->pluck('applicant_id')->unique()->filter();
        $applicants = Applicant::query()
            ->whereIn('id', $applicantIds)
            ->get();

        // Only the admin-activated question bank(s) ship to proctor devices.
        // Inactive banks (and their selected questions) must not be downloadable.
        $banks = QuestionBank::query()
            ->where('is_active', true)
            ->with([
                'subjects' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'subjects.questions' => fn ($q) => $q
                    ->where('is_selected_for_exam', true)
                    ->where(function ($inner) {
                        $inner->whereNull('status')->orWhere('status', 'active');
                    })
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        return [
            'pack_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'exam_date_filter' => $examDate,
            'schedules' => $schedules->map(fn (ExaminationSchedule $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'exam_date' => optional($s->exam_date)?->toDateString(),
                'academic_year' => $s->academic_year,
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'time_slot' => $s->time_slot,
                'venue' => $s->venue,
                'batch_code' => $s->batch_code,
                'course' => $s->course,
                'proctor_id' => $s->proctor_id,
                'status' => $s->status?->value ?? $s->status,
                'expected_examinees' => $s->expected_examinees,
                'rooms_managed' => $s->rooms_managed,
                'rooms_revision' => $s->rooms_revision,
                'rooms_published_at' => $s->rooms_published_at,
                'rooms' => $s->rooms->map(fn (ExaminationRoom $r) => [
                    'id' => $r->id,
                    'examination_schedule_id' => $r->examination_schedule_id,
                    'room_name' => $r->room_name,
                    'capacity' => $r->capacity,
                    'proctor_id' => $r->proctor_id,
                ])->values()->all(),
            ])->values()->all(),
            'applicants' => $applicants->map(fn (Applicant $a) => [
                'id' => $a->id,
                'applicant_code' => $a->applicant_code,
                'reference_number' => $a->reference_number,
                'name' => $a->name,
                'email' => $a->email,
                'gmail' => $a->gmail,
                'course_applied' => $a->course_applied,
                'preferred_exam_time' => $a->preferred_exam_time,
                'application_date' => optional($a->application_date)?->toDateString(),
                'status' => $a->status?->value ?? $a->status,
            ])->values()->all(),
            'registrations' => $registrations->map(fn (ExaminationRegistration $r) => [
                'id' => $r->id,
                'examination_schedule_id' => $r->examination_schedule_id,
                'applicant_id' => $r->applicant_id,
                'assigned_room_id' => $r->assigned_room_id,
                'attendance_status' => $r->attendance_status?->value ?? $r->attendance_status,
                'result_status' => $r->result_status?->value ?? $r->result_status,
                'exam_passkey_hash' => $r->exam_passkey_hash,
                'passkey_status' => $r->passkey_status,
                'passkey_sent_to_email' => $r->passkey_sent_to_email,
                'passkey_used_at' => optional($r->passkey_used_at)?->toIso8601String(),
                'passkey_expires_at' => optional($r->passkey_expires_at)?->toIso8601String(),
                'previous_schedule_id' => $r->previous_schedule_id,
                'reschedule_reason' => $r->reschedule_reason,
                'rescheduled_at' => optional($r->rescheduled_at)?->toIso8601String(),
            ])->values()->all(),
            'proctors' => $proctors->map(function (User $u) use ($includeAuthSecrets) {
                $row = [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'status' => $u->status?->value ?? $u->status,
                    'phone' => $u->phone,
                    'address' => $u->address,
                ];
                if ($includeAuthSecrets) {
                    $row['password_hash'] = $u->getAttributes()['password'] ?? null;
                }

                return $row;
            })->values()->all(),
            'question_banks' => $banks->map(function (QuestionBank $bank) {
                return [
                    'id' => $bank->id,
                    'school_year' => $bank->school_year,
                    'title' => $bank->title,
                    'is_active' => (bool) $bank->is_active,
                    'subjects' => $bank->subjects->map(function (ExamSubject $subject) {
                        return [
                            'id' => $subject->id,
                            'question_bank_id' => $subject->question_bank_id,
                            'name' => $subject->name,
                            'code' => $subject->code,
                            'description' => $subject->description,
                            'sort_order' => $subject->sort_order ?? 0,
                            'is_active' => (bool) $subject->is_active,
                            'selection_limit' => $subject->selection_limit,
                            'questions' => $subject->questions->map(fn (ExamQuestion $q) => [
                                'id' => $q->id,
                                'exam_subject_id' => $q->exam_subject_id,
                                'stem' => $q->stem,
                                'question_type' => $q->question_type,
                                'options' => $q->options,
                                'correct_answer' => $q->correct_answer,
                                'difficulty' => $q->difficulty,
                                'status' => $q->status,
                                'is_selected_for_exam' => (bool) $q->is_selected_for_exam,
                                'selected_at' => optional($q->selected_at)?->toIso8601String(),
                                'sort_order' => $q->sort_order,
                            ])->values()->all(),
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'examination_settings' => tap(ExaminationSetting::current(), function () {})->only([
                'duration_minutes',
                'allow_late_entry',
                'shuffle_questions',
                'shuffle_categories',
                'shuffle_both',
                'disconnect_grace_seconds',
                'room_student_limit',
            ]),
            'grading_settings' => CourseGradingSetting::query()->orderBy('id')->get([
                'course_code',
                'course_name',
                'passing_percentage',
                'failing_grade_point',
                'is_active',
            ])->toArray(),
        ];
    }

    /**
     * Lightweight pack freshness check for proctor phones.
     * Used to notify when applicants were rescheduled or questions changed after the last download.
     *
     * @return array<string, mixed>
     */
    public function status(
        ?string $examDate = null,
        ?string $since = null,
        ?string $localFingerprint = null,
        ?int $scheduleId = null,
    ): array
    {
        $scheduleQuery = ExaminationSchedule::query()
            ->inCurrentCalendarYear()
            ->inCurrentAcademicYear()
            ->orderBy('id')
            ->when($scheduleId, fn ($query) => $query->whereKey($scheduleId));
        if ($examDate) {
            $scheduleQuery->whereDate('exam_date', $examDate);
        }
        $scheduleIds = $scheduleQuery->pluck('id');

        $registrations = ExaminationRegistration::query()
            ->whereIn('examination_schedule_id', $scheduleIds)
            ->orderBy('id')
            ->get([
                'id',
                'examination_schedule_id',
                'applicant_id',
                'exam_passkey_hash',
                'passkey_status',
                'passkey_expires_at',
                'previous_schedule_id',
                'rescheduled_at',
            ]);

        $rescheduled = $registrations->filter(fn (ExaminationRegistration $r) => filled($r->rescheduled_at));
        $sinceTs = $since ? strtotime($since) : null;
        $rescheduledSince = $rescheduled->filter(function (ExaminationRegistration $r) use ($sinceTs) {
            if ($sinceTs === null) {
                return false;
            }
            $at = optional($r->rescheduled_at)->getTimestamp();

            return $at !== null && $at > $sinceTs;
        })->count();

        $rescheduledToday = $rescheduled->filter(function (ExaminationRegistration $r) {
            return optional($r->rescheduled_at)?->isToday();
        })->count();

        // Active exam questions (same filter as pack export) — detect new / edited items.
        $questions = ExamQuestion::query()
            ->where('is_selected_for_exam', true)
            ->where(function ($inner) {
                $inner->whereNull('status')->orWhere('status', 'active');
            })
            ->whereHas('subject', function ($q) {
                $q->where('is_active', true)
                    ->whereHas('questionBank', fn ($bank) => $bank->where('is_active', true));
            })
            ->orderBy('id')
            ->get([
                'id',
                'exam_subject_id',
                'stem',
                'difficulty',
                'status',
                'is_selected_for_exam',
                'selected_at',
                'sort_order',
                'created_at',
                'updated_at',
            ]);

        $questionsChangedSince = $questions->filter(function (ExamQuestion $q) use ($sinceTs) {
            if ($sinceTs === null) {
                return false;
            }
            $updated = optional($q->updated_at)->getTimestamp();
            $created = optional($q->created_at)->getTimestamp();
            $selected = optional($q->selected_at)->getTimestamp();
            $latest = max($updated ?: 0, $created ?: 0, $selected ?: 0);

            return $latest > $sinceTs;
        })->count();

        $questionsChangedToday = $questions->filter(function (ExamQuestion $q) {
            return optional($q->updated_at)?->isToday()
                || optional($q->created_at)?->isToday()
                || optional($q->selected_at)?->isToday();
        })->count();

        $settings = ExaminationSetting::current();
        $fingerprintSource = [
            'schedules' => $scheduleIds->values()->all(),
            'registrations' => $registrations
                ->map(fn (ExaminationRegistration $r) => [
                    $r->id,
                    $r->examination_schedule_id,
                    $r->applicant_id,
                    $r->exam_passkey_hash,
                    $r->passkey_status,
                    optional($r->passkey_expires_at)?->toIso8601String(),
                    $r->previous_schedule_id,
                    optional($r->rescheduled_at)?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'questions' => $questions
                ->map(fn (ExamQuestion $q) => [
                    $q->id,
                    $q->exam_subject_id,
                    $q->stem,
                    $q->options,
                    $q->correct_answer,
                    $q->difficulty,
                    $q->status,
                    (bool) $q->is_selected_for_exam,
                    (int) ($q->sort_order ?? 0),
                    optional($q->selected_at)?->toIso8601String(),
                    optional($q->updated_at)?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'settings' => [
                (int) ($settings->duration_minutes ?? 60),
                (int) ($settings->room_student_limit ?? 60),
                (bool) ($settings->shuffle_questions ?? false),
                (bool) ($settings->shuffle_categories ?? false),
                (bool) ($settings->shuffle_both ?? false),
            ],
        ];
        $contentFingerprint = hash('sha256', json_encode($fingerprintSource));

        $fingerprintMismatch = $localFingerprint
            && $localFingerprint !== ''
            && ! hash_equals($contentFingerprint, $localFingerprint);

        $hasRescheduleSignal = $rescheduledSince > 0 || ($sinceTs === null && $rescheduledToday > 0);
        $hasQuestionSignal = $questionsChangedSince > 0 || ($sinceTs === null && $questionsChangedToday > 0);

        $updateRequired = $hasRescheduleSignal
            || $hasQuestionSignal
            || (bool) $fingerprintMismatch;

        $reason = null;
        if ($hasRescheduleSignal) {
            $reason = 'reschedule';
        } elseif ($hasQuestionSignal) {
            $reason = 'questions';
        } elseif ($fingerprintMismatch) {
            $reason = 'fingerprint';
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'exam_date_filter' => $examDate,
            'schedule_count' => $scheduleIds->count(),
            'registration_count' => $registrations->count(),
            'rescheduled_total' => $rescheduled->count(),
            'rescheduled_since' => $rescheduledSince,
            'rescheduled_today' => $rescheduledToday,
            'question_count' => $questions->count(),
            'questions_changed_since' => $questionsChangedSince,
            'questions_changed_today' => $questionsChangedToday,
            'content_fingerprint' => $contentFingerprint,
            'update_required' => $updateRequired,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return array{schedules: int, rooms: int, applicants: int, registrations: int, proctors: int, banks: int, questions: int}
     */
    public function import(array $pack): array
    {
        $counts = [
            'schedules' => 0,
            'rooms' => 0,
            'applicants' => 0,
            'registrations' => 0,
            'proctors' => 0,
            'banks' => 0,
            'questions' => 0,
        ];

        DB::transaction(function () use ($pack, &$counts) {
            $proctorRoleId = Role::query()->where('slug', 'proctor')->value('id');

            foreach ($pack['proctors'] ?? [] as $row) {
                if (empty($row['email']) || empty($row['id'])) {
                    continue;
                }
                $id = (int) $row['id'];
                $exists = User::withTrashed()->find($id);
                $payload = [
                    'role_id' => $proctorRoleId ?? $exists?->role_id,
                    'name' => $row['name'] ?? 'Proctor',
                    'email' => $row['email'],
                    'status' => $row['status'] ?? 'active',
                    'phone' => $row['phone'] ?? null,
                    'address' => $row['address'] ?? null,
                    'email_verified_at' => now(),
                ];

                if ($exists) {
                    $exists->forceFill($payload)->save();
                } else {
                    $user = new User($payload);
                    $user->id = $id;
                    $user->save();
                }

                $hash = $row['password_hash'] ?? null;
                if (is_string($hash) && $hash !== '') {
                    DB::table('users')->where('id', $id)->update(['password' => $hash]);
                }
                $counts['proctors']++;
            }

            foreach ($pack['schedules'] ?? [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                ExaminationSchedule::query()->updateOrCreate(
                    ['id' => $id],
                    [
                        'title' => $row['title'] ?? 'Examination',
                        'exam_date' => $row['exam_date'] ?? now()->toDateString(),
                        'start_time' => $row['start_time'] ?? null,
                        'end_time' => $row['end_time'] ?? null,
                        'time_slot' => $row['time_slot'] ?? null,
                        'venue' => $row['venue'] ?? null,
                        'batch_code' => $row['batch_code'] ?? null,
                        'course' => $row['course'] ?? null,
                        'proctor_id' => $row['proctor_id'] ?? null,
                        'status' => $row['status'] ?? 'scheduled',
                        'expected_examinees' => $row['expected_examinees'] ?? 0,
                    ]
                );
                $counts['schedules']++;

                foreach ($row['rooms'] ?? [] as $room) {
                    $rid = (int) ($room['id'] ?? 0);
                    if ($rid < 1) {
                        continue;
                    }
                    ExaminationRoom::query()->updateOrCreate(
                        ['id' => $rid],
                        [
                            'examination_schedule_id' => (int) ($room['examination_schedule_id'] ?? $id),
                            'room_name' => $room['room_name'] ?? 'Room',
                            'capacity' => (int) ($room['capacity'] ?? 40),
                            'proctor_id' => $room['proctor_id'] ?? null,
                        ]
                    );
                    $counts['rooms']++;
                }
            }

            foreach ($pack['applicants'] ?? [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1 || empty($row['applicant_code'])) {
                    continue;
                }
                Applicant::query()->updateOrCreate(
                    ['id' => $id],
                    [
                        'applicant_code' => $row['applicant_code'],
                        'reference_number' => $row['reference_number'] ?? null,
                        'name' => $row['name'] ?? 'Applicant',
                        'email' => $row['email'] ?? null,
                        'gmail' => $row['gmail'] ?? null,
                        'course_applied' => $row['course_applied'] ?? null,
                        'preferred_exam_time' => $row['preferred_exam_time'] ?? null,
                        'application_date' => $row['application_date'] ?? null,
                        'status' => $row['status'] ?? 'registered',
                    ]
                );
                $counts['applicants']++;
            }

            foreach ($pack['registrations'] ?? [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                $scheduleId = (int) ($row['examination_schedule_id'] ?? 0);
                $applicantId = (int) ($row['applicant_id'] ?? 0);
                if ($id < 1 || $scheduleId < 1 || $applicantId < 1) {
                    continue;
                }

                $existing = ExaminationRegistration::query()->find($id);
                if ($existing && $existing->score !== null) {
                    // Keep local graded results; do not overwrite with cloud pending row.
                    continue;
                }

                ExaminationRegistration::query()->updateOrCreate(
                    ['id' => $id],
                    [
                        'examination_schedule_id' => $scheduleId,
                        'applicant_id' => $applicantId,
                        'attendance_status' => $row['attendance_status'] ?? 'pending',
                        'result_status' => $row['result_status'] ?? 'pending',
                        'exam_passkey_hash' => $row['exam_passkey_hash']
                            ?? (isset($row['exam_passkey']) ? app(ExaminationPasskeyService::class)->hashPasskey((string) $row['exam_passkey']) : $existing?->exam_passkey_hash),
                        'exam_passkey_encrypted' => $row['exam_passkey_encrypted']
                            ?? (isset($row['exam_passkey']) && $row['exam_passkey'] !== ''
                                ? app(ExaminationPasskeyService::class)->encryptPasskey((string) $row['exam_passkey'])
                                : $existing?->exam_passkey_encrypted),
                        'passkey_status' => $row['passkey_status'] ?? $existing?->passkey_status ?? 'not_sent',
                        'passkey_sent_to_email' => $row['passkey_sent_to_email'] ?? $existing?->passkey_sent_to_email,
                        'passkey_used_at' => $row['passkey_used_at'] ?? $existing?->passkey_used_at,
                        'passkey_expires_at' => $row['passkey_expires_at'] ?? $existing?->passkey_expires_at,
                        'sync_status' => $existing?->sync_status ?? 'pending',
                    ]
                );
                $counts['registrations']++;
            }

            foreach ($pack['question_banks'] ?? [] as $bankRow) {
                $bankId = (int) ($bankRow['id'] ?? 0);
                if ($bankId < 1) {
                    continue;
                }
                QuestionBank::query()->updateOrCreate(
                    ['id' => $bankId],
                    [
                        'school_year' => $bankRow['school_year'] ?? '',
                        'title' => $bankRow['title'] ?? 'Question Bank',
                        'is_active' => (bool) ($bankRow['is_active'] ?? false),
                    ]
                );
                $counts['banks']++;

                foreach ($bankRow['subjects'] ?? [] as $subjectRow) {
                    $subjectId = (int) ($subjectRow['id'] ?? 0);
                    if ($subjectId < 1) {
                        continue;
                    }
                    $subjectAttrs = [
                        'question_bank_id' => (int) ($subjectRow['question_bank_id'] ?? $bankId),
                        'name' => $subjectRow['name'] ?? 'Subject',
                        'code' => $subjectRow['code'] ?? null,
                        'description' => $subjectRow['description'] ?? null,
                        'sort_order' => $subjectRow['sort_order'] ?? 0,
                        'is_active' => (bool) ($subjectRow['is_active'] ?? true),
                        'selection_limit' => $subjectRow['selection_limit'] ?? null,
                    ];
                    ExamSubject::query()->updateOrCreate(['id' => $subjectId], $subjectAttrs);

                    foreach ($subjectRow['questions'] ?? [] as $qRow) {
                        $qid = (int) ($qRow['id'] ?? 0);
                        if ($qid < 1) {
                            continue;
                        }
                        $question = ExamQuestion::query()->updateOrCreate(
                            ['id' => $qid],
                            [
                                'exam_subject_id' => (int) ($qRow['exam_subject_id'] ?? $subjectId),
                                'stem' => $qRow['stem'] ?? '',
                                'question_type' => $qRow['question_type'] ?? 'mcq',
                                'difficulty' => $qRow['difficulty'] ?? 'medium',
                                'status' => $qRow['status'] ?? 'active',
                                'is_selected_for_exam' => (bool) ($qRow['is_selected_for_exam'] ?? false),
                                'selected_at' => $qRow['selected_at'] ?? null,
                                'sort_order' => $qRow['sort_order'] ?? 0,
                            ]
                        );
                        $question->syncChoices($qRow['options'] ?? [], $qRow['correct_answer'] ?? 'A');
                        $counts['questions']++;
                    }
                }
            }

            if (! empty($pack['examination_settings']) && is_array($pack['examination_settings'])) {
                $settings = ExaminationSetting::current();
                $settings->forceFill([
                    'duration_minutes' => $pack['examination_settings']['duration_minutes'] ?? $settings->duration_minutes,
                    'allow_late_entry' => $pack['examination_settings']['allow_late_entry'] ?? $settings->allow_late_entry,
                    'shuffle_questions' => $pack['examination_settings']['shuffle_questions'] ?? $settings->shuffle_questions,
                    'shuffle_categories' => $pack['examination_settings']['shuffle_categories'] ?? $settings->shuffle_categories,
                    'shuffle_both' => $pack['examination_settings']['shuffle_both'] ?? $settings->shuffle_both,
                    'room_student_limit' => $pack['examination_settings']['room_student_limit'] ?? $settings->room_student_limit ?? 60,
                ])->save();
            }

            foreach ($pack['grading_settings'] ?? [] as $g) {
                $code = $g['course_code'] ?? null;
                if (! $code) {
                    continue;
                }
                CourseGradingSetting::query()->updateOrCreate(
                    ['course_code' => $code],
                    [
                        'course_name' => $g['course_name'] ?? $code,
                        'passing_percentage' => $g['passing_percentage'] ?? 75,
                        'failing_grade_point' => $g['failing_grade_point'] ?? 5.0,
                        'is_active' => (bool) ($g['is_active'] ?? true),
                    ]
                );
            }
        });

        return $counts;
    }

    /**
     * LAN host: download pack from central ADMIN_SYNC_URL and import.
     *
     * @return array{counts: array<string, int>, message: string}
     */
    public function pullFromCloud(?string $examDate = null): array
    {
        $base = rtrim((string) (config('services.admin_sync.url') ?: env('ADMIN_SYNC_URL', '')), '/');
        $token = (string) (config('services.admin_sync.token') ?: env('ADMIN_SYNC_TOKEN', ''));

        if ($base === '' || $token === '') {
            throw new \RuntimeException(
                'Cloud pull is not configured. Set ADMIN_SYNC_URL and ADMIN_SYNC_TOKEN on this LAN server (point URL at the central /api/v1).'
            );
        }

        /*
         * php artisan serve is single-threaded. If ADMIN_SYNC_URL points at this
         * same API, an HTTP self-call deadlocks until timeout. Import from a
         * local export instead (useful for local demos). For real Option B,
         * point ADMIN_SYNC_URL at the cloud API host.
         */
        if ($this->isSelfAdminSyncUrl($base)) {
            $pack = $this->export($examDate, true);
            $counts = $this->import($pack);
            $total = array_sum($counts);

            return [
                'counts' => $counts,
                'message' => "Refreshed local exam data ({$total} records). ADMIN_SYNC_URL points at this server — set it to the cloud API to pull remote schedules/questions.",
            ];
        }

        $query = http_build_query(array_filter([
            'exam_date' => $examDate,
            // The LAN host needs proctor accounts to support offline login.
            'include_auth' => '1',
        ], static fn ($value) => $value !== null && $value !== ''));
        $query = $query !== '' ? '?'.$query : '';

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->withToken($token)
                ->acceptJson()
                ->get($base.'/sync/exam-day-pack'.$query);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Cannot reach the central admin API. Connect this LAN server to the internet and try again. ('.$e->getMessage().')'
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                $response->json('message') ?: 'Central API rejected the exam-day pack request (HTTP '.$response->status().').'
            );
        }

        $pack = $response->json('data');
        if (! is_array($pack)) {
            throw new \RuntimeException('Central API returned an empty exam-day pack.');
        }

        $counts = $this->import($pack);
        $total = array_sum($counts);

        return [
            'counts' => $counts,
            'message' => "Pulled exam data from admin ({$total} records upserted). You can disconnect from the internet for exam day.",
        ];
    }

    private function isSelfAdminSyncUrl(string $base): bool
    {
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if ($host === '' || in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return true;
        }

        $appUrl = (string) config('app.url');
        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

        return $appHost !== '' && $host === $appHost;
    }
}
