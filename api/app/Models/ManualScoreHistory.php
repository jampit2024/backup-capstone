<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualScoreHistory extends Model
{
    protected $fillable = [
        'examination_registration_id',
        'recorded_by',
        'operation',
        'previous_score',
        'new_score',
        'maximum_score',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_score' => 'decimal:2',
            'new_score' => 'decimal:2',
            'maximum_score' => 'decimal:2',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ExaminationRegistration::class, 'examination_registration_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
