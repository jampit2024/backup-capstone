<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultEmailBatch extends Model
{
    protected $fillable = [
        'requested_by',
        'total',
        'queued',
        'sent',
        'failed',
        'status',
        'filters',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ExaminationRegistration::class, 'result_email_batch_id');
    }
}
