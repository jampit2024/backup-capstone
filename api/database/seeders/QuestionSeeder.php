<?php

namespace Database\Seeders;

use App\Models\ExamQuestion;
use App\Models\ExamSubject;
use App\Models\User;
use Database\Seeders\Data\AbilityQuestionBank;
use Illuminate\Database\Seeder;

class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'rommeljampit21@gmail.com')->first()
            ?? User::query()->first();

        ExamSubject::query()
            ->orderBy('question_bank_id')
            ->orderBy('sort_order')
            ->get()
            ->each(function (ExamSubject $subject) use ($admin) {
                // Always refresh seeded ability banks so counts stay at 20+.
                $subject->questions()->delete();

                $samples = AbilityQuestionBank::forCode((string) $subject->code);
                $limit = max(0, (int) $subject->selection_limit);

                foreach ($samples as $index => $sample) {
                    $selected = $index < $limit;
                    $question = ExamQuestion::query()->create([
                        'exam_subject_id' => $subject->id,
                        'created_by' => $admin?->id,
                        'stem' => $sample['stem'],
                        'question_type' => 'multiple_choice',
                        'difficulty' => $sample['difficulty'],
                        'status' => 'active',
                        'is_selected_for_exam' => $selected,
                        'selected_at' => $selected ? now() : null,
                        'sort_order' => $index + 1,
                    ]);
                    $question->syncChoices($sample['options'], $sample['correct']);
                }
            });
    }
}
