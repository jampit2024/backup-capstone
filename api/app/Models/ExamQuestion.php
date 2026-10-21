<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ExamQuestion extends Model
{
    protected $with = ['choices'];

    protected $fillable = [
        'exam_subject_id',
        'created_by',
        'stem',
        'question_type',
        'difficulty',
        'status',
        'is_selected_for_exam',
        'selected_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_selected_for_exam' => 'boolean',
            'selected_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class, 'exam_subject_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function choices(): HasMany
    {
        return $this->hasMany(ExamQuestionChoice::class, 'exam_question_id')->orderBy('sort_order');
    }

    public function getOptionsAttribute(): array
    {
        return $this->choices->map(fn (ExamQuestionChoice $choice) => [
            'key' => $choice->choice_key,
            'text' => $choice->choice_text,
        ])->all();
    }

    public function getCorrectAnswerAttribute(): ?string
    {
        return $this->choices->firstWhere('is_correct', true)?->choice_key;
    }

    public function syncChoices(array $options, ?string $correctAnswer): void
    {
        $correct = strtoupper(trim((string) $correctAnswer));
        $normalized = [];
        $seenKeys = [];
        foreach (array_values($options) as $index => $option) {
            $originalKey = array_keys($options)[$index] ?? $index;
            if (is_array($option)) {
                $key = strtoupper(trim((string) ($option['key'] ?? (is_string($originalKey) ? $originalKey : ''))));
                $text = $option['text'] ?? $option['label'] ?? $option['value'] ?? '';
            } else {
                $key = is_string($originalKey) && preg_match('/^[A-Za-z]{1,10}$/', $originalKey)
                    ? strtoupper($originalKey)
                    : chr(65 + $index);
                $text = $option;
            }

            if ($key === '') {
                $key = chr(65 + $index);
            }
            if (isset($seenKeys[$key])) {
                throw new \InvalidArgumentException('Question choice keys must be unique.');
            }
            $seenKeys[$key] = true;
            $normalized[] = [
                'choice_key' => $key,
                'choice_text' => (string) $text,
                'sort_order' => $index,
                'is_correct' => $key === $correct,
            ];
        }

        if ($correct !== '' && ! collect($normalized)->contains('choice_key', $correct)) {
            throw new \InvalidArgumentException('Correct answer must match one of the question choices.');
        }

        DB::transaction(function () use ($normalized): void {
            $this->choices()->delete();
            if ($normalized !== []) {
                $this->choices()->createMany($normalized);
            }
            $this->unsetRelation('choices');
        });
    }
}
