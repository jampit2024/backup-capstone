<?php

namespace Tests\Feature;

use App\Models\ExamQuestion;
use App\Models\ExaminationAnswer;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\ExamSubject;
use App\Models\Applicant;
use App\Models\QuestionBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExamQuestionChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_choices_are_normalized_and_keep_the_existing_question_shape(): void
    {
        $question = $this->createQuestion();
        $question->syncChoices([
            ['key' => 'A', 'text' => 'First choice'],
            ['key' => 'B', 'text' => 'Second choice'],
        ], 'B');

        $question = $question->fresh();

        $this->assertSame([
            ['key' => 'A', 'text' => 'First choice'],
            ['key' => 'B', 'text' => 'Second choice'],
        ], $question->options);
        $this->assertSame('B', $question->correct_answer);
        $this->assertDatabaseHas('exam_question_choices', [
            'exam_question_id' => $question->id,
            'choice_key' => 'B',
            'choice_text' => 'Second choice',
            'is_correct' => true,
        ]);
        $this->assertFalse(Schema::hasColumn('exam_questions', 'options'));
        $this->assertFalse(Schema::hasColumn('exam_questions', 'correct_answer'));
    }

    public function test_replacing_choices_removes_stale_rows_and_updates_the_answer_key(): void
    {
        $question = $this->createQuestion();
        $question->syncChoices(['Old answer', 'Old distractor'], 'A');
        $question->syncChoices(['New answer', 'New distractor'], 'B');

        $this->assertSame('B', $question->fresh()->correct_answer);
        $this->assertDatabaseMissing('exam_question_choices', [
            'exam_question_id' => $question->id,
            'choice_text' => 'Old answer',
        ]);
        $this->assertDatabaseCount('exam_question_choices', 2);
    }

    public function test_answers_keep_the_selected_letter_as_an_immutable_scalar_snapshot(): void
    {
        $question = $this->createQuestion();
        $question->syncChoices(['First choice', 'Second choice'], 'B');
        $choice = $question->choices()->where('choice_key', 'B')->firstOrFail();
        $applicant = Applicant::query()->create([
            'applicant_code' => 'APP-CHOICE-1',
            'name' => 'Choice Test Applicant',
        ]);
        $schedule = ExaminationSchedule::query()->create([
            'title' => 'Choice Test Exam',
            'exam_date' => '2026-09-30',
            'start_time' => '09:00:00',
        ]);
        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
        ]);

        $answer = ExaminationAnswer::query()->create([
            'examination_registration_id' => $registration->id,
            'exam_question_id' => $question->id,
            'selected_answer' => 'B',
            'is_correct' => true,
        ])->fresh();

        $this->assertSame('B', $answer->selected_answer);
        $this->assertSame($choice->id, $question->choices()->where('choice_key', $answer->selected_answer)->value('id'));
        $this->assertTrue(Schema::hasColumn('exam_answers', 'selected_answer'));
        $this->assertFalse(Schema::hasColumn('exam_answers', 'exam_question_choice_id'));
    }

    public function test_database_seed_chain_writes_normalized_question_choices(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $question = ExamQuestion::query()->firstOrFail();

        $this->assertNotEmpty($question->options);
        $this->assertSame($question->correct_answer, $question->choices->firstWhere('is_correct', true)?->choice_key);
        $this->assertSame(
            ExamQuestion::query()->count() * 4,
            \DB::table('exam_question_choices')->count()
        );
    }

    private function createQuestion(): ExamQuestion
    {
        $bank = QuestionBank::query()->create([
            'school_year' => '2026',
            'title' => 'Test pack',
            'is_active' => true,
        ]);
        $subject = ExamSubject::query()->create([
            'question_bank_id' => $bank->id,
            'name' => 'Verbal Ability',
            'code' => 'VERBAL',
        ]);

        return ExamQuestion::query()->create([
            'exam_subject_id' => $subject->id,
            'stem' => 'Select an answer',
            'question_type' => 'multiple_choice',
            'status' => 'active',
        ]);
    }
}