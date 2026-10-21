<?php

namespace Tests\Feature;

use App\Models\ExamQuestion;
use App\Models\ExamSubject;
use App\Models\QuestionBank;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuestionBankRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_bank_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $bank = $this->bank('2026', true);

        $this->actingAs($admin)->deleteJson("/api/question-banks/{$bank->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The active question bank cannot be deleted. Set another bank active first.');

        $this->assertDatabaseHas('exam_packs', ['id' => $bank->id]);
    }

    public function test_activating_a_bank_switches_the_only_active_flag(): void
    {
        $admin = $this->admin();
        $active = $this->bank('2025', true);
        $next = $this->bank('2026');

        $this->actingAs($admin)->postJson("/api/question-banks/{$next->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse($active->fresh()->is_active);
        $this->assertTrue($next->fresh()->is_active);
        $this->assertSame(1, QuestionBank::query()->where('is_active', true)->count());
    }

    public function test_duplicate_bank_copies_categories_and_questions_as_unselected_drafts(): void
    {
        $admin = $this->admin();
        $bank = $this->bank('2025', true);
        $subject = $this->subject($bank, 1);
        $question = $this->question($subject, true);

        $this->actingAs($admin)->postJson("/api/question-banks/{$bank->id}/duplicate", [
            'school_year' => '2027',
        ])->assertCreated();

        $copy = QuestionBank::query()->where('school_year', '2027')->firstOrFail();
        $copiedQuestion = $copy->questions()->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertSame('draft', $copiedQuestion->status);
        $this->assertFalse($copiedQuestion->is_selected_for_exam);
        $this->assertSame($question->options, $copiedQuestion->options);
        $this->assertSame($question->correct_answer, $copiedQuestion->correct_answer);
    }

    public function test_lowering_selection_limit_trims_oldest_selections_in_one_request(): void
    {
        $admin = $this->admin();
        $subject = $this->subject($this->bank('2026'), 3);
        $questions = collect(range(1, 3))->map(fn ($number) => $this->question($subject, true, $number));

        $this->actingAs($admin)->patchJson("/api/subjects/{$subject->id}", [
            'selection_limit' => 1,
        ])->assertOk();

        $this->assertTrue($questions[0]->fresh()->is_selected_for_exam);
        $this->assertFalse($questions[1]->fresh()->is_selected_for_exam);
        $this->assertFalse($questions[2]->fresh()->is_selected_for_exam);
        $this->assertSame(1, $subject->questions()->where('is_selected_for_exam', true)->count());
        $this->assertSame(3, $subject->questions()->count());
    }

    public function test_question_creation_and_pagination_have_no_category_question_cap(): void
    {
        $admin = $this->admin();
        $subject = $this->subject($this->bank('2026'), 1000);
        $now = now();
        $rows = collect(range(1, 1000))->map(fn ($number) => [
            'exam_subject_id' => $subject->id,
            'stem' => "Question number {$number}",
            'question_type' => 'multiple_choice',
            'difficulty' => 'medium',
            'status' => 'draft',
            'is_selected_for_exam' => false,
            'sort_order' => $number,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('exam_questions')->insert($batch);
        }

        $this->actingAs($admin)->postJson("/api/subjects/{$subject->id}/questions", [
            'stem' => 'Question number 1001',
            'options' => ['One', 'Two', 'Three', 'Four'],
            'correct_answer' => 'B',
            'difficulty' => 'hard',
        ])->assertCreated();

        $this->actingAs($admin)->getJson("/api/subjects/{$subject->id}/questions?per_page=10&page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1001)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonCount(10, 'data');
    }

    public function test_exam_selection_limit_can_exceed_five_hundred_and_lowering_it_keeps_bank_rows(): void
    {
        $admin = $this->admin();
        $subject = $this->subject($this->bank('2026'), 501);
        $questions = collect(range(1, 3))->map(fn ($number) => $this->question($subject, true, $number));

        $this->actingAs($admin)->patchJson("/api/subjects/{$subject->id}", [
            'selection_limit' => 1000,
        ])->assertOk();

        $this->assertSame(1000, $subject->fresh()->selection_limit);
        $this->assertSame(3, $subject->questions()->count());
        $this->assertTrue($questions->every(fn (ExamQuestion $question) => $question->fresh()->is_selected_for_exam));
    }

    public function test_selection_at_limit_replaces_oldest_selected_question(): void
    {
        $admin = $this->admin();
        $subject = $this->subject($this->bank('2026'), 2);
        $oldest = $this->question($subject, true, 1);
        $this->question($subject, true, 2);
        $incoming = $this->question($subject, false, 3);

        $this->actingAs($admin)->postJson("/api/exam-questions/{$incoming->id}/toggle-selection")
            ->assertOk()
            ->assertJsonPath('data.is_selected_for_exam', true);

        $this->assertFalse($oldest->fresh()->is_selected_for_exam);
        $this->assertTrue($incoming->fresh()->is_selected_for_exam);
        $this->assertSame(2, $subject->questions()->where('is_selected_for_exam', true)->count());
    }

    public function test_bulk_selection_applies_multiple_questions_within_category_capacity(): void
    {
        $admin = $this->admin();
        $subject = $this->subject($this->bank('2026'), 3);
        $first = $this->question($subject, true, 1);
        $second = $this->question($subject, false, 2);
        $third = $this->question($subject, false, 3);

        $this->actingAs($admin)->postJson('/api/exam-questions/bulk', [
            'ids' => [$second->id, $third->id],
            'action' => 'select_for_exam',
        ])->assertOk()->assertJsonPath('data.affected', 2);

        $this->assertTrue($first->fresh()->is_selected_for_exam);
        $this->assertTrue($second->fresh()->is_selected_for_exam);
        $this->assertTrue($third->fresh()->is_selected_for_exam);
    }

    public function test_bulk_selection_over_capacity_changes_nothing(): void
    {
        $admin = $this->admin();
        $subject = $this->subject($this->bank('2026'), 2);
        $selected = $this->question($subject, true, 1);
        $incoming = collect([2, 3])->map(fn ($number) => $this->question($subject, false, $number));

        $this->actingAs($admin)->postJson('/api/exam-questions/bulk', [
            'ids' => $incoming->pluck('id')->all(),
            'action' => 'select_for_exam',
        ])->assertUnprocessable();

        $this->assertTrue($selected->fresh()->is_selected_for_exam);
        $incoming->each(fn (ExamQuestion $question) => $this->assertFalse($question->fresh()->is_selected_for_exam));
    }

    public function test_spreadsheet_import_reports_invalid_rows_and_imported_count(): void
    {
        $admin = $this->admin();
        $bank = $this->bank('2026');
        $subject = $this->subject($bank, 4);
        $csv = UploadedFile::fake()->createWithContent(
            'questions.csv',
            "stem,option_a,option_b,option_c,option_d,correct_answer\n\"Choose the best answer\",One,Two,Three,Four,B\n\"Incomplete question\",Only one,,,,A\n"
        );

        $this->actingAs($admin)->post("/api/question-banks/{$bank->id}/import-questions", [
            'file' => $csv,
            'file_type' => 'excel',
            'exam_subject_id' => $subject->id,
            'replace_existing' => '0',
        ])->assertOk()
            ->assertJsonPath('data.import_summary.0.imported', 1)
            ->assertJsonPath('data.import_summary.0.errors.0.row', 3);

        $this->assertSame(1, $subject->questions()->count());
    }

    private function admin(): User
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function bank(string $year, bool $active = false): QuestionBank
    {
        return QuestionBank::query()->create([
            'school_year' => $year,
            'title' => "{$year} Examination Question Bank",
            'is_active' => $active,
        ]);
    }

    private function subject(QuestionBank $bank, int $limit): ExamSubject
    {
        return $bank->subjects()->create([
            'name' => 'Verbal Ability',
            'code' => 'VERBAL',
            'selection_limit' => $limit,
        ]);
    }

    private function question(ExamSubject $subject, bool $selected, int $order = 1): ExamQuestion
    {
        $question = $subject->questions()->create([
            'stem' => "Question number {$order}",
            'status' => $selected ? 'active' : 'draft',
            'is_selected_for_exam' => $selected,
            'selected_at' => $selected ? now()->addSeconds($order) : null,
            'sort_order' => $order,
        ]);
        $question->syncChoices(['Choice A', 'Choice B'], 'A');

        return $question;
    }
}
