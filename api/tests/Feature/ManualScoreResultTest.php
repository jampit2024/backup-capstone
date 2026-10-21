<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use App\Mail\ExaminationResultMail;
use App\Models\Applicant;
use App\Models\CourseGradingSetting;
use App\Models\EmailConfiguration;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\Role;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ManualScoreResultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $bank = \App\Models\QuestionBank::query()->create([
            'school_year' => '20262027',
            'title' => 'Test Question Bank',
            'is_active' => true,
        ]);
        $subject = \App\Models\ExamSubject::query()->create([
            'question_bank_id' => $bank->id,
            'name' => 'General Information',
            'code' => 'GEN-INFO',
        ]);
        for ($i = 1; $i <= 100; $i++) {
            \App\Models\ExamQuestion::query()->create([
                'exam_subject_id' => $subject->id,
                'stem' => "Test Question {$i}",
                'question_type' => 'multiple_choice',
                'status' => 'active',
                'is_selected_for_exam' => true,
            ]);
        }
    }

    public function test_manually_scored_student_appears_in_examination_results_with_all_required_fields(): void
    {
        $admin = $this->createAdminUser();

        $schedule = ExaminationSchedule::query()->create([
            'title' => 'College Entrance Examination 2026',
            'course' => 'BS Information Technology',
            'venue' => 'Main Gymnasium - Station 4',
            'exam_date' => '2026-08-20',
            'academic_year' => null, // Explicitly NULL to test auto-healing & dynamic resolution
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'time_slot' => '08:00 AM - 10:00 AM',
            'batch_code' => 'Batch 1',
            'status' => 'scheduled',
            'expected_examinees' => 50,
        ]);

        $applicant = Applicant::query()->create([
            'applicant_code' => 'TCC-2026-9001',
            'name' => 'Maria Santos Dela Cruz',
            'course_applied' => 'BS Information Technology',
            'email' => 'maria.delacruz@example.com',
            'gmail' => 'maria.delacruz@gmail.com',
        ]);

        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => AttendanceStatus::Pending,
            'result_status' => ResultStatus::Pending,
        ]);

        CourseGradingSetting::query()->create([
            'course_code' => 'BS Information Technology',
            'course_name' => 'Bachelor of Science in Information Technology',
            'passing_percentage' => 75,
            'failing_grade_point' => 5.0,
            'is_active' => true,
        ]);

        // Step 1: Admin records manual paper-based score of 82/100
        $response = $this->actingAs($admin)->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'score' => 82,
            'paper_based_confirmed' => true,
            'remarks' => 'Clean paper exam, verified by Guidance proctor.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.score', 82)
            ->assertJsonPath('data.maximum_score', 100)
            ->assertJsonPath('data.examination_method', 'manual');

        // Check registration updated
        $registration->refresh();
        $this->assertSame('manual', $registration->examination_method);
        $this->assertEquals(82.0, (float) $registration->manual_score);

        // Academic year was healed on schedule
        $schedule->refresh();
        $this->assertSame(AcademicYear::forDate('2026-08-20'), $schedule->academic_year);

        // Step 2: Verify the result appears in Examination Results API
        $year = AcademicYear::forDate('2026-08-20');
        $resultsResponse = $this->actingAs($admin)->getJson("/api/results?year={$year}&method=all");
        $resultsResponse->assertOk()
            ->assertJsonPath('stats.examinees', 1);

        $row = collect($resultsResponse->json('applicants'))->firstWhere('applicantCode', 'TCC-2026-9001');
        $this->assertNotNull($row, 'Manually scored student must appear in examination results');
        $this->assertSame('Maria Santos Dela Cruz', $row['name']);
        $this->assertSame('TCC-2026-9001', $row['applicantCode']);
        $this->assertSame('BS Information Technology', $row['program']);
        $this->assertSame('College Entrance Examination 2026', $row['examTitle']);
        $this->assertSame('manual', $row['examinationMethod']);
        $this->assertSame('passed', $row['status']);
        $this->assertStringContainsString('82', $row['displayScore']);

        // Step 3: Verify single applicant lookup by applicant ID or registration ID
        $detailByApplicantId = $this->actingAs($admin)->getJson("/api/results/{$applicant->id}?year={$year}");
        $detailByApplicantId->assertOk()
            ->assertJsonPath('name', 'Maria Santos Dela Cruz')
            ->assertJsonPath('program', 'BS Information Technology');

        $detailByRegId = $this->actingAs($admin)->getJson("/api/results/{$registration->id}?year={$year}");
        $detailByRegId->assertOk()
            ->assertJsonPath('applicantCode', 'TCC-2026-9001');

        // Step 4: Verify search finds the manual student
        $searchResponse = $this->actingAs($admin)->getJson('/api/results/search?q=Maria');
        $searchResponse->assertOk()
            ->assertJsonPath('0.applicantCode', 'TCC-2026-9001')
            ->assertJsonPath('0.examinationMethod', 'manual');
    }

    public function test_send_result_via_email_for_manual_examination_result(): void
    {
        Mail::fake();

        $admin = $this->createAdminUser();
        $schedule = ExaminationSchedule::query()->create([
            'title' => 'TCC Entrance Examination',
            'course' => 'BS Computer Science',
            'exam_date' => now()->toDateString(),
            'academic_year' => AcademicYear::current(),
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'time_slot' => '10:00 AM - 12:00 PM',
            'batch_code' => 'Batch 2',
            'status' => 'scheduled',
        ]);

        $applicant = Applicant::query()->create([
            'applicant_code' => 'TCC-MANUAL-EMAIL',
            'name' => 'Roberto Garcia',
            'course_applied' => 'BS Computer Science',
            'email' => 'roberto.garcia@example.com',
            'gmail' => 'roberto.garcia@gmail.com',
        ]);

        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'examination_method' => 'manual',
            'manual_score' => 88.0,
            'manual_max_score' => 100.0,
            'score' => 88.0,
            'items_correct' => 88,
            'items_total' => 100,
            'result_status' => ResultStatus::Passed,
            'attendance_status' => AttendanceStatus::Present,
            'email_status' => ResultEmailStatus::Pending,
        ]);

        // Send applicant result using the API
        $response = $this->actingAs($admin)->postJson("/api/results/{$applicant->id}/send?year=".AcademicYear::current()."&schedule_id={$schedule->id}");
        $response->assertOk()
            ->assertJsonPath('count', 1);

        // Also test sending using registration ID
        $registration->update(['email_status' => ResultEmailStatus::Pending]);
        $responseReg = $this->actingAs($admin)->postJson("/api/results/{$registration->id}/send?year=".AcademicYear::current()."&schedule_id={$schedule->id}");
        $responseReg->assertOk()
            ->assertJsonPath('count', 1);

        // Verify ExaminationResultMail build
        $mailable = new ExaminationResultMail($registration->fresh(['applicant', 'schedule']));
        $mailable->assertHasSubject('TCC Examination Result: TCC Entrance Examination');
        $content = $mailable->content();
        $this->assertSame('Roberto Garcia', $content->with['name']);
        $this->assertSame('BS Computer Science', $content->with['program']);
        $this->assertSame('TCC Entrance Examination', $content->with['examTitle']);
        $this->assertEquals(88.0, $content->with['score']);
        $this->assertEquals(88.0, $content->with['percentage']);
        $this->assertSame('Passed', $content->with['remarks']);
    }

    public function test_online_examination_results_remain_unaffected(): void
    {
        $admin = $this->createAdminUser();
        $schedule = ExaminationSchedule::query()->create([
            'title' => 'Online Entrance Exam',
            'course' => 'General Examination',
            'exam_date' => now()->toDateString(),
            'academic_year' => AcademicYear::current(),
            'start_time' => '13:00:00',
            'end_time' => '15:00:00',
            'time_slot' => '01:00 PM - 03:00 PM',
            'status' => 'completed',
        ]);

        $onlineApplicant = Applicant::query()->create([
            'applicant_code' => 'TCC-ONLINE-01',
            'name' => 'Online Student',
            'email' => 'online@example.test',
        ]);

        ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $onlineApplicant->id,
            'examination_method' => 'online',
            'score' => 90.0,
            'items_correct' => 45,
            'items_total' => 50,
            'result_status' => ResultStatus::Passed,
            'attendance_status' => AttendanceStatus::Present,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/results?year='.AcademicYear::current().'&method=online');
        $response->assertOk()
            ->assertJsonPath('stats.examinees', 1)
            ->assertJsonPath('applicants.0.name', 'Online Student')
            ->assertJsonPath('applicants.0.examinationMethod', 'online')
            ->assertJsonPath('applicants.0.displayScore', '45/50')
            ->assertJsonPath('applicants.0.percentage', '90%');
    }

    public function test_valid_score_of_zero_is_allowed_and_marks_failed(): void
    {
        $admin = $this->createAdminUser();
        $schedule = ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam',
            'course' => 'General',
            'exam_date' => now()->toDateString(),
            'academic_year' => AcademicYear::current(),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'time_slot' => '08:00 AM - 10:00 AM',
            'status' => 'scheduled',
        ]);

        $applicant = Applicant::query()->create([
            'applicant_code' => 'TCC-ZERO-SCORE',
            'name' => 'Zero Examinee',
            'email' => 'zero@example.test',
        ]);

        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'result_status' => ResultStatus::Pending,
        ]);

        $this->actingAs($admin)->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'score' => 0,
            'paper_based_confirmed' => true,
            'remarks' => 'Applicant turned in blank paper.',
        ])->assertCreated()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.percentage', 0)
            ->assertJsonPath('data.result_status', 'failed');

        $registration->refresh();
        $this->assertSame(0.0, (float) $registration->manual_score);
        $this->assertSame(0.0, (float) $registration->score);
        $this->assertSame(ResultStatus::Failed, $registration->result_status);
    }

    public function test_repeated_saving_updates_existing_record_without_duplicates(): void
    {
        $admin = $this->createAdminUser();
        $schedule = ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam',
            'course' => 'General',
            'exam_date' => now()->toDateString(),
            'academic_year' => AcademicYear::current(),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'time_slot' => '08:00 AM - 10:00 AM',
            'status' => 'scheduled',
        ]);

        $applicant = Applicant::query()->create([
            'applicant_code' => 'TCC-DUP-CHECK',
            'name' => 'Duplicate Check Student',
            'email' => 'dup@example.test',
        ]);

        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'result_status' => ResultStatus::Pending,
        ]);

        // First save creates manual result
        $this->actingAs($admin)->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'score' => 70,
            'paper_based_confirmed' => true,
        ])->assertCreated();

        $this->assertSame(1, ExaminationRegistration::query()->where('applicant_id', $applicant->id)->count());

        // Attempting to create again via store gives 409 conflict instructing to update
        $this->actingAs($admin)->postJson('/api/exam-results/manual-entry', [
            'schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'score' => 75,
            'paper_based_confirmed' => true,
        ])->assertStatus(409);

        // Correcting score via PUT updates the existing record
        $this->actingAs($admin)->putJson("/api/exam-results/manual-entry/{$registration->id}", [
            'score' => 78,
            'paper_based_confirmed' => true,
            'reason' => 'Recount of essay score items',
        ])->assertOk()
            ->assertJsonPath('data.score', 78);

        // Still exactly 1 registration
        $this->assertSame(1, ExaminationRegistration::query()->where('applicant_id', $applicant->id)->count());
        $registration->refresh();
        $this->assertEquals(78.0, (float) $registration->manual_score);
    }

    public function test_unauthorized_users_cannot_access_examination_results(): void
    {
        $this->getJson('/api/results?year='.AcademicYear::current())
            ->assertUnauthorized();

        $proctorRole = Role::query()->where('slug', Role::SLUG_PROCTOR)->firstOrFail();
        $proctor = User::query()->create([
            'name' => 'Proctor User',
            'email' => 'proctor@masterexam.test',
            'password' => Hash::make('Secret123!'),
            'role_id' => $proctorRole->id,
            'is_active' => true,
        ]);

        $this->actingAs($proctor)->getJson('/api/results?year='.AcademicYear::current())
            ->assertForbidden();
    }

    private function createAdminUser(): User
    {
        $adminRole = Role::query()->where('slug', Role::SLUG_ADMIN)->firstOrFail();
        return User::query()->create([
            'name' => 'System Administrator',
            'email' => 'admin@masterexam.test',
            'password' => Hash::make('Secret123!'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);
    }
}
