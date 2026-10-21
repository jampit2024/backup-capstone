<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\ExaminationStatus;
use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExaminationRegistration extends Model
{
    protected $table = 'exam_registrations';

    protected $hidden = ['exam_passkey_hash', 'exam_passkey_encrypted'];

    protected $fillable = [
        'examination_schedule_id',
        'assigned_room_id',
        'room_assignment_manual',
        'exam_session_id',
        'participation_token',
        'pending_device_id',
        'lobby_status',
        'joined_lobby_at',
        'last_heartbeat_at',
        'agreed_at',
        'reconnect_allowed',
        'reconnect_code',
        'reconnect_code_expires_at',
        'previous_schedule_id',
        'applicant_id',
        'exam_passkey_hash',
        'exam_passkey_encrypted',
        'passkey_status',
        'passkey_sent_to_email',
        'passkey_used_at',
        'passkey_expires_at',
        'passkey_email_sent',
        'passkey_email_sent_at',
        'passkey_email_status',
        'passkey_email_attempts',
        'passkey_email_error',
        'attendance_status',
        'result_status',
        'score',
        'items_correct',
        'items_total',
        'grade_point',
        'examination_method',
        'manual_score',
        'manual_max_score',
        'manual_remarks',
        'manual_recorded_by',
        'manual_recorded_at',
        'manual_last_modified_by',
        'sync_status',
        'synced_at',
        'sync_error',
        'reschedule_reason',
        'rescheduled_at',
        'rescheduled_by',
        'email_sent_at',
        'email_sent',
        'email_status',
        'email_attempts',
        'email_error_message',
        'result_email_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'attendance_status' => AttendanceStatus::class,
            'result_status' => ResultStatus::class,
            'email_status' => ResultEmailStatus::class,
            'passkey_email_status' => ResultEmailStatus::class,
            'email_sent' => 'boolean',
            'passkey_email_sent' => 'boolean',
            'score' => 'decimal:2',
            'items_correct' => 'integer',
            'items_total' => 'integer',
            'grade_point' => 'decimal:2',
            'manual_score' => 'decimal:2',
            'manual_max_score' => 'decimal:2',
            'manual_recorded_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'passkey_email_sent_at' => 'datetime',
            'joined_lobby_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'agreed_at' => 'datetime',
            'reconnect_allowed' => 'boolean',
            'reconnect_code_expires_at' => 'datetime',
            'synced_at' => 'datetime',
            'passkey_email_attempts' => 'integer',
            'passkey_used_at' => 'datetime',
            'passkey_expires_at' => 'datetime',
        ];
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExaminationAnswer::class, 'examination_registration_id');
    }

    public function assignedRoom(): BelongsTo
    {
        return $this->belongsTo(ExaminationRoom::class, 'assigned_room_id');
    }

    public function save(array $options = [])
    {
        if ($this->exists && ! $this->isDirty('examination_schedule_id')) {
            return parent::save($options);
        }
        return \Illuminate\Support\Facades\DB::transaction(function () use ($options) {
            $ids = array_filter([$this->getOriginal('examination_schedule_id'), $this->examination_schedule_id]);
            $schedules = ExaminationSchedule::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $service = app(\App\Services\RoomAssignmentService::class);
            $targetSchedule = $schedules->firstWhere('id', $this->examination_schedule_id);
            if ($targetSchedule && $targetSchedule->rooms_managed) {
                $service->assertEditable($targetSchedule);
            }
            if (! $this->room_assignment_manual || ! $this->assigned_room_id) {
                $this->assigned_room_id = null;
                $this->room_assignment_manual = false;
            }
            $result = parent::save($options);
            foreach ($schedules as $schedule) {
                $status = $schedule->status?->value ?? $schedule->status;
                if ($schedule->rooms_managed && $status === ExaminationStatus::Scheduled->value) {
                    $service->distribute($schedule);
                }
            }
            $this->refresh();
            return $result;
        }, 3);
    }

    public function delete()
    {
        return \Illuminate\Support\Facades\DB::transaction(function () {
            $schedule = ExaminationSchedule::whereKey($this->examination_schedule_id)->lockForUpdate()->first();
            $service = app(\App\Services\RoomAssignmentService::class);
            if ($schedule?->rooms_managed) {
                $service->assertEditable($schedule);
            }
            $result = parent::delete();
            if ($schedule) {
                $service->distribute($schedule);
            }
            return $result;
        }, 3);
    }

    public function passkeyEmailLogs(): HasMany
    {
        return $this->hasMany(ExaminationPasskeyEmailLog::class, 'examination_registration_id');
    }

    public function emailBatch(): BelongsTo
    {
        return $this->belongsTo(ResultEmailBatch::class, 'result_email_batch_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ExaminationSchedule::class, 'examination_schedule_id');
    }

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function previousSchedule(): BelongsTo
    {
        return $this->belongsTo(ExaminationSchedule::class, 'previous_schedule_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function rescheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rescheduled_by');
    }

    public function manualRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_recorded_by');
    }

    public function manualLastModifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_last_modified_by');
    }

    public function manualScoreHistory(): HasMany
    {
        return $this->hasMany(ManualScoreHistory::class, 'examination_registration_id')->latest();
    }

    public function canReschedule(): bool
    {
        $attendance = $this->attendance_status?->value ?? $this->attendance_status;
        $result = $this->result_status?->value ?? $this->result_status;

        if ($attendance === AttendanceStatus::Present->value && $result !== ResultStatus::Pending->value) {
            return false;
        }

        return true;
    }
}
