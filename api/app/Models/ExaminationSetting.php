<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExaminationSetting extends Model
{
    protected $table = 'exam_settings';

    protected $fillable = [
        'duration_minutes',
        'allow_late_entry',
        'shuffle_questions',
        'shuffle_categories',
        'shuffle_both',
        'disconnect_grace_seconds',
        'room_student_limit',
        'default_start_at',
        'default_end_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'allow_late_entry' => 'boolean',
            'shuffle_questions' => 'boolean',
            'shuffle_categories' => 'boolean',
            'shuffle_both' => 'boolean',
            'disconnect_grace_seconds' => 'integer',
            'room_student_limit' => 'integer',
            'default_start_at' => 'datetime',
            'default_end_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        $row = static::query()->first();
        if ($row) {
            return $row;
        }

        return static::query()->create([
            'duration_minutes' => 60,
            'allow_late_entry' => false,
            'shuffle_questions' => true,
            'shuffle_categories' => false,
            'shuffle_both' => false,
            'disconnect_grace_seconds' => 120,
            'room_student_limit' => 60,
        ]);
    }
}
