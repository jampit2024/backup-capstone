<?php

namespace Tests\Feature;

use App\Enums\ExamSessionStatus;
use App\Models\Applicant;
use App\Models\ExamSession;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationRoom;
use App\Models\ExaminationSchedule;
use App\Models\ExaminationSetting;
use App\Models\QuestionBank;
use App\Models\Role;
use App\Models\User;
use App\Services\ExamSessionService;
use App\Services\ExaminationPasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExamLobbyConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        Carbon::setTestNow(Carbon::now('Asia/Manila')->setTime(10, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_passkey_join_reserves_key_until_agreement_then_appears_in_lobby(): void
    {
        [$schedule, $applicant, $registration] = $this->registration();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $session = $this->createSession($schedule);

        $joined = $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
        ])->assertOk()
            ->assertJsonCount(0, 'data.lobby.students');

        $token = $joined->json('data.participation_token');
        $reserved = $registration->fresh();
        $this->assertSame('used', $reserved->passkey_status);
        $this->assertSame($session->id, $reserved->exam_session_id);
        $this->assertSame($token, $reserved->participation_token);
        $this->assertNull($reserved->lobby_status);
        $this->assertNull($reserved->joined_lobby_at);
        $this->assertNull($reserved->agreed_at);

        $this->getJson('/api/v1/exam/lobby?participation_token='.$token)
            ->assertOk()
            ->assertJsonCount(0, 'data.students')
            ->assertJsonPath('data.connectedCount', 0);
        $this->assertSame(0, collect(app(ExamSessionService::class)->roomsForSchedule($schedule))
            ->firstWhere('id', $session->examination_room_id)['connected_count']);

        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid examination key for this session.');

        $this->postJson('/api/v1/exam/agree', ['participation_token' => $token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $agreed = $registration->fresh();
        $this->assertNotNull($agreed->agreed_at);
        $this->assertNotNull($agreed->joined_lobby_at);
        $this->assertSame('waiting', $agreed->lobby_status);
        $this->getJson('/api/v1/exam/lobby?participation_token='.$token)
            ->assertOk()
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.connectedCount', 1);
        $this->assertSame(1, collect(app(ExamSessionService::class)->roomsForSchedule($schedule))
            ->firstWhere('id', $session->examination_room_id)['connected_count']);
    }

    public function test_early_join_is_allowed_without_starting_until_the_proctor_starts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 08:29:00', 'Asia/Manila'));
        [$schedule, , $registration] = $this->registration();
        $schedule->forceFill(['start_time' => '09:00:00', 'end_time' => '10:00:00'])->save();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $session = $this->createSession($schedule);

        // 1. Student joins at 8:29: rejected with "too early" message
        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Joining opens at 8:30 AM.');
        $this->assertSame('not_sent', $registration->fresh()->passkey_status);

        // 2. Schedule 9:00. Room opened at 8:30. Student joins at 8:31: ALLOWED
        Carbon::setTestNow(Carbon::parse('2026-10-04 08:31:00', 'Asia/Manila'));
        $joined = $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
        ])->assertOk();

        $session->refresh();
        $this->assertSame(ExamSessionStatus::LobbyOpen, $session->status);
        $this->assertNull($session->started_at);
        $this->assertSame('used', $registration->fresh()->passkey_status);

        // 3. Proctor presses Start at 9:07: the exam starts at 9:07, timer starts from proctor Start
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:07:00', 'Asia/Manila'));
        $started = app(ExamSessionService::class)->start($session);
        $this->assertSame(
            '2026-10-04 09:07:00',
            $started->started_at->timezone('Asia/Manila')->toDateTimeString(),
        );
        $this->assertNotEmpty($joined->json('data.participation_token'));

        // 4. Student joins at 9:20 after proctor has started the exam: follows existing late-join behavior
        $applicant2 = Applicant::query()->create([
            'applicant_code' => 'CONSENT-2-'.uniqid(),
            'name' => 'Late Examinee',
            'gmail' => 'late@example.test',
        ]);
        $reg2 = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant2->id,
            'attendance_status' => 'pending',
            'result_status' => 'pending',
        ]);
        $passkey2 = app(ExaminationPasskeyService::class)->generateForSchedule($schedule, [$reg2->id])['keys'][0]['key'];

        Carbon::setTestNow(Carbon::parse('2026-10-04 09:20:00', 'Asia/Manila'));
        ExaminationSetting::current()->update(['allow_late_entry' => false]);
        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey2,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The examination has already started. Late entry is not allowed.');

        ExaminationSetting::current()->update(['allow_late_entry' => true]);
        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey2,
        ])->assertOk();

        // 5. Student joins after schedule_end: rejected
        $applicant3 = Applicant::query()->create([
            'applicant_code' => 'CONSENT-3-'.uniqid(),
            'name' => 'Expired Examinee',
            'gmail' => 'expired@example.test',
        ]);
        $reg3 = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant3->id,
            'attendance_status' => 'pending',
            'result_status' => 'pending',
        ]);
        $passkey3 = app(ExaminationPasskeyService::class)->generateForSchedule($schedule, [$reg3->id])['keys'][0]['key'];

        Carbon::setTestNow(Carbon::parse('2026-10-04 10:00:01', 'Asia/Manila'));
        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey3,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This examination schedule has ended.');
    }

    public function test_proctor_open_api_enforces_today_and_the_early_to_end_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 08:29:00', 'Asia/Manila'));
        [$schedule] = $this->registration();
        $session = $this->createSession($schedule);
        $payload = [
            'examination_schedule_id' => $schedule->id,
            'examination_room_id' => $session->examination_room_id,
        ];

        $this->actingAs($session->proctor)
            ->postJson('/api/v1/proctor/sessions', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Joining opens at 8:30 AM.');

        Carbon::setTestNow(Carbon::parse('2026-10-04 08:30:00', 'Asia/Manila'));
        $this->postJson('/api/v1/proctor/sessions', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The selected question bank has no exam questions.');

        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'Asia/Manila'));
        $this->postJson('/api/v1/proctor/sessions', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The selected question bank has no exam questions.');

        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:01', 'Asia/Manila'));
        $this->postJson('/api/v1/proctor/sessions', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'This examination schedule has ended.');

        $schedule->forceFill(['exam_date' => '2026-10-05'])->save();
        Carbon::setTestNow(Carbon::parse('2026-10-04 08:31:00', 'Asia/Manila'));
        $this->postJson('/api/v1/proctor/sessions', $payload)
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                "You can only open today's schedule (Sun, 04 Oct 2026). This schedule is for 05 Oct 2026. It will be available on that day.",
            );
    }

    public function test_pending_passkey_join_retry_with_same_device_returns_the_same_participation_token(): void
    {
        [$schedule, , $registration] = $this->registration();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $session = $this->createSession($schedule);
        $request = [
            'code' => $session->exam_code,
            'passkey' => $passkey,
            'device_id' => 'student-device-123',
        ];

        $firstJoin = $this->postJson('/api/v1/exam/join-passkey', $request)
            ->assertOk()
            ->assertJsonCount(0, 'data.lobby.students');
        $this->assertSame(['success', 'message', 'data'], array_keys($firstJoin->json()));
        $this->assertSame(
            ['registration_id', 'participation_token', 'applicant_id', 'lobby'],
            array_keys($firstJoin->json('data'))
        );
        $originalToken = $firstJoin->json('data.participation_token');

        $retry = $this->postJson('/api/v1/exam/join-passkey', $request)
            ->assertOk()
            ->assertJsonPath('data.participation_token', $originalToken)
            ->assertJsonCount(0, 'data.lobby.students');

        $this->assertSame($originalToken, $registration->fresh()->participation_token);
        $this->assertNull($registration->fresh()->agreed_at);
        $this->assertNull($registration->fresh()->joined_lobby_at);

        $this->postJson('/api/v1/exam/agree', ['participation_token' => $originalToken])
            ->assertOk();
        $this->assertNull($registration->fresh()->pending_device_id);
        $this->postJson('/api/v1/exam/join-passkey', $request)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid examination key for this session.');
    }

    public function test_pending_passkey_join_retry_from_another_or_unidentified_device_is_rejected(): void
    {
        [$schedule] = $this->registration();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $session = $this->createSession($schedule);

        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
            'device_id' => 'student-device-123',
        ])->assertOk();

        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
            'device_id' => 'different-device-456',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This examination key is already reserved on another device.');

        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
        ])->assertUnprocessable();
    }

    public function test_expired_pending_passkey_reservation_can_be_retried_without_changing_the_key(): void
    {
        [$schedule, , $registration] = $this->registration();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $session = $this->createSession($schedule);
        $hash = $registration->fresh()->exam_passkey_hash;
        $request = [
            'code' => $session->exam_code,
            'passkey' => $passkey,
            'device_id' => 'student-device-123',
        ];

        $firstJoin = $this->postJson('/api/v1/exam/join-passkey', $request)
            ->assertOk();
        $firstToken = $firstJoin->json('data.participation_token');

        $this->travel(16)->minutes();

        $retry = $this->postJson('/api/v1/exam/join-passkey', $request)
            ->assertOk();

        $this->assertNotSame($firstToken, $retry->json('data.participation_token'));
        $this->assertSame($hash, $registration->fresh()->exam_passkey_hash);
        $this->assertSame('used', $registration->fresh()->passkey_status);
        $this->assertSame('student-device-123', $registration->fresh()->pending_device_id);
        $this->travelBack();
    }

    public function test_expired_pending_participation_token_cannot_agree(): void
    {
        [$schedule] = $this->registration();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $session = $this->createSession($schedule);

        $joined = $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
            'device_id' => 'student-device-123',
        ])->assertOk();
        $token = $joined->json('data.participation_token');

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/exam/agree', ['participation_token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This pending examination reservation expired. Re-enter your examination key to rejoin.');
        $this->travelBack();
    }

    public function test_legacy_claim_and_join_are_not_visible_until_agreement(): void
    {
        [$schedule, $applicant] = $this->registration();
        $session = $this->createSession($schedule);

        $claim = $this->postJson('/api/v1/exam/claim', [
            'code' => $session->exam_code,
            'applicant_id' => $applicant->id,
        ])->assertOk()
            ->assertJsonCount(0, 'data.lobby.students');

        $joined = $this->postJson('/api/v1/exam/join', [
            'code' => $session->exam_code,
            'applicant_id' => $applicant->id,
        ])->assertOk()
            ->assertJsonCount(0, 'data.lobby.students');

        $registration = ExaminationRegistration::query()->findOrFail($claim->json('data.registration_id'));
        $this->assertSame($registration->id, $joined->json('data.registration_id'));
        $this->assertNull($registration->fresh()->lobby_status);
        $this->assertNull($registration->fresh()->joined_lobby_at);

        $this->postJson('/api/v1/exam/agree', [
            'participation_token' => $joined->json('data.participation_token'),
        ])->assertOk();

        $this->getJson('/api/v1/exam/lobby?participation_token='.$joined->json('data.participation_token'))
            ->assertOk()
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.connectedCount', 1);
    }

    public function test_agreed_student_can_heartbeat_and_reconnect(): void
    {
        [$schedule, , $registration] = $this->registration();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        ExaminationSetting::current()->update(['allow_late_entry' => true]);
        $session = $this->createSession($schedule, ExamSessionStatus::InProgress);

        $joined = $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
        ])->assertOk();
        $token = $joined->json('data.participation_token');
        $this->postJson('/api/v1/exam/agree', ['participation_token' => $token])->assertOk();

        $this->postJson('/api/v1/exam/heartbeat', ['participation_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.lobby_status', 'taking_exam');

        app(ExamSessionService::class)->markWifiDisconnected($registration->fresh());
        $reconnectCode = $registration->fresh()->reconnect_code;
        $this->assertNotEmpty($reconnectCode);

        $this->postJson('/api/v1/exam/reconnect', [
            'participation_token' => $token,
            'reconnect_code' => $reconnectCode,
        ])->assertOk()
            ->assertJsonPath('data.lobby_status', 'taking_exam');
    }

    public function test_closing_lobby_releases_unagreed_passkey_reservation(): void
    {
        [$schedule, , $registration] = $this->registration();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $session = $this->createSession($schedule);

        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
            'device_id' => 'student-device-123',
        ])->assertOk();

        app(ExamSessionService::class)->closeLobby($session);

        $released = $registration->fresh();
        $this->assertNull($released->exam_session_id);
        $this->assertNull($released->participation_token);
        $this->assertNull($released->lobby_status);
        $this->assertSame('not_sent', $released->passkey_status);
        $this->assertNull($released->passkey_used_at);
        $this->assertNull($released->pending_device_id);
        $this->assertSame(app(ExaminationPasskeyService::class)->hashPasskey($passkey), $released->exam_passkey_hash);
    }

    public function test_ending_session_releases_pending_reservations_without_counting_them_as_joined(): void
    {
        [$schedule, , $registration] = $this->registration();
        $passkey = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        ExaminationSetting::current()->update(['allow_late_entry' => true]);
        $session = $this->createSession($schedule, ExamSessionStatus::InProgress);

        $this->postJson('/api/v1/exam/join-passkey', [
            'code' => $session->exam_code,
            'passkey' => $passkey,
            'device_id' => 'student-device-123',
        ])->assertOk();

        $this->assertSame(
            ExamSessionStatus::InProgress,
            app(ExamSessionService::class)->maybeEndWhenAllFinished($session)->status,
        );

        app(ExamSessionService::class)->end($session, forceSubmitRemaining: false);

        $released = $registration->fresh();
        $this->assertNull($released->exam_session_id);
        $this->assertSame('not_sent', $released->passkey_status);
        $this->assertNull($released->participation_token);
        $this->assertNull($released->pending_device_id);
    }

    private function registration(): array
    {
        $schedule = ExaminationSchedule::query()->create([
            'title' => 'Lobby consent exam',
            'exam_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'time_slot' => '09:00 AM - 12:00 PM',
            'batch_code' => 'B1',
            'status' => 'scheduled',
        ]);
        $applicant = Applicant::query()->create([
            'applicant_code' => 'CONSENT-'.uniqid(),
            'name' => 'Consent Examinee',
            'gmail' => 'consent@example.test',
        ]);
        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => 'pending',
            'result_status' => 'pending',
        ]);

        return [$schedule, $applicant, $registration];
    }

    private function createSession(
        ExaminationSchedule $schedule,
        ExamSessionStatus $status = ExamSessionStatus::LobbyOpen,
    ): ExamSession {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $proctor = User::factory()->create(['role_id' => $role->id]);
        $room = ExaminationRoom::query()->create([
            'examination_schedule_id' => $schedule->id,
            'room_name' => 'Consent Room',
            'capacity' => 30,
            'proctor_id' => $proctor->id,
        ]);
        $bank = QuestionBank::query()->create([
            'school_year' => '2028-2029',
            'title' => 'Consent Test Bank',
            'is_active' => true,
        ]);

        return ExamSession::query()->create([
            'examination_schedule_id' => $schedule->id,
            'examination_room_id' => $room->id,
            'question_bank_id' => $bank->id,
            'proctor_id' => $proctor->id,
            'exam_code' => strtoupper(bin2hex(random_bytes(4))),
            'status' => $status,
        ]);
    }
}
