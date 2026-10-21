<?php

namespace Tests\Feature;

use App\Enums\ResultStatus;
use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\CourseGradingSetting;
use App\Services\AdminResultSyncService;
use App\Models\ExaminationRegistration;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\ExaminationSchedule;
use App\Models\ExamSubject;
use App\Models\ManualScoreHistory;
use App\Models\QuestionBank;
use App\Models\Role;
use App\Models\User;
use App\Services\ExaminationResultService;
use App\Support\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualScoreEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_manual_score_uses_the_official_result_and_grading_rules(): void
    {
        $admin = $this->createUser(Role::SLUG_ADMIN, 'Manual Score Admin');
        $schedule = $this->createSchedule(50);
        $applicant = $this->createApplicant('TCC-MANUAL-1', 'Paper Student', 'NURSING');
        $registration = $this->register($schedule, $applicant);
        CourseGradingSetting::query()->create([
            'course_code' => 'NURSING',
            'course_name' => 'Nursing',
            'passing_percentage' => 80,
            'failing_grade_point' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'score' => 42,
            'paper_based_confirmed' => true,
            'remarks' => 'Checked against the paper.',
        ])->assertCreated()
            ->assertJsonPath('data.registration_id', $registration->id)
            ->assertJsonPath('data.score', 42)
            ->assertJsonPath('data.maximum_score', 50)
            ->assertJsonPath('data.percentage', 84)
            ->assertJsonPath('data.result_status', 'passed')
            ->assertJsonPath('data.examination_method', 'manual');

        $this->assertDatabaseHas('exam_registrations', [
            'id' => $registration->id,
            'score' => 84,
            'result_status' => 'passed',
            'examination_method' => 'manual',
            'manual_score' => 42,
            'manual_max_score' => 50,
            'manual_recorded_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('manual_score_histories', [
            'examination_registration_id' => $registration->id,
            'operation' => 'created',
            'new_score' => 42,
            'maximum_score' => 50,
        ]);
        $this->assertSame(1, ActivityLog::query()->where('action', 'manual_exam_score_created')->count());

        $this->getJson('/api/results?year='.AcademicYear::current().'&method=manual')
            ->assertOk()
            ->assertJsonPath('stats.examinees', 1)
            ->assertJsonPath('applicants.0.examinationMethod', 'manual')
            ->assertJsonPath('applicants.0.manualScore', 42)
            ->assertJsonPath('applicants.0.score', 84)
            ->assertJsonPath('applicants.0.displayScore', '42/50')
            ->assertJsonPath('applicants.0.percentage', '84%');

        $this->getJson('/api/results?year='.AcademicYear::current().'&method=online')
            ->assertOk()
            ->assertJsonPath('stats.examinees', 0);

        $manualExport = $this->get('/api/results/export?year='.AcademicYear::current().'&method=manual')->assertOk();
        $this->assertStringContainsString('42/50', $manualExport->streamedContent());
        $this->assertStringContainsString('84%', $manualExport->streamedContent());

        $this->getJson('/api/v1/applicants/'.$applicant->id)
            ->assertOk()
            ->assertJsonPath('data.examination_method', 'manual')
            ->assertJsonPath('data.manual_score', 42)
            ->assertJsonPath('data.display_score', '42/50');
    }

    public function test_invalid_and_duplicate_scores_are_rejected_and_online_results_are_preserved(): void
    {
        $admin = $this->createUser(Role::SLUG_ADMIN, 'Score Admin');
        $schedule = $this->createSchedule();
        $paperApplicant = $this->createApplicant('TCC-MANUAL-2', 'Paper Candidate');
        $paperRegistration = $this->register($schedule, $paperApplicant);

        $payload = [
            'schedule_id' => $schedule->id,
            'applicant_id' => $paperApplicant->id,
            'paper_based_confirmed' => true,
        ];

        $this->actingAs($admin)->postJson('/api/exam-results/manual-entry', $payload + ['score' => 100.01])->assertUnprocessable();
        $this->postJson('/api/exam-results/manual-entry', $payload + ['score' => -1])->assertUnprocessable();
        $this->postJson('/api/exam-results/manual-entry', $payload + ['score' => 80.123])->assertUnprocessable();
        $this->assertNull($paperRegistration->fresh()->score);

        $this->postJson('/api/exam-results/manual-entry', $payload + ['score' => 80])->assertCreated();
        $this->postJson('/api/exam-results/manual-entry', $payload + ['score' => 82])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A manual result already exists for this student and schedule. Edit that score instead.');

        $onlineApplicant = $this->createApplicant('TCC-ONLINE-1', 'Online Candidate');
        $onlineRegistration = $this->register($schedule, $onlineApplicant, [
            'score' => 91,
            'result_status' => ResultStatus::Passed,
        ]);
        $this->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $onlineApplicant->id,
            'score' => 40,
            'paper_based_confirmed' => true,
        ])->assertStatus(409);

        $this->assertSame(91.0, (float) $onlineRegistration->fresh()->score);
        $this->assertSame('online', $onlineRegistration->fresh()->examination_method);

        $staleResultApplicant = $this->createApplicant('TCC-MANUAL-STALE', 'Stale Result Candidate');
        $this->register($schedule, $staleResultApplicant, ['result_status' => ResultStatus::Failed]);
        $this->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $staleResultApplicant->id,
            'score' => 80,
            'paper_based_confirmed' => true,
        ])->assertStatus(409);
    }

    public function test_manual_score_correction_keeps_history_and_online_submission_cannot_replace_it(): void
    {
        $admin = $this->createUser(Role::SLUG_ADMIN, 'Correction Admin');
        $schedule = $this->createSchedule();
        $applicant = $this->createApplicant('TCC-MANUAL-3', 'Correction Candidate');
        $registration = $this->register($schedule, $applicant);

        $this->actingAs($admin)->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'score' => 70,
            'paper_based_confirmed' => true,
        ])->assertCreated()->assertJsonPath('data.result_status', 'failed');

        $this->putJson('/api/exam-results/manual-entry/'.$registration->id, [
            'score' => 85,
            'paper_based_confirmed' => true,
            'reason' => 'Second marker verified the tally.',
        ])->assertOk()
            ->assertJsonPath('data.score', 85)
            ->assertJsonPath('data.result_status', 'passed');

        $history = ManualScoreHistory::query()->where('examination_registration_id', $registration->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertNull($history[0]->previous_score);
        $this->assertSame(70.0, (float) $history[1]->previous_score);
        $this->assertSame(85.0, (float) $history[1]->new_score);
        $this->assertSame('Second marker verified the tally.', $history[1]->reason);

        try {
            app(ExaminationResultService::class)->submitAnswers($registration->fresh(), []);
            $this->fail('A manual result must reject subsequent online answer submission.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This registration has a manual paper-based result and cannot accept online answers.',
                $exception->errors()['registration'][0]
            );
        }

        $this->assertSame(85.0, (float) $registration->fresh()->score);
        $sync = app(AdminResultSyncService::class)->ingest([[
            'applicant_code' => $applicant->applicant_code,
            'examination_schedule_id' => $schedule->id,
            'score' => 20,
            'result_status' => 'failed',
        ]]);
        $this->assertSame(0, $sync['updated']);
        $this->assertSame(1, count($sync['rejected']));
        $this->assertSame(85.0, (float) $registration->fresh()->score);
        $this->getJson('/api/exam-results/manual-entry/'.$registration->id.'/history')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_only_administrators_can_record_manual_scores(): void
    {
        $proctor = $this->createUser(Role::SLUG_PROCTOR, 'Proctor');
        $schedule = $this->createSchedule();
        $applicant = $this->createApplicant('TCC-STAFF-1', 'Restricted Candidate');
        $this->register($schedule, $applicant);

        $this->actingAs($proctor)->getJson('/api/exam-results/manual-entry/schedules')->assertForbidden();
        $this->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'score' => 80,
            'paper_based_confirmed' => true,
        ])->assertForbidden();
    }

    public function test_student_search_returns_registered_exams_across_schedules(): void
    {
        $admin = $this->createUser(Role::SLUG_ADMIN, 'Search Admin');
        $firstSchedule = $this->createSchedule();
        $secondSchedule = $this->createSchedule();
        $applicant = $this->createApplicant('TCC-SEARCH-1', 'Juan Dela Cruz');
        $firstRegistration = $this->register($firstSchedule, $applicant);
        $secondRegistration = $this->register($secondSchedule, $applicant);

        $this->actingAs($admin)
            ->getJson('/api/exam-results/manual-entry/students?search=Juan')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'registration_id' => $firstRegistration->id,
                'schedule_id' => $firstSchedule->id,
                'student_name' => 'Juan Dela Cruz',
                'eligible' => true,
            ])
            ->assertJsonFragment([
                'registration_id' => $secondRegistration->id,
                'schedule_id' => $secondSchedule->id,
                'student_name' => 'Juan Dela Cruz',
                'eligible' => true,
            ]);

        $this->getJson('/api/exam-results/manual-entry/students?search=J')->assertUnprocessable();
    }

    public function test_online_submission_still_calculates_and_saves_an_online_result(): void
    {
        $admin = $this->createUser(Role::SLUG_ADMIN, 'Online Exam Proctor');
        $schedule = $this->createSchedule();
        $applicant = $this->createApplicant('TCC-ONLINE-2', 'Online Scoring Candidate');
        $registration = $this->register($schedule, $applicant);
        $bank = QuestionBank::query()->create([
            'school_year' => '2027',
            'title' => 'Online scoring test bank',
            'is_active' => true,
        ]);
        $subject = ExamSubject::query()->create([
            'question_bank_id' => $bank->id,
            'name' => 'Mathematics',
            'code' => 'MATH',
        ]);
        $question = ExamQuestion::query()->create([
            'exam_subject_id' => $subject->id,
            'stem' => 'One plus one?',
            'question_type' => 'multiple_choice',
            'status' => 'active',
            'is_selected_for_exam' => true,
        ]);
        $question->syncChoices(['Two', 'Three'], 'A');
        $session = ExamSession::query()->create([
            'examination_schedule_id' => $schedule->id,
            'question_bank_id' => $bank->id,
            'proctor_id' => $admin->id,
            'exam_code' => 'ONLINE-SCORE-1',
            'status' => 'in_progress',
        ]);
        $registration->forceFill(['exam_session_id' => $session->id])->save();

        $scored = app(ExaminationResultService::class)->submitAnswers($registration->fresh(), [[
            'exam_question_id' => $question->id,
            'selected_answer' => 'A',
        ]]);

        $this->assertSame('online', $scored->examination_method);
        $this->assertSame(100.0, (float) $scored->score);
        $this->assertSame(ResultStatus::Passed, $scored->result_status);
        $this->assertSame(1, $scored->items_correct);
        $this->assertSame(1, $scored->items_total);

        $this->actingAs($admin)->getJson('/api/results?year='.AcademicYear::current().'&method=online')
            ->assertOk()
            ->assertJsonPath('stats.examinees', 1)
            ->assertJsonPath('applicants.0.examinationMethod', 'online');
        $this->getJson('/api/results/search?q=Online&method=online')
            ->assertOk()
            ->assertJsonPath('0.examinationMethod', 'online');
    }

    private function createUser(string $roleSlug, string $name): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::query()->create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createSchedule(int $maximumScore = 100): ExaminationSchedule
    {
        $bank = QuestionBank::query()->create([
            'school_year' => fake()->unique()->numerify('20######'),
            'title' => 'Manual score test bank '.fake()->unique()->word(),
            'is_active' => true,
        ]);
        $subject = ExamSubject::query()->create([
            'question_bank_id' => $bank->id,
            'name' => 'Mathematics',
            'code' => 'MATH',
        ]);

        for ($index = 1; $index <= $maximumScore; $index++) {
            ExamQuestion::query()->create([
                'exam_subject_id' => $subject->id,
                'stem' => "Question {$index}",
                'question_type' => 'multiple_choice',
                'status' => 'active',
                'is_selected_for_exam' => true,
            ]);
        }

        return ExaminationSchedule::query()->create([
            'title' => 'Mathematics Examination',
            'exam_date' => now()->toDateString(),
            'academic_year' => AcademicYear::current(),
            'start_time' => '08:00:00',
            'end_time' => '11:00:00',
            'time_slot' => 'Morning',
            'batch_code' => 'MANUAL-1',
            'course' => 'General',
            'status' => 'scheduled',
        ]);
    }

    private function createApplicant(string $code, string $name, string $course = 'General'): Applicant
    {
        return Applicant::query()->create([
            'applicant_code' => $code,
            'name' => $name,
            'course_applied' => $course,
        ]);
    }

    private function register(ExaminationSchedule $schedule, Applicant $applicant, array $attributes = []): ExaminationRegistration
    {
        return ExaminationRegistration::query()->create($attributes + [
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => 'pending',
            'result_status' => 'pending',
        ]);
    }
}
