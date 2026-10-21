<?php

namespace Tests\Feature;

use App\Enums\ExamSessionStatus;
use App\Models\ExamSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExamSessionJoinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_opening_session_twice_for_same_proctor_closes_first_and_creates_fresh_session(): void
    {
        $proctor = $this->createProctor();

        // 1st open
        $res1 = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctor->id,
            'local_ip' => '192.168.1.42',
            'local_port' => 9777,
        ]);

        $res1->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('proctor_local_ip', '192.168.1.42')
            ->assertJsonPath('proctor_local_port', 9777)
            ->assertJsonPath('status', 'open');

        $sessionId1 = $res1->json('session_id');
        $code1 = $res1->json('session_code');
        $token1 = $res1->json('qr_token');

        $this->assertNotEmpty($code1);
        $this->assertSame(6, strlen($code1));
        $this->assertNotEmpty($token1);

        // 2nd open for same proctor
        $res2 = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctor->id,
            'local_ip' => '192.168.1.42',
            'local_port' => 9777,
        ]);

        $res2->assertCreated()
            ->assertJsonPath('success', true);

        $sessionId2 = $res2->json('session_id');
        $code2 = $res2->json('session_code');
        $token2 = $res2->json('qr_token');

        $this->assertNotEquals($sessionId1, $sessionId2);
        $this->assertNotEquals($code1, $code2);
        $this->assertNotEquals($token1, $token2);

        // First session must be closed
        $firstSession = ExamSession::find($sessionId1);
        $this->assertSame(ExamSessionStatus::Closed, $firstSession->status);
        $this->assertNotNull($firstSession->ended_at);

        // Second session must be open
        $secondSession = ExamSession::find($sessionId2);
        $this->assertSame(ExamSessionStatus::Open, $secondSession->status);
    }

    public function test_opening_session_with_return_existing_config_reuses_active_session(): void
    {
        Config::set('exam_session.reopen_behavior', 'return_existing');
        $proctor = $this->createProctor();

        $res1 = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctor->id,
            'local_ip' => '192.168.1.42',
            'local_port' => 9777,
        ]);
        $res1->assertCreated();
        $sessionId1 = $res1->json('session_id');

        $res2 = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctor->id,
            'local_ip' => '192.168.1.55',
            'local_port' => 9888,
        ]);

        $res2->assertOk()
            ->assertJsonPath('session_id', $sessionId1)
            ->assertJsonPath('proctor_local_ip', '192.168.1.55')
            ->assertJsonPath('proctor_local_port', 9888);
    }

    public function test_resolving_open_code_returns_proctor_ip_port_and_handshake_passkey(): void
    {
        $proctor = $this->createProctor();

        $openRes = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctor->id,
            'local_ip' => '192.168.1.100',
            'local_port' => 9777,
        ]);
        $code = $openRes->json('session_code');
        $sessionId = $openRes->json('session_id');

        $resolveRes = $this->getJson("/api/sessions/resolve?code={$code}");

        $resolveRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonPath('session_code', $code)
            ->assertJsonPath('proctor_local_ip', '192.168.1.100')
            ->assertJsonPath('proctor_local_port', 9777)
            ->assertJsonPath('status', 'open');

        $this->assertNotEmpty($resolveRes->json('passkey'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $resolveRes->json('passkey'));
    }

    public function test_resolving_open_qr_token_returns_proctor_ip_port_and_handshake_passkey(): void
    {
        $proctor = $this->createProctor();

        $openRes = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctor->id,
            'local_ip' => '192.168.1.105',
            'local_port' => 9777,
        ]);
        $token = $openRes->json('qr_token');
        $sessionId = $openRes->json('session_id');

        $resolveRes = $this->getJson("/api/sessions/resolve?token={$token}");

        $resolveRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonPath('proctor_local_ip', '192.168.1.105');
    }

    public function test_resolving_expired_or_closed_code_returns_404(): void
    {
        $proctor = $this->createProctor();

        // 1. Expired session
        $expired = ExamSession::create([
            'proctor_id' => $proctor->id,
            'session_code' => 'EXPR01',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'proctor_local_ip' => '192.168.1.10',
            'proctor_local_port' => 9777,
            'status' => ExamSessionStatus::Open,
            'expires_at' => now()->subHour(),
        ]);

        $res1 = $this->getJson('/api/sessions/resolve?code=EXPR01');
        $res1->assertNotFound()
            ->assertJsonPath('success', false);

        // 2. Closed session
        $closed = ExamSession::create([
            'proctor_id' => $proctor->id,
            'session_code' => 'CLSD01',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'proctor_local_ip' => '192.168.1.10',
            'proctor_local_port' => 9777,
            'status' => ExamSessionStatus::Closed,
            'expires_at' => now()->addHour(),
        ]);

        $res2 = $this->getJson('/api/sessions/resolve?code=CLSD01');
        $res2->assertNotFound()
            ->assertJsonPath('success', false);

        // 3. Non-existent code
        $res3 = $this->getJson('/api/sessions/resolve?code=UNKNOWN');
        $res3->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_two_different_proctors_on_same_network_produce_non_colliding_codes(): void
    {
        $proctorA = $this->createProctor();
        $proctorB = $this->createProctor();

        // Simulate Proctor A on Lab Wi-Fi
        $resA = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctorA->id,
            'local_ip' => '192.168.1.120',
            'local_port' => 9777,
        ]);
        $resA->assertCreated();

        // Simulate Proctor B on same subnet with distinct IP
        $resB = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctorB->id,
            'local_ip' => '192.168.1.121',
            'local_port' => 9777,
        ]);
        $resB->assertCreated();

        $codeA = $resA->json('session_code');
        $codeB = $resB->json('session_code');

        $this->assertNotEquals($codeA, $codeB);

        // Examinee entering Proctor A's code resolves to Proctor A's host
        $resolveA = $this->getJson("/api/sessions/resolve?code={$codeA}");
        $resolveA->assertOk()
            ->assertJsonPath('proctor_local_ip', '192.168.1.120');

        // Examinee entering Proctor B's code resolves to Proctor B's host
        $resolveB = $this->getJson("/api/sessions/resolve?code={$codeB}");
        $resolveB->assertOk()
            ->assertJsonPath('proctor_local_ip', '192.168.1.121');
    }

    public function test_student_cannot_join_same_session_from_second_device(): void
    {
        $proctor = $this->createProctor();
        $openRes = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctor->id,
            'local_ip' => '192.168.1.50',
            'local_port' => 9777,
        ]);
        $code = $openRes->json('session_code');

        // Student first join from Device 1
        $res1 = $this->getJson("/api/sessions/resolve?code={$code}&student_id=STU-999&device_id=dev-phone-1");
        $res1->assertOk();

        // Same student same device retry is permitted
        $resRetry = $this->getJson("/api/sessions/resolve?code={$code}&student_id=STU-999&device_id=dev-phone-1");
        $resRetry->assertOk();

        // Same student attempts second join from Device 2 -> Rejected 409
        $res2 = $this->getJson("/api/sessions/resolve?code={$code}&student_id=STU-999&device_id=dev-phone-2");
        $res2->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_session_code_is_unique_across_open_and_lobby_open_states(): void
    {
        $proctor = $this->createProctor();
        ExamSession::query()->create([
            'proctor_id' => $proctor->id,
            'exam_code' => 'EXAM01',
            'session_code' => 'SAME01',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ExamSessionStatus::LobbyOpen,
        ]);

        $this->expectException(QueryException::class);
        ExamSession::query()->create([
            'proctor_id' => $proctor->id,
            'exam_code' => 'EXAM02',
            'session_code' => 'SAME01',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ExamSessionStatus::Open,
        ]);
    }

    public function test_active_exam_code_is_unique_across_open_and_lobby_open_states(): void
    {
        $proctor = $this->createProctor();
        ExamSession::query()->create([
            'proctor_id' => $proctor->id,
            'exam_code' => '8CHARAA1',
            'session_code' => 'OPENA01',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ExamSessionStatus::Closed,
        ]);
        ExamSession::query()->create([
            'proctor_id' => $proctor->id,
            'exam_code' => '8CHARAA1',
            'session_code' => 'OPENA02',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ExamSessionStatus::LobbyOpen,
        ]);

        $this->expectException(QueryException::class);
        ExamSession::query()->create([
            'proctor_id' => $proctor->id,
            'exam_code' => '8CHARAA1',
            'session_code' => 'OPENB01',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ExamSessionStatus::Open,
        ]);
    }

    public function test_closed_session_reopens_with_new_exam_code(): void
    {
        $proctor = $this->createProctor();
        $session = ExamSession::query()->create([
            'proctor_id' => $proctor->id,
            'exam_code' => 'OLDCODE1',
            'session_code' => 'SESSION1',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ExamSessionStatus::Closed,
        ]);

        $session->update([
            'status' => ExamSessionStatus::LobbyOpen,
            'exam_code' => 'NEWCODE1',
        ]);

        $this->assertSame('NEWCODE1', $session->fresh()->exam_code);
        $this->assertNotSame('OLDCODE1', $session->fresh()->exam_code);
    }

    public function test_session_start_and_close_endpoints(): void
    {
        $proctor = $this->createProctor();
        $openRes = $this->postJson('/api/sessions/open', [
            'proctor_id' => $proctor->id,
            'local_ip' => '192.168.1.75',
            'local_port' => 9777,
        ]);
        $sessionId = $openRes->json('session_id');

        // Start
        $startRes = $this->postJson("/api/sessions/{$sessionId}/start");
        $startRes->assertOk()
            ->assertJsonPath('session.status', 'in_progress');

        $session = ExamSession::find($sessionId);
        $this->assertSame(ExamSessionStatus::InProgress, $session->status);
        $this->assertNotNull($session->started_at);

        // Close
        $closeRes = $this->postJson("/api/sessions/{$sessionId}/close");
        $closeRes->assertOk()
            ->assertJsonPath('session.status', 'closed');

        $session->refresh();
        $this->assertSame(ExamSessionStatus::Closed, $session->status);
        $this->assertNotNull($session->ended_at);
    }

    public function test_scheduled_job_closes_expired_sessions(): void
    {
        $proctor = $this->createProctor();
        $expired = ExamSession::create([
            'proctor_id' => $proctor->id,
            'session_code' => 'EXP111',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'proctor_local_ip' => '192.168.1.80',
            'proctor_local_port' => 9777,
            'status' => ExamSessionStatus::Open,
            'expires_at' => now()->subMinutes(15),
        ]);

        $active = ExamSession::create([
            'proctor_id' => $proctor->id,
            'session_code' => 'ACT222',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'proctor_local_ip' => '192.168.1.81',
            'proctor_local_port' => 9777,
            'status' => ExamSessionStatus::Open,
            'expires_at' => now()->addMinutes(45),
        ]);

        Artisan::call('sessions:close-expired');

        $expired->refresh();
        $active->refresh();

        $this->assertSame(ExamSessionStatus::Closed, $expired->status);
        $this->assertNotNull($expired->ended_at);

        $this->assertSame(ExamSessionStatus::Open, $active->status);
    }

    private function createProctor(): User
    {
        $role = Role::where('slug', 'proctor')->first();

        return User::create([
            'role_id' => $role?->id ?? 2,
            'name' => 'Proctor ' . fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
