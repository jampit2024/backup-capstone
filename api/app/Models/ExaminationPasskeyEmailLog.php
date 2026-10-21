<?php

namespace App\Models;

use App\Enums\ResultEmailStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExaminationPasskeyEmailLog extends Model
{
    protected $table = 'exam_passkey_delivery_logs';

    protected $fillable = [
        'examination_registration_id',
        'sent_by',
        'email',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ResultEmailStatus::class,
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ExaminationRegistration::class, 'examination_registration_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
