<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\ExaminationStatus;
use App\Enums\ExamSessionStatus;
use App\Models\Applicant;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationRoom;
use App\Models\ExaminationSchedule;
use App\Models\ExamSession;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Services\ExamSessionService;
use App\Services\RoomAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExaminationRoomManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $proctorA;
    protected User $proctorB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $adminRole = Role::where('slug', 'admin')->first();
        $proctorRole = Role::where('slug', 'proctor')->first();

        $this->admin = User::create([
            'role_id' => $adminRole->id,
            'name' => 'Admin User',
            'email' => 'admin@tcc.edu.ph',
            'password' => Hash::make('password123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->proctorA = User::create([
            'role_id' => $proctorRole->id,
            'name' => 'Proctor Alice',
            'email' => 'alice@tcc.edu.ph',
            'password' => Hash::make('password123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->proctorB = User::create([
            'role_id' => $proctorRole->id,
            'name' => 'Proctor Bob',
            'email' => 'bob@tcc.edu.ph',
            'password' => Hash::make('password123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $qb = \App\Models\QuestionBank::create([
            'title' => 'TCC General Entrance Exam',
            'school_year' => '2026-2027',
            'is_active' => true,
        ]);

        $subject = \App\Models\ExamSubject::create([
            'question_bank_id' => $qb->id,
            'name' => 'General Science',
            'code' => 'SCI',
            'is_active' => true,
            'selection_limit' => 10,
        ]);

        \App\Models\ExamQuestion::create([
            'exam_subject_id' => $subject->id,
            'stem' => 'What is H2O?',
            'question_type' => 'multiple_choice',
            'status' => 'active',
            'is_selected_for_exam' => true,
            'selected_at' => now(),
        ]);
    }

    public function test_admin_can_manage_room_catalog(): void
    {
        // 1. Create room
        $createRes = $this->actingAs($this->admin)->postJson('/api/rooms', [
            'name' => 'Room 105',
            'capacity' => 35,
            'is_active' => true,
        ]);
        $createRes->assertCreated()
            ->assertJsonPath('data.name', 'Room 105')
            ->assertJsonPath('data.capacity', 35)
            ->assertJsonPath('data.is_active', true);

        $roomId = $createRes->json('data.id');

        // 2. Prevent duplicate room (case-insensitive)
        $dupRes = $this->actingAs($this->admin)->postJson('/api/rooms', [
            'name' => 'room 105',
            'capacity' => 40,
            'is_active' => true,
        ]);
        $dupRes->assertStatus(422);

        // 3. Update room
        $updateRes = $this->actingAs($this->admin)->putJson("/api/rooms/{$roomId}", [
            'name' => 'Room 105-A',
            'capacity' => 40,
            'is_active' => true,
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.name', 'Room 105-A')
            ->assertJsonPath('data.capacity', 40);

        // 4. Delete room
        $delRes = $this->actingAs($this->admin)->deleteJson("/api/rooms/{$roomId}");
        $delRes->assertOk();

        $this->assertSoftDeleted('rooms', ['id' => $roomId]);
    }

    public function test_room_associated_with_active_examination_cannot_be_deleted(): void
    {
        $room = Room::create(['name' => 'Room 106', 'capacity' => 30, 'is_active' => true]);
        $schedule = ExaminationSchedule::create([
            'title' => 'TCC Entrance Exam',
            'exam_date' => Carbon::now('Asia/Manila')->addDays(3)->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'time_slot' => '8:00 AM - 10:00 AM',
            'status' => ExaminationStatus::Scheduled,
        ]);

        ExaminationRoom::create([
            'examination_schedule_id' => $schedule->id,
            'room_id' => $room->id,
            'room_name' => $room->name,
            'capacity' => $room->capacity,
        ]);

        $res = $this->actingAs($this->admin)->deleteJson("/api/rooms/{$room->id}");
        $res->assertStatus(422)
            ->assertJsonPath('message', 'Remove this room from upcoming schedules and finish active examinations before deleting it.');
    }

    public function test_automatic_student_room_distribution_evenly_balances_students(): void
    {
        $room1 = Room::create(['name' => 'Room 101', 'capacity' => 30, 'is_active' => true]);
        $room2 = Room::create(['name' => 'Room 102', 'capacity' => 30, 'is_active' => true]);
        $room3 = Room::create(['name' => 'Room 103', 'capacity' => 30, 'is_active' => true]);

        $schedule = ExaminationSchedule::create([
            'title' => 'TCC Entrance Exam',
            'exam_date' => Carbon::now('Asia/Manila')->addDays(2)->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'status' => ExaminationStatus::Scheduled,
        ]);

        // Enroll 90 students
        for ($i = 1; $i <= 90; $i++) {
            $applicant = Applicant::create([
                'name' => "Student {$i}",
                'applicant_code' => sprintf('APP-%04d', $i),
                'email' => "student{$i}@example.com",
            ]);
            ExaminationRegistration::create([
                'examination_schedule_id' => $schedule->id,
                'applicant_id' => $applicant->id,
                'attendance_status' => AttendanceStatus::Pending,
            ]);
        }

        // Admin selects the 3 rooms for this schedule
        $res = $this->actingAs($this->admin)->putJson("/api/examination-schedules/{$schedule->id}/room-assignments", [
            'revision' => $schedule->fresh()->rooms_revision,
            'rooms' => [
                ['room_id' => $room1->id, 'proctor_id' => $this->proctorA->id],
                ['room_id' => $room2->id, 'proctor_id' => $this->proctorB->id],
                ['room_id' => $room3->id, 'proctor_id' => null],
            ],
        ]);

        $res->assertOk();
        $rooms = $res->json('data.rooms');
        $this->assertCount(3, $rooms);

        // Exactly 30 students per room (90 / 3)
        foreach ($rooms as $r) {
            $this->assertEquals(30, $r['registrations_count']);
        }
    }

    public function test_insufficient_room_capacity_is_rejected(): void
    {
        $room = Room::create(['name' => 'Small Room', 'capacity' => 10, 'is_active' => true]);
        $schedule = ExaminationSchedule::create([
            'title' => 'TCC Entrance Exam',
            'exam_date' => Carbon::now('Asia/Manila')->addDays(2)->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'status' => ExaminationStatus::Scheduled,
        ]);

        for ($i = 1; $i <= 20; $i++) {
            $applicant = Applicant::create([
                'name' => "Student {$i}",
                'applicant_code' => sprintf('APP-S-%03d', $i),
            ]);
            ExaminationRegistration::create([
                'examination_schedule_id' => $schedule->id,
                'applicant_id' => $applicant->id,
                'attendance_status' => AttendanceStatus::Pending,
            ]);
        }

        $res = $this->actingAs($this->admin)->putJson("/api/examination-schedules/{$schedule->id}/room-assignments", [
            'revision' => $schedule->fresh()->rooms_revision,
            'rooms' => [
                ['room_id' => $room->id, 'proctor_id' => null],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['rooms']);
    }

    public function test_manual_student_placements_are_respected(): void
    {
        $room1 = Room::create(['name' => 'Room 201', 'capacity' => 20, 'is_active' => true]);
        $room2 = Room::create(['name' => 'Room 202', 'capacity' => 20, 'is_active' => true]);

        $schedule = ExaminationSchedule::create([
            'title' => 'TCC Entrance Exam',
            'exam_date' => Carbon::now('Asia/Manila')->addDays(2)->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'status' => ExaminationStatus::Scheduled,
        ]);

        $students = [];
        for ($i = 1; $i <= 10; $i++) {
            $app = Applicant::create(['name' => "Student {$i}", 'applicant_code' => "MAN-{$i}"]);
            $students[] = ExaminationRegistration::create([
                'examination_schedule_id' => $schedule->id,
                'applicant_id' => $app->id,
                'attendance_status' => AttendanceStatus::Pending,
            ]);
        }

        // Set rooms
        $this->actingAs($this->admin)->putJson("/api/examination-schedules/{$schedule->id}/room-assignments", [
            'revision' => $schedule->fresh()->rooms_revision,
            'rooms' => [
                ['room_id' => $room1->id, 'proctor_id' => null],
                ['room_id' => $room2->id, 'proctor_id' => null],
            ],
        ])->assertOk();

        $examRooms = $schedule->rooms()->get();
        $targetRoom = $examRooms->firstWhere('room_id', $room2->id);

        // Manually move student 0 to Room 202
        $res = $this->actingAs($this->admin)->patchJson("/api/examination-schedules/{$schedule->id}/room-assignments/students", [
            'revision' => $schedule->fresh()->rooms_revision,
            'assignments' => [
                ['registration_id' => $students[0]->id, 'room_id' => $targetRoom->id, 'manual' => true],
            ],
        ]);

        $res->assertOk();
        $reg = $students[0]->fresh();
        $this->assertEquals($targetRoom->id, $reg->assigned_room_id);
        $this->assertTrue((bool) $reg->room_assignment_manual);
    }

    public function test_proctor_room_ownership_and_exclusive_locking(): void
    {
        $room1 = Room::create(['name' => 'Room 301', 'capacity' => 25, 'is_active' => true]);
        $room2 = Room::create(['name' => 'Room 302', 'capacity' => 25, 'is_active' => true]);

        $schedule = ExaminationSchedule::create([
            'title' => 'TCC Entrance Exam',
            'exam_date' => Carbon::now('Asia/Manila')->toDateString(),
            'start_time' => Carbon::now('Asia/Manila')->subMinutes(10)->format('H:i:s'),
            'end_time' => Carbon::now('Asia/Manila')->addHours(2)->format('H:i:s'),
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
            'rooms_published_at' => now(),
        ]);

        $examRoom1 = ExaminationRoom::create([
            'examination_schedule_id' => $schedule->id,
            'room_id' => $room1->id,
            'room_name' => $room1->name,
            'capacity' => 25,
            'proctor_id' => $this->proctorA->id,
        ]);

        $examRoom2 = ExaminationRoom::create([
            'examination_schedule_id' => $schedule->id,
            'room_id' => $room2->id,
            'room_name' => $room2->name,
            'capacity' => 25,
            'proctor_id' => null, // Unassigned room
        ]);

        $sessionService = app(ExamSessionService::class);

        // 1. Proctor B cannot open Room 301 (assigned to Proctor A)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Room 301 is assigned to Proctor Alice.');
        $sessionService->openLobby($this->proctorB, $schedule, null, $examRoom1);
    }

    public function test_claimed_room_cannot_be_opened_by_another_proctor(): void
    {
        $room = Room::create(['name' => 'Room 401', 'capacity' => 30, 'is_active' => true]);

        $schedule = ExaminationSchedule::create([
            'title' => 'TCC Entrance Exam',
            'exam_date' => Carbon::now('Asia/Manila')->toDateString(),
            'start_time' => Carbon::now('Asia/Manila')->subMinutes(5)->format('H:i:s'),
            'end_time' => Carbon::now('Asia/Manila')->addHours(2)->format('H:i:s'),
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
            'rooms_published_at' => now(),
        ]);

        $examRoom = ExaminationRoom::create([
            'examination_schedule_id' => $schedule->id,
            'room_id' => $room->id,
            'room_name' => $room->name,
            'capacity' => 30,
            'proctor_id' => null, // Available
        ]);

        $sessionService = app(ExamSessionService::class);

        // Proctor A claims the unassigned room
        $sessionA = $sessionService->openLobby($this->proctorA, $schedule, null, $examRoom);
        $this->assertNotNull($sessionA);
        $this->assertEquals($this->proctorA->id, $sessionA->proctor_id);

        // Proctor B attempts to open Room 401 while Proctor A manages it
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Room 401 is currently managed by Proctor Alice.');
        $sessionService->openLobby($this->proctorB, $schedule, null, $examRoom);
    }

    public function test_admin_recovery_can_transfer_or_release_room_ownership(): void
    {
        $room = Room::create(['name' => 'Room 501', 'capacity' => 30, 'is_active' => true]);

        $schedule = ExaminationSchedule::create([
            'title' => 'TCC Entrance Exam',
            'exam_date' => Carbon::now('Asia/Manila')->toDateString(),
            'start_time' => Carbon::now('Asia/Manila')->subMinutes(5)->format('H:i:s'),
            'end_time' => Carbon::now('Asia/Manila')->addHours(2)->format('H:i:s'),
            'status' => ExaminationStatus::Scheduled,
            'rooms_managed' => true,
            'rooms_published_at' => now(),
        ]);

        $examRoom = ExaminationRoom::create([
            'examination_schedule_id' => $schedule->id,
            'room_id' => $room->id,
            'room_name' => $room->name,
            'capacity' => 30,
            'proctor_id' => null,
        ]);

        $sessionService = app(ExamSessionService::class);
        $session = $sessionService->openLobby($this->proctorA, $schedule, null, $examRoom);
        $this->assertEquals($this->proctorA->id, $session->proctor_id);

        // Admin transfers room to Proctor B
        $res = $this->actingAs($this->admin)->postJson("/api/examination-rooms/{$examRoom->id}/recover", [
            'proctor_id' => $this->proctorB->id,
            'reason' => 'Proctor Alice had an emergency; transferring to Proctor Bob.',
        ]);

        $res->assertOk()
            ->assertJsonPath('message', 'Room ownership updated.');

        $this->assertEquals($this->proctorB->id, $session->fresh()->proctor_id);
        $this->assertEquals($this->proctorB->id, $examRoom->fresh()->proctor_id);
    }
}
