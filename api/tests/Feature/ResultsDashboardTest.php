<?php

namespace Tests\Feature;

use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use App\Models\Applicant;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\Role;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResultsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_admin_gets_database_aggregates_scoped_by_academic_year_and_date(): void
    {
        $admin = $this->createAdmin();
        $schedule = $this->createSchedule(AcademicYear::current(), '2026-08-10', 'MORNING');
        $this->createResult($schedule, 'TCC-001', 'Juan Applicant', ResultStatus::Passed, 86);
        $this->createResult($schedule, 'TCC-002', 'Ana Applicant', ResultStatus::Failed, 58);

        $response = $this->actingAs($admin)->getJson('/api/results?year='.AcademicYear::current().'&date=2026-08-10');

        $response->assertOk()
            ->assertJsonPath('stats.examDays', 1)
            ->assertJsonPath('stats.batches', 1)
            ->assertJsonPath('stats.examinees', 2)
            ->assertJsonPath('stats.passed', 1)
            ->assertJsonPath('stats.failed', 1)
            ->assertJsonPath('stats.sendable', 2)
            ->assertJsonPath('dates.0.sendableCount', 2)
            ->assertJsonPath('batches.0.batch', 'MORNING')
            ->assertJsonPath('batches.0.total', 2)
            ->assertJsonPath('applicants.0.name', 'Ana Applicant');
    }

    public function test_applicant_search_is_limited_to_six_database_results(): void
    {
        $admin = $this->createAdmin();
        $schedule = $this->createSchedule(AcademicYear::current(), '2026-08-10', 'MORNING');
        for ($index = 1; $index <= 7; $index++) {
            $this->createResult(
                $schedule,
                sprintf('TCC-%03d', $index),
                'Juan Applicant '.$index,
                ResultStatus::Passed,
                80 + $index
            );
        }

        $this->actingAs($admin)
            ->getJson('/api/results/search?q=Juan')
            ->assertOk()
            ->assertJsonCount(6);
    }

    public function test_applicant_detail_stays_with_the_requested_academic_year(): void
    {
        $admin = $this->createAdmin();
        $applicant = Applicant::query()->create([
            'applicant_code' => 'TCC-MULTI-YEAR',
            'name' => 'Multi Year Applicant',
            'email' => 'multi.year@example.test',
        ]);
        $currentSchedule = $this->createSchedule(AcademicYear::current(), '2026-08-10', 'CURRENT');
        $laterSchedule = $this->createSchedule(AcademicYear::current(), '2026-11-10', 'LATER');
        $currentYearStart = (int) explode('-', AcademicYear::current())[0];
        $archivedYear = ($currentYearStart - 1).'-'.$currentYearStart;
        $archivedSchedule = $this->createSchedule($archivedYear, ($currentYearStart - 1).'-09-10', 'ARCHIVE');

        foreach ([$currentSchedule, $laterSchedule, $archivedSchedule] as $schedule) {
            ExaminationRegistration::query()->create([
                'examination_schedule_id' => $schedule->id,
                'applicant_id' => $applicant->id,
                'result_status' => ResultStatus::Passed,
                'score' => 88,
                'email_sent' => false,
                'email_status' => ResultEmailStatus::Pending,
            ]);
        }

        $this->actingAs($admin)
            ->getJson('/api/results/'.$applicant->id.'?year='.AcademicYear::current().'&schedule_id='.$currentSchedule->id)
            ->assertOk()
            ->assertJsonPath('batch', 'CURRENT');

        $this->actingAs($admin)
            ->getJson('/api/results/'.$applicant->id.'?year='.$archivedYear)
            ->assertOk()
            ->assertJsonPath('academicYear', $archivedYear)
            ->assertJsonPath('batch', 'ARCHIVE');
    }

    public function test_archived_academic_year_rejects_bulk_notifications(): void
    {
        $admin = $this->createAdmin();
        $startYear = (int) explode('-', AcademicYear::current())[0] - 1;
        $archivedYear = $startYear.'-'.($startYear + 1);
        $schedule = $this->createSchedule($archivedYear, $startYear.'-09-10', 'ARCHIVE');
        $this->createResult($schedule, 'TCC-ARCHIVE', 'Archived Applicant', ResultStatus::Passed, 84);

        $this->actingAs($admin)
            ->postJson('/api/results/send', ['year' => $archivedYear, 'mode' => 'all'])
            ->assertForbidden()
            ->assertJsonPath('error', 'Archived academic years are read-only.');
    }

    public function test_bulk_notifications_only_queue_each_unsent_result_once(): void
    {
        $admin = $this->createAdmin();
        $schedule = $this->createSchedule(AcademicYear::current(), '2026-08-10', 'MORNING');
        $registration = $this->createResult($schedule, 'TCC-SEND-1', 'Send Applicant', ResultStatus::Passed, 90);

        $first = $this->actingAs($admin)->postJson('/api/results/send', [
            'year' => AcademicYear::current(),
            'mode' => 'all',
        ]);
        $first->assertOk()->assertJsonPath('count', 1);

        $this->postJson('/api/results/send', [
            'year' => AcademicYear::current(),
            'mode' => 'all',
        ])->assertOk()->assertJsonPath('count', 0);

        $this->assertSame(ResultEmailStatus::Sent, $registration->fresh()->email_status);
        $this->assertNotNull($registration->fresh()->email_sent_at);
    }

    private function createAdmin(): User
    {
        $role = Role::query()->where('slug', 'admin')->firstOrFail();

        return User::query()->create([
            'role_id' => $role->id,
            'name' => 'Results Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createSchedule(string $year, string $date, string $batch): ExaminationSchedule
    {
        return ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam',
            'exam_date' => $date,
            'academic_year' => $year,
            'start_time' => '08:00:00',
            'end_time' => '11:00:00',
            'time_slot' => 'Morning',
            'batch_code' => $batch,
            'status' => 'scheduled',
        ]);
    }

    private function createResult(
        ExaminationSchedule $schedule,
        string $code,
        string $name,
        ResultStatus $status,
        int $score,
    ): ExaminationRegistration {
        $applicant = Applicant::query()->create([
            'applicant_code' => $code,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        return ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'result_status' => $status,
            'score' => $score,
            'email_sent' => false,
            'email_status' => ResultEmailStatus::Pending,
        ]);
    }
}