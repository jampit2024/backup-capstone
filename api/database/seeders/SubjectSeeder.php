<?php

namespace Database\Seeders;

use App\Models\ExamQuestion;
use App\Models\ExamSubject;
use App\Models\QuestionBank;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            [
                'name' => 'Verbal Ability',
                'code' => 'VERBAL',
                'description' => 'Vocabulary, grammar, reading comprehension, and language usage.',
                'selection_limit' => 20,
            ],
            [
                'name' => 'Numerical Ability',
                'code' => 'NUMERICAL',
                'description' => 'Arithmetic, algebra, word problems, and quantitative reasoning.',
                'selection_limit' => 20,
            ],
            [
                'name' => 'Logical Ability',
                'code' => 'LOGICAL',
                'description' => 'Patterns, sequences, analogies, and critical reasoning.',
                'selection_limit' => 20,
            ],
            [
                'name' => 'Scientific Ability',
                'code' => 'SCIENCE',
                'description' => 'Basic concepts in biology, chemistry, physics, and earth science.',
                'selection_limit' => 20,
            ],
            [
                'name' => 'General Information',
                'code' => 'GENERAL',
                'description' => 'Civics, Philippine history, current events, and general knowledge.',
                'selection_limit' => 20,
            ],
        ];

        $allowedCodes = collect($catalog)->pluck('code')->all();

        // Replace legacy subject sets so every bank uses the five ability categories.
        ExamQuestion::query()->delete();
        ExamSubject::query()->whereNotIn('code', $allowedCodes)->delete();

        $banks = QuestionBank::query()->orderBy('school_year')->get();

        foreach ($banks as $bank) {
            ExamSubject::query()
                ->where('question_bank_id', $bank->id)
                ->whereNotIn('code', $allowedCodes)
                ->delete();

            foreach ($catalog as $index => $item) {
                ExamSubject::query()->updateOrCreate(
                    [
                        'question_bank_id' => $bank->id,
                        'code' => $item['code'],
                    ],
                    [
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'sort_order' => $index + 1,
                        'is_active' => true,
                        'selection_limit' => $item['selection_limit'],
                    ]
                );
            }
        }
    }
}