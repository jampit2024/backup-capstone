<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\Role;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CurrentAcademicYearProctorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 12:00:00');
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_proctor_schedule_list_only_contains_the_current_academic_year(): void
    {
        $proctor = $this->createProctor();
        $current = $this->createSchedule('2026-09-10');
        $this->createSchedule('2025-06-10'); // 2024-2025
        $this->createSchedule('2026-06-10'); // 2025-2026
        $this->createSchedule('2028-09-10'); // 2028-2029

        $this->actingAs($proctor)
            ->getJson('/api/v1/proctor/schedules?per_page=200')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $current->id)
            ->assertJsonPath('meta.today', '2026-10-03')
            ->assertJsonPath('meta.server_now', '2026-10-03T12:00:00+08:00');
    }

    public function test_schedule_screen_can_request_read_only_history_outside_current_academic_year(): void
    {
        $proctor = $this->createProctor();
        $current = $this->createSchedule('2026-09-10');
        $archived = $this->createSchedule('2025-06-10');

        $this->actingAs($proctor)
            ->getJson('/api/v1/proctor/schedules?per_page=200&include_history=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $archived->id)
            ->assertJsonPath('data.1.id', $current->id);
    }

    public function test_proctor_direct_schedule_request_and_old_pack_date_return_403(): void
    {
        $proctor = $this->createProctor();
        $archived = $this->createSchedule('2025-06-10');
        $previousArchived = $this->createSchedule('2026-06-10');
        $upcoming = $this->createSchedule('2028-09-10');
        config(['services.admin_sync.token' => 'test-admin-sync-token']);

        $this->actingAs($proctor)
            ->getJson("/api/v1/proctor/schedules/{$archived->id}/rooms")
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->actingAs($proctor)
            ->getJson("/api/v1/examination-schedules/{$archived->id}")
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->actingAs($proctor)
            ->getJson("/api/v1/proctor/schedules/{$upcoming->id}/rooms")
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->actingAs($proctor)
            ->getJson("/api/v1/proctor/schedules/{$previousArchived->id}/rooms")
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack?exam_date=2025-06-10')
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack?schedule_id='.$archived->id)
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack?schedule_id='.$previousArchived->id)
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack-status?schedule_id='.$archived->id)
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack?schedule_id='.$upcoming->id)
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);

        $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack-status?exam_date=2028-09-10')
            ->assertForbidden()
            ->assertJsonPath('message', AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE);
    }

    public function test_exam_pack_only_contains_current_year_students_and_passkey_hashes(): void
    {
        config(['services.admin_sync.token' => 'test-admin-sync-token']);
        $current = $this->createSchedule('2026-09-10');
        $archived = $this->createSchedule('2025-06-10');
        $upcoming = $this->createSchedule('2028-09-10');

        $currentApplicant = $this->createApplicant('CURRENT');
        $archivedApplicant = $this->createApplicant('ARCHIVED');
        $upcomingApplicant = $this->createApplicant('UPCOMING');
        $currentPasskeyHash = hash('sha256', 'CURRENT-KEY');
        $archivedPasskeyHash = hash('sha256', 'ARCHIVED-KEY');
        $upcomingPasskeyHash = hash('sha256', 'UPCOMING-KEY');

        $this->createRegistration($current, $currentApplicant, $currentPasskeyHash);
        $this->createRegistration($archived, $archivedApplicant, $archivedPasskeyHash);
        $this->createRegistration($upcoming, $upcomingApplicant, $upcomingPasskeyHash);

        $response = $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack')
            ->assertOk()
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.id', $current->id)
            ->assertJsonCount(1, 'data.registrations')
            ->assertJsonCount(1, 'data.applicants');

        $this->assertSame($currentPasskeyHash, $response->json('data.registrations.0.exam_passkey_hash'));
        $this->assertStringNotContainsString($archivedPasskeyHash, $response->getContent());
        $this->assertStringNotContainsString($upcomingPasskeyHash, $response->getContent());
        $this->assertStringNotContainsString('ARCHIVED', $response->getContent());
        $this->assertStringNotContainsString('UPCOMING', $response->getContent());

        $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack-status?exam_date=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.schedule_count', 1);
    }

    public function test_pack_with_no_current_year_schedules_contains_no_archived_students_or_credentials(): void
    {
        config(['services.admin_sync.token' => 'test-admin-sync-token']);
        $archived = $this->createSchedule('2025-06-10');
        $upcoming = $this->createSchedule('2028-09-10');
        $archivedApplicant = $this->createApplicant('ONLY-ARCHIVED');
        $upcomingApplicant = $this->createApplicant('ONLY-UPCOMING');
        $this->createRegistration($archived, $archivedApplicant, hash('sha256', 'ARCHIVE-KEY'));
        $this->createRegistration($upcoming, $upcomingApplicant, hash('sha256', 'UPCOMING-KEY'));

        $response = $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack')
            ->assertOk()
            ->assertJsonCount(0, 'data.schedules')
            ->assertJsonCount(0, 'data.registrations')
            ->assertJsonCount(0, 'data.applicants');

        $this->assertStringNotContainsString(hash('sha256', 'ARCHIVE-KEY'), $response->getContent());
        $this->assertStringNotContainsString(hash('sha256', 'UPCOMING-KEY'), $response->getContent());
    }

    public function test_schedule_becomes_downloadable_when_its_academic_year_is_current(): void
    {
        $proctor = $this->createProctor();
        $schedule = $this->createSchedule('2028-09-10');

        Carbon::setTestNow('2028-09-01 12:00:00');

        $this->assertSame('2028-2029', AcademicYear::current());
        $this->actingAs($proctor)
            ->getJson('/api/v1/proctor/schedules?per_page=200')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $schedule->id);

        config(['services.admin_sync.token' => 'test-admin-sync-token']);
        $this->withToken('test-admin-sync-token')
            ->getJson('/api/v1/sync/exam-day-pack?schedule_id='.$schedule->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.id', $schedule->id);
    }

    public function test_admin_schedule_list_still_includes_archived_and_upcoming_years(): void
    {
        $adminRole = Role::query()->where('slug', Role::SLUG_ADMIN)->firstOrFail();
        $admin = User::query()->create([
            'role_id' => $adminRole->id,
            'name' => 'Schedule Admin',
            'email' => 'schedule-admin@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->createSchedule('2025-06-10');
        $this->createSchedule('2026-06-10');
        $this->createSchedule('2026-09-10');
        $this->createSchedule('2028-09-10');

        $this->actingAs($admin)
            ->getJson('/api/examination-schedules?per_page=200')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    private function createProctor(): User
    {
        $role = Role::query()->where('slug', Role::SLUG_PROCTOR)->firstOrFail();

        return User::query()->create([
            'role_id' => $role->id,
            'name' => 'Year Scope Proctor',
            'email' => 'proctor-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createSchedule(string $examDate): ExaminationSchedule
    {
        return ExaminationSchedule::query()->create([
            'title' => 'Entrance Examination',
            'exam_date' => $examDate,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'status' => 'scheduled',
        ]);
    }

    private function createApplicant(string $suffix): Applicant
    {
        return Applicant::query()->create([
            'applicant_code' => 'APP-'.$suffix.'-'.uniqid(),
            'name' => $suffix.' Applicant',
            'email' => strtolower($suffix).'@example.test',
        ]);
    }

    private function createRegistration(
        ExaminationSchedule $schedule,
        Applicant $applicant,
        string $passkeyHash,
    ): ExaminationRegistration {
        return ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => 'pending',
            'result_status' => 'pending',
            'exam_passkey_hash' => $passkeyHash,
            'passkey_status' => 'not_sent',
        ]);
    }
}
