<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;

class QuestionBank extends Model
{
    protected $table = 'exam_packs';

    protected $fillable = [
        'school_year',
        'title',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class, 'question_bank_id');
    }

    public function questions(): HasManyThrough
    {
        return $this->hasManyThrough(
            ExamQuestion::class,
            ExamSubject::class,
            'question_bank_id',
            'exam_subject_id'
        );
    }

    public function activate(): void
    {
        DB::transaction(function (): void {
            static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            static::query()->where('id', '!=', $this->id)->where('is_active', true)->update(['is_active' => false]);
            $this->forceFill(['is_active' => true])->save();
        }, 3);
    }

    public static function titleFromSchoolYear(string $schoolYear): string
    {
        return trim($schoolYear).' Examination Question Bank';
    }
}
