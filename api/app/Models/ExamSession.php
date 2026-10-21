<?php

namespace App\Models;

use App\Enums\ExamSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ExamSession extends Model
{
    protected $fillable = [
        'examination_schedule_id',
        'examination_room_id',
        'question_bank_id',
        'proctor_id',
        'exam_code',
        'session_code',
        'qr_token',
        'proctor_local_ip',
        'proctor_local_port',
        'status',
        'expires_at',
        'started_at',
        'ended_at',
        'sync_status',
        'synced_at',
        'wifi_ssid',
        'wifi_bssid',
        'local_server_ip',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExamSessionStatus::class,
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'synced_at' => 'datetime',
            'proctor_local_port' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ExamSession $session) {
            $active = in_array($session->status, [ExamSessionStatus::Open, ExamSessionStatus::LobbyOpen, ExamSessionStatus::InProgress], true);
            $session->active_room_id = $active ? $session->examination_room_id : null;
            $session->active_proctor_id = $active && $session->examination_room_id ? $session->proctor_id : null;
        });
        static::creating(function (ExamSession $session) {
            if (empty($session->exam_code) && ! empty($session->session_code)) {
                $session->exam_code = $session->session_code;
            }
            if (empty($session->session_code) && ! empty($session->exam_code)) {
                $session->session_code = $session->exam_code;
            }
        });
    }

    public static function generateSessionCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::query()->whereIn('status', [ExamSessionStatus::Open, ExamSessionStatus::LobbyOpen])->where('session_code', $code)->exists());

        return $code;
    }

    public static function generateQrToken(): string
    {
        do {
            $token = (string) Str::uuid();
        } while (static::query()->whereIn('status', [ExamSessionStatus::Open, ExamSessionStatus::LobbyOpen])->where('qr_token', $token)->exists());

        return $token;
    }

    public function isOpen(): bool
    {
        $statusValue = $this->status instanceof ExamSessionStatus ? $this->status->value : (string) $this->status;
        if (! in_array($statusValue, [ExamSessionStatus::Open->value, ExamSessionStatus::LobbyOpen->value], true)) {
            return false;
        }

        return ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ExaminationSchedule::class, 'examination_schedule_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ExaminationRoom::class, 'examination_room_id');
    }

    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class);
    }

    public function proctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proctor_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ExaminationRegistration::class, 'exam_session_id');
    }

    public function joins(): HasMany
    {
        return $this->hasMany(ExamSessionJoin::class, 'exam_session_id');
    }

    public function isJoinable(): bool
    {
        if ($this->isOpen()) {
            return true;
        }

        // Late entry only when explicitly enabled in examination settings.
        if ($this->status === ExamSessionStatus::InProgress) {
            return (bool) ExaminationSetting::current()->allow_late_entry;
        }

        return false;
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->proctor_id === (int) $user->id;
    }
}
