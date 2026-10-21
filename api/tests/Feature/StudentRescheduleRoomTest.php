<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\ExaminationStatus;
use App\Enums\ResultStatus;
use App\Models\Applicant;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationRoom;
use App\Models\ExaminationSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\ExaminationPasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentRescheduleRoomTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-10 07:00:00', 'Asia/Manila'));

        $adminRole = Role::query()->firstOrCreate(
            ['slug' => Role::SLUG_ADMIN],
            ['name' => 'Administrator']
        );

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_reschedule_student_to_specific_room_in_new_schedule(): void
    {
        // 1. Create source schedule (Oct 15, Batch 1) with Room 105
        $sourceSchedule = ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam Day 1',
            'exam_date' => '2026-10-15',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'time_slot' => '08:00 - 09:00',
            'batch_code' => 'Batch 1',
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
        ]);

        $sourceRoom = ExaminationRoom::query()->create([
            'examination_schedule_id' => $sourceSchedule->id,
            'room_name' => 'Room 105',
            'capacity' => 30,
        ]);

        // 2. Create target schedule (Oct 16, Batch 2) with Room 106 and Room 107
        $targetSchedule = ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam Day 2',
            'exam_date' => '2026-10-16',
            'start_time' => '09:30:00',
            'end_time' => '10:30:00',
            'time_slot' => '09:30 - 10:30',
            'batch_code' => 'Batch 2',
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
        ]);

        $targetRoom106 = ExaminationRoom::query()->create([
            'examination_schedule_id' => $targetSchedule->id,
            'room_name' => 'Room 106',
            'capacity' => 30,
        ]);

        $targetRoom107 = ExaminationRoom::query()->create([
            'examination_schedule_id' => $targetSchedule->id,
            'room_name' => 'Room 107',
            'capacity' => 30,
        ]);

        // 3. Create student and registration in source schedule Room 105
        $applicant = Applicant::query()->create([
            'name' => 'Juan Dela Cruz',
            'applicant_code' => 'APP-2026-001',
            'email' => 'juan@example.com',
        ]);

        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $sourceSchedule->id,
            'assigned_room_id' => $sourceRoom->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => AttendanceStatus::Pending,
            'result_status' => ResultStatus::Pending,
            'passkey_status' => 'not_sent',
        ]);

        app(ExaminationPasskeyService::class)->generateForSchedule($sourceSchedule);
        $registration->refresh();
        $originalHash = $registration->exam_passkey_hash;
        $originalEncrypted = $registration->exam_passkey_encrypted;
        $this->assertNotNull($originalHash);

        // 4. Perform reschedule to target schedule and target Room 107
        $response = $this->actingAs($this->admin)->postJson(
            "/api/examination-registrations/{$registration->id}/reschedule",
            [
                'target_schedule_id' => $targetSchedule->id,
                'target_room_id' => $targetRoom107->id,
                'reason' => 'Family emergency on original test date',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.from_schedule_id', $sourceSchedule->id)
            ->assertJsonPath('data.to_schedule_id', $targetSchedule->id)
            ->assertJsonPath('data.to_room_id', $targetRoom107->id)
            ->assertJsonPath('data.to_room_name', 'Room 107');

        // 5. Verify database state
        $registration->refresh();
        $this->assertSame($targetSchedule->id, $registration->examination_schedule_id);
        $this->assertSame($sourceSchedule->id, $registration->previous_schedule_id);
        $this->assertSame($targetRoom107->id, $registration->assigned_room_id);
        $this->assertTrue((bool) $registration->room_assignment_manual);
        $this->assertSame('Family emergency on original test date', $registration->reschedule_reason);

        // Passkey is preserved
        $this->assertSame($originalHash, $registration->exam_passkey_hash);
        $this->assertSame($originalEncrypted, $registration->exam_passkey_encrypted);

        // Disappears from October 15 Room 105
        $this->assertDatabaseMissing('exam_registrations', [
            'id' => $registration->id,
            'examination_schedule_id' => $sourceSchedule->id,
        ]);
        $this->assertDatabaseMissing('exam_registrations', [
            'id' => $registration->id,
            'assigned_room_id' => $sourceRoom->id,
        ]);

        // Appears in October 16 Room 107
        $this->assertDatabaseHas('exam_registrations', [
            'id' => $registration->id,
            'examination_schedule_id' => $targetSchedule->id,
            'assigned_room_id' => $targetRoom107->id,
        ]);
    }

    public function test_reschedule_rejects_full_room_with_validation_error(): void
    {
        $sourceSchedule = ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam Day 1',
            'exam_date' => '2026-10-15',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'time_slot' => '08:00 - 09:00',
            'batch_code' => 'Batch 1',
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
        ]);

        $sourceRoom = ExaminationRoom::query()->create([
            'examination_schedule_id' => $sourceSchedule->id,
            'room_name' => 'Room 105',
            'capacity' => 30,
        ]);

        $targetSchedule = ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam Day 2',
            'exam_date' => '2026-10-16',
            'start_time' => '09:30:00',
            'end_time' => '10:30:00',
            'time_slot' => '09:30 - 10:30',
            'batch_code' => 'Batch 2',
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
        ]);

        // Target room with capacity 1
        $fullRoom = ExaminationRoom::query()->create([
            'examination_schedule_id' => $targetSchedule->id,
            'room_name' => 'Room 107',
            'capacity' => 1,
        ]);

        // Fill the target room
        $otherApplicant = Applicant::query()->create([
            'name' => 'Existing Student',
            'applicant_code' => 'APP-EXISTING-001',
            'email' => 'existing@example.com',
        ]);
        ExaminationRegistration::query()->create([
            'examination_schedule_id' => $targetSchedule->id,
            'assigned_room_id' => $fullRoom->id,
            'applicant_id' => $otherApplicant->id,
            'attendance_status' => AttendanceStatus::Pending,
            'result_status' => ResultStatus::Pending,
        ]);

        $applicant = Applicant::query()->create([
            'name' => 'Moving Student',
            'applicant_code' => 'APP-MOVING-001',
            'email' => 'moving@example.com',
        ]);
        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $sourceSchedule->id,
            'assigned_room_id' => $sourceRoom->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => AttendanceStatus::Pending,
            'result_status' => ResultStatus::Pending,
        ]);

        // Attempting to reschedule to the full room should fail
        $response = $this->actingAs($this->admin)->postJson(
            "/api/examination-registrations/{$registration->id}/reschedule",
            [
                'target_schedule_id' => $targetSchedule->id,
                'target_room_id' => $fullRoom->id,
                'reason' => 'Request to move',
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['target_room_id']);

        // Examinee still remains in original schedule and room
        $this->assertSame($sourceSchedule->id, $registration->fresh()->examination_schedule_id);
        $this->assertSame($sourceRoom->id, $registration->fresh()->assigned_room_id);
    }

    public function test_reschedule_rejects_room_not_belonging_to_target_schedule(): void
    {
        $sourceSchedule = ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam Day 1',
            'exam_date' => '2026-10-15',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'time_slot' => '08:00 - 09:00',
            'batch_code' => 'Batch 1',
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
        ]);

        $sourceRoom = ExaminationRoom::query()->create([
            'examination_schedule_id' => $sourceSchedule->id,
            'room_name' => 'Room 105',
            'capacity' => 30,
        ]);

        $targetSchedule = ExaminationSchedule::query()->create([
            'title' => 'Entrance Exam Day 2',
            'exam_date' => '2026-10-16',
            'start_time' => '09:30:00',
            'end_time' => '10:30:00',
            'time_slot' => '09:30 - 10:30',
            'batch_code' => 'Batch 2',
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
        ]);

        ExaminationRoom::query()->create([
            'examination_schedule_id' => $targetSchedule->id,
            'room_name' => 'Room 106',
            'capacity' => 30,
        ]);

        $unrelatedSchedule = ExaminationSchedule::query()->create([
            'title' => 'Unrelated Exam',
            'exam_date' => '2026-10-20',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'time_slot' => '09:00 - 10:00',
            'batch_code' => 'Batch X',
            'status' => ExaminationStatus::Scheduled,
        ]);

        $unrelatedRoom = ExaminationRoom::query()->create([
            'examination_schedule_id' => $unrelatedSchedule->id,
            'room_name' => 'Room 999',
            'capacity' => 30,
        ]);

        $applicant = Applicant::query()->create([
            'name' => 'Test Applicant',
            'applicant_code' => 'APP-TEST-001',
            'email' => 'test@example.com',
        ]);
        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $sourceSchedule->id,
            'assigned_room_id' => $sourceRoom->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => AttendanceStatus::Pending,
            'result_status' => ResultStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            "/api/examination-registrations/{$registration->id}/reschedule",
            [
                'target_schedule_id' => $targetSchedule->id,
                'target_room_id' => $unrelatedRoom->id,
                'reason' => 'Request to move',
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['target_room_id']);
    }
}
