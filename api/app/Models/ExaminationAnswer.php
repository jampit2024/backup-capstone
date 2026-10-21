<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExaminationAnswer extends Model
{
    protected $table = 'exam_answers';

    protected $fillable = [
        'examination_registration_id',
        'exam_question_id',
        'selected_answer',
        'is_correct',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ExaminationRegistration::class, 'examination_registration_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

}
