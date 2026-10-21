<?php

namespace Tests\Feature;

use App\Mail\ExaminationPasskeyMail;
use App\Models\Applicant;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicantService;
use App\Services\ExaminationPasskeyService;
use App\Support\PasskeyCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExaminationPasskeySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_is_idempotent_and_encrypted_passkey_is_persisted_and_revealed_for_admin_use(): void
    {
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);

        $first = $service->generateForSchedule($schedule);
        $key = $first['keys'][0]['key'];
        $this->assertSame(8, strlen($key));
        $this->assertMatchesRegularExpression('/\A[A-Z0-9]{8}\z/', $key);
        $this->assertDatabaseHas('exam_registrations', [
            'id' => $registration->id,
            'exam_passkey_hash' => $service->hashPasskey($key),
            'passkey_status' => 'not_sent',
        ]);
        $this->assertFalse(Schema::hasColumn('exam_registrations', 'exam_passkey'));

        $second = $service->generateForSchedule($schedule);
        $this->assertSame(0, $second['generated']);
        $this->assertSame([], $second['keys']);

        $row = $service->listForSchedule($schedule)->items()[0];
        $this->assertSame($key, $row['exam_passkey']);
        $this->assertArrayNotHasKey('exam_passkey_hash', $row);
        $this->assertSame('not_sent', $row['passkey_status']);
        $this->atScheduleWindow($schedule, function () use ($service, $schedule, $key, $registration): void {
            $this->assertSame($registration->id, $service->findByPasskeyForSchedule($schedule, $key)?->id);
        });
    }

    public function test_admin_passkey_list_reveals_expired_key_without_reopening_it(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration(Carbon::yesterday()->toDateString());
        $service = app(ExaminationPasskeyService::class);
        $key = $service->generateForSchedule($schedule)['keys'][0]['key'];

        $this->actingAs($admin)
            ->getJson("/api/examination-schedules/{$schedule->id}/passkeys")
            ->assertOk()
            ->assertJsonPath('data.0.exam_passkey', $key)
            ->assertJsonPath('data.0.passkey_status', 'not_sent');

        $this->assertTrue($registration->fresh()->passkey_expires_at->isPast());
        $this->assertNull($service->findByPasskeyForSchedule($schedule, $key));
    }

    public function test_successful_email_delivery_marks_key_sent_and_returns_the_key_once(): void
    {
        Mail::fake();
        [$schedule, $registration] = $this->registration();
        $result = app(ExaminationPasskeyService::class)->sendForSchedule($schedule);
        $key = $result['keys'][0]['key'];

        $this->assertSame(1, $result['sent']);
        $this->assertSame('sent', $registration->fresh()->passkey_status);
        $this->assertTrue($registration->fresh()->passkey_email_sent);
        $this->assertSame('student@example.test', $registration->fresh()->passkey_sent_to_email);
        $this->assertSame(app(ExaminationPasskeyService::class)->hashPasskey($key), $registration->fresh()->exam_passkey_hash);
        Mail::assertSent(ExaminationPasskeyMail::class, fn (ExaminationPasskeyMail $mail) => $mail->passkey === $key);
    }

    public function test_import_creates_a_distinct_encrypted_passkey_for_each_scheduled_student(): void
    {
        $result = app(ApplicantService::class)->importRows([
            [
                'name' => 'First Imported Examinee',
                'desired_program' => 'BSIT',
                'application_date' => Carbon::tomorrow()->toDateString(),
                'time' => '09:00-10:00',
                'gmail' => 'first@example.test',
            ],
            [
                'name' => 'Second Imported Examinee',
                'desired_program' => 'BSIT',
                'application_date' => Carbon::tomorrow()->toDateString(),
                'time' => '09:00-10:00',
                'gmail' => 'second@example.test',
            ],
        ]);

        $this->assertSame(2, $result['imported']);
        $registrations = ExaminationRegistration::query()->orderBy('id')->get();
        $this->assertCount(2, $registrations);

        $service = app(ExaminationPasskeyService::class);
        $keys = $registrations->map(function (ExaminationRegistration $registration) use ($service) {
            $this->assertNotEmpty($registration->exam_passkey_hash);
            $this->assertNotEmpty($registration->exam_passkey_encrypted);
            $key = $service->revealPasskey($registration->exam_passkey_encrypted);
            $this->assertSame($registration->exam_passkey_hash, $service->hashPasskey($key));

            return $key;
        });

        $this->assertCount(2, $keys->unique());
    }

    public function test_resending_delivers_the_same_active_passkey(): void
    {
        Mail::fake();
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $originalKey = $service->generateForSchedule($schedule)['keys'][0]['key'];

        $result = $service->sendForSchedule($schedule);

        $this->assertSame($originalKey, $result['keys'][0]['key']);
        $this->assertSame($originalKey, $service->revealPasskey($registration->fresh()->exam_passkey_encrypted));
        Mail::assertSent(ExaminationPasskeyMail::class, fn (ExaminationPasskeyMail $mail) => $mail->passkey === $originalKey);
    }

    public function test_resending_a_hash_only_legacy_key_reissues_and_makes_it_visible(): void
    {
        Mail::fake();
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $oldKey = $service->generateForSchedule($schedule)['keys'][0]['key'];
        DB::table('exam_registrations')
            ->where('id', $registration->id)
            ->update(['exam_passkey_encrypted' => null]);

        $response = $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/passkeys/resend")
            ->assertOk();
        $replacementKey = $response->json('data.key');

        $this->assertNotSame($oldKey, $replacementKey);
        $this->assertSame($replacementKey, $service->revealPasskey($registration->fresh()->exam_passkey_encrypted));
        $this->assertSame($replacementKey, $service->listForSchedule($schedule)->items()[0]['exam_passkey']);
        Mail::assertSent(ExaminationPasskeyMail::class, fn (ExaminationPasskeyMail $mail) => $mail->passkey === $replacementKey);
    }

    public function test_admin_schedule_view_reports_hash_only_used_key_without_changing_the_registration(): void
    {
        Mail::fake();
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $service->generateForSchedule($schedule);
        DB::table('exam_registrations')->where('id', $registration->id)->update([
            'exam_passkey_encrypted' => null,
            'passkey_status' => 'used',
            'result_status' => 'failed',
            'score' => 37,
        ]);
        $before = $registration->fresh();
        $auditCount = DB::table('activity_logs')->count();

        $response = $this->actingAs($admin)
            ->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk();
        $visibleKey = $response->json('data.students.0.exam_passkey');
        $fresh = $registration->fresh();

        $this->assertNull($visibleKey);
        $response->assertJsonPath('data.students.0.passkey_exists', true)
            ->assertJsonPath('data.students.0.passkey_encrypted_exists', false)
            ->assertJsonPath('data.students.0.passkey_decryption_error', false)
            ->assertJsonPath('data.students.0.passkey_unavailable_reason', 'This examination key has already been used.');
        $this->assertSame($before->exam_passkey_hash, $fresh->exam_passkey_hash);
        $this->assertNull($fresh->exam_passkey_encrypted);
        $this->assertSame('used', $fresh->passkey_status);
        $this->assertSame('failed', $fresh->result_status->value);
        $this->assertSame('37.00', $fresh->score);
        $this->assertSame($auditCount + 1, DB::table('activity_logs')->count());

        $this->getJson("/api/examination-schedules/{$schedule->id}/passkeys")
            ->assertOk()
            ->assertJsonPath('data.0.exam_passkey', null)
            ->assertJsonPath('data.0.passkey_unavailable_reason', 'This examination key has already been used.');
        $this->assertSame($before->exam_passkey_hash, $registration->fresh()->exam_passkey_hash);
        $this->assertNull($registration->fresh()->exam_passkey_encrypted);
        $this->assertSame('used', $registration->fresh()->passkey_status);
        $this->assertSame($auditCount + 2, DB::table('activity_logs')->count());
        Mail::assertNothingSent();
    }

    #[DataProvider('unavailablePasskeyStatuses')]
    public function test_admin_schedule_read_returns_unavailable_for_hash_only_used_or_revoked_keys(string $status): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $oldKey = $service->generateForSchedule($schedule)['keys'][0]['key'];
        DB::table('exam_registrations')->where('id', $registration->id)->update([
            'exam_passkey_encrypted' => null,
            'passkey_status' => $status,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk();
        $visibleKey = $response->json('data.students.0.exam_passkey');
        $fresh = $registration->fresh();

        $this->assertNull($visibleKey);
        $response->assertJsonPath(
            'data.students.0.passkey_unavailable_reason',
            $status === 'used'
                ? 'This examination key has already been used.'
                : 'This examination key has been revoked.',
        );
        $this->assertSame($service->hashPasskey($oldKey), $fresh->exam_passkey_hash);
        $this->assertNull($fresh->exam_passkey_encrypted);
        $this->assertSame($status, $fresh->passkey_status);
        $this->assertNull($service->findByPasskeyForSchedule($schedule, $oldKey));
    }

    public function test_two_admin_schedule_reads_do_not_mutate_registration_and_displayed_key_is_validator_accepted(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $key = $service->generateForSchedule($schedule)['keys'][0]['key'];
        $before = $registration->fresh();
        $auditCount = DB::table('activity_logs')->count();
        $registrationWrites = 0;

        DB::listen(function ($query) use (&$registrationWrites): void {
            if (preg_match('/^\s*(update|insert\s+into|delete\s+from)\s+["`]?exam_registrations\b/i', $query->sql) === 1) {
                $registrationWrites++;
            }
        });

        $first = $this->actingAs($admin)
            ->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.students.0.exam_passkey', $key)
            ->assertJsonPath('data.students.0.passkey_unavailable_reason', null);
        $second = $this->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.students.0.exam_passkey', $key)
            ->assertJsonPath('data.students.0.passkey_unavailable_reason', null);

        $this->assertSame(0, $registrationWrites);
        $after = $registration->fresh();
        $this->assertSame($before->exam_passkey_hash, $after->exam_passkey_hash);
        $this->assertSame($before->exam_passkey_encrypted, $after->exam_passkey_encrypted);
        $this->assertSame($before->passkey_status, $after->passkey_status);
        $this->assertTrue($before->passkey_expires_at->equalTo($after->passkey_expires_at));
        $this->assertSame($auditCount + 2, DB::table('activity_logs')->count());
        $this->atScheduleWindow($schedule, function () use ($service, $schedule, $first, $second): void {
            $this->assertNotNull($service->findByPasskeyForSchedule(
                $schedule,
                $first->json('data.students.0.exam_passkey'),
            ));
            $this->assertNotNull($service->findByPasskeyForSchedule(
                $schedule,
                $second->json('data.students.0.exam_passkey'),
            ));
        });
    }

    public static function unavailablePasskeyStatuses(): array
    {
        return [
            'used key' => ['used'],
            'revoked key' => ['revoked'],
        ];
    }

    public function test_failed_preflight_does_not_mark_the_key_as_sent(): void
    {
        Mail::fake();
        [$schedule, $registration] = $this->registration(gmail: null);
        $service = app(ExaminationPasskeyService::class);
        $key = $service->generateForSchedule($schedule)['keys'][0]['key'];

        try {
            $service->sendOne($registration->fresh(), null, $key);
            $this->fail('Expected missing Gmail to prevent key delivery.');
        } catch (\RuntimeException) {
            $this->assertSame('not_sent', $registration->fresh()->passkey_status);
            $this->assertFalse($registration->fresh()->passkey_email_sent);
        }
    }

    public function test_key_is_single_use_and_expired_keys_do_not_validate(): void
    {
        $service = app(ExaminationPasskeyService::class);
        [$schedule, $registration] = $this->registration();
        $key = $service->generateForSchedule($schedule)['keys'][0]['key'];

        Carbon::setTestNow(Carbon::parse($schedule->exam_date->toDateString().' 10:00:00', 'Asia/Manila'));
        try {
            $service->markUsed($registration, 44);
            $this->assertSame('used', $registration->fresh()->passkey_status);
            $this->assertNotNull($registration->fresh()->passkey_used_at);
            $this->assertNull($service->findByPasskeyForSchedule($schedule, $key));
        } finally {
            Carbon::setTestNow();
        }

        [$pastSchedule] = $this->registration(Carbon::yesterday()->toDateString(), '00:01:00');
        $pastKey = $service->generateForSchedule($pastSchedule)['keys'][0]['key'];
        $this->assertNull($service->findByPasskeyForSchedule($pastSchedule, $pastKey));
    }

    public function test_passkey_is_valid_from_thirty_minutes_before_through_the_schedule_end(): void
    {
        [$schedule] = $this->registration('2026-10-04', '10:00:00');
        $schedule->forceFill(['start_time' => '09:00:00'])->save();
        $registration = $schedule->registrations()->firstOrFail();
        $key = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $session = $this->joinableSessionFor($schedule);
        $sessions = app(\App\Services\ExamSessionService::class);

        try {
            Carbon::setTestNow(Carbon::parse('2026-10-04 08:29:59', 'Asia/Manila'));
            try {
                $sessions->validatePasskey($session, $key);
                $this->fail('A passkey must not be valid before the early-join window.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Joining opens at 8:30 AM.', $exception->getMessage());
            }

            Carbon::setTestNow(Carbon::parse('2026-10-04 08:31:00', 'Asia/Manila'));
            $this->assertSame($registration->id, $sessions->validatePasskey($session, $key)['registration']->id);

            Carbon::setTestNow(Carbon::parse('2026-10-04 09:07:00', 'Asia/Manila'));
            $this->assertSame($registration->id, $sessions->validatePasskey($session, $key)['registration']->id);

            Carbon::setTestNow(Carbon::parse('2026-10-04 10:00:00', 'Asia/Manila'));
            $this->assertSame($registration->id, $sessions->validatePasskey($session, $key)['registration']->id);

            Carbon::setTestNow(Carbon::parse('2026-10-04 10:00:01', 'Asia/Manila'));
            try {
                $sessions->validatePasskey($session, $key);
                $this->fail('A passkey must be rejected after the batch end.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('This examination schedule has ended.', $exception->getMessage());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revoke_blocks_lookup_and_explicit_regeneration_returns_a_new_one_time_key(): void
    {
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $oldKey = $service->generateForSchedule($schedule)['keys'][0]['key'];

        $service->revoke($registration);
        $this->assertSame('revoked', $registration->fresh()->passkey_status);
        $this->assertNull($service->findByPasskeyForSchedule($schedule, $oldKey));
        $this->assertSame(0, $service->generateForSchedule($schedule)['generated']);

        $regenerated = $service->regenerate($registration->fresh());
        $this->assertNotSame($oldKey, $regenerated['key']);
        $this->assertSame('not_sent', $registration->fresh()->passkey_status);
        $this->assertSame($service->hashPasskey($regenerated['key']), $registration->fresh()->exam_passkey_hash);
    }

    public function test_admin_list_includes_status_counts_and_reveals_the_key_code(): void
    {
        Mail::fake();
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule] = $this->registration();

        $sent = $this->actingAs($admin)->postJson("/api/examination-schedules/{$schedule->id}/passkeys/send")
            ->assertOk();
        $key = $sent->json('data.keys.0.key');

        $response = $this->actingAs($admin)->getJson("/api/examination-schedules/{$schedule->id}/passkeys?status=sent");
        $response->assertOk()
            ->assertJsonPath('meta.counts.total', 1)
            ->assertJsonPath('meta.counts.sent', 1)
            ->assertJsonPath('data.0.passkey_status', 'sent');
        $this->assertSame($key, $response->json('data.0.exam_passkey'));
        $this->assertArrayNotHasKey('exam_passkey_hash', $response->json('data.0'));
    }

    public function test_admin_can_reveal_the_code_while_db_keeps_only_ciphertext(): void
    {
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $key = $service->generateForSchedule($schedule)['keys'][0]['key'];

        $raw = DB::table('exam_registrations')->where('id', $registration->id)->value('exam_passkey_encrypted');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($key, (string) $raw);
        $this->assertSame($key, $service->revealPasskey($raw));
        $this->assertSame($key, $service->listForSchedule($schedule)->items()[0]['exam_passkey']);

        $service->revoke($registration->fresh());
        $this->assertNull($service->revealPasskey(
            DB::table('exam_registrations')->where('id', $registration->id)->value('exam_passkey_encrypted')
        ));
    }

    public function test_admin_schedule_student_list_includes_the_plaintext_passkey(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule] = $this->registration();
        $key = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];

        $this->actingAs($admin)
            ->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.students.0.exam_passkey', $key);
    }

    public function test_schedule_id_round_trips_from_index_to_detail_and_admin_view_is_audited(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule] = $this->registration();
        app(ExaminationPasskeyService::class)->generateForSchedule($schedule);

        $this->actingAs($admin)->getJson('/api/examination-schedules')
            ->assertOk()
            ->assertJsonPath('data.0.id', $schedule->id);

        $this->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $schedule->id)
            ->assertJsonPath('data.schedule_id', $schedule->id);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'examination_passkeys_viewed',
            'subject_type' => ExaminationSchedule::class,
            'subject_id' => $schedule->id,
        ]);
    }

    public function test_corrupt_ciphertext_is_reported_without_changing_the_registration(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $service->generateForSchedule($schedule);
        DB::table('exam_registrations')->where('id', $registration->id)
            ->update(['exam_passkey_encrypted' => 'corrupted-ciphertext']);
        $before = $registration->fresh();

        $this->actingAs($admin)->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.students.0.exam_passkey', null)
            ->assertJsonPath('data.students.0.passkey_decryption_error', true)
            ->assertJsonPath('data.students.0.passkey_unavailable_reason', 'The stored examination key cannot be decrypted.');

        $this->getJson("/api/examination-schedules/{$schedule->id}/passkeys")
            ->assertOk()
            ->assertJsonPath('data.0.exam_passkey', null)
            ->assertJsonPath('data.0.passkey_decryption_error', true);
        $fresh = $registration->fresh();
        $this->assertSame($before->exam_passkey_hash, $fresh->exam_passkey_hash);
        $this->assertSame('corrupted-ciphertext', $fresh->exam_passkey_encrypted);
        $this->assertSame('not_sent', $fresh->passkey_status);
    }

    public function test_legacy_laravel_ciphertext_is_read_without_reencrypting_during_admin_view(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $key = $service->generateForSchedule($schedule)['keys'][0]['key'];
        $legacyCiphertext = Crypt::encryptString($key);
        DB::table('exam_registrations')->where('id', $registration->id)
            ->update(['exam_passkey_encrypted' => $legacyCiphertext]);

        $this->actingAs($admin)->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.students.0.exam_passkey', $key);

        $ciphertext = DB::table('exam_registrations')->where('id', $registration->id)
            ->value('exam_passkey_encrypted');
        $this->assertSame($legacyCiphertext, $ciphertext);
        $this->assertSame($key, $service->revealPasskey($ciphertext));
    }

    public function test_admin_view_does_not_replace_an_unsupported_legacy_passkey(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $oldKey = 'ABC234DEF567GHJK';
        $registration->forceFill([
            'exam_passkey_hash' => $service->hashPasskey($oldKey),
            'exam_passkey_encrypted' => $service->encryptPasskey($oldKey),
            'passkey_status' => 'sent',
            'passkey_expires_at' => now()->addDay(),
        ])->save();
        $before = $registration->fresh();

        $response = $this->actingAs($admin)->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertOk();
        $this->assertNull($response->json('data.students.0.exam_passkey'));
        $response->assertJsonPath(
            'data.students.0.passkey_unavailable_reason',
        'The stored examination key is not a valid 8-character code.',
        );
        $this->assertSame($before->exam_passkey_hash, $registration->fresh()->exam_passkey_hash);
        $this->assertSame($before->exam_passkey_encrypted, $registration->fresh()->exam_passkey_encrypted);
        $this->assertSame('sent', $registration->fresh()->passkey_status);
        $this->assertSame($oldKey, $service->revealPasskey($registration->fresh()->exam_passkey_encrypted));
    }

    public function test_batch_regeneration_encrypts_new_codes_and_preserves_used_results(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $service->generateForSchedule($schedule);
        $registration->forceFill([
            'passkey_status' => 'used',
            'passkey_used_at' => now(),
            'result_status' => 'failed',
            'score' => 37,
        ])->save();

        $response = $this->actingAs($admin)
            ->postJson("/api/examination-schedules/{$schedule->id}/passkeys/regenerate-batch")
            ->assertOk()
            ->assertJsonPath('data.generated', 1);
        $key = $response->json('data.keys.0.key');
        $fresh = $registration->fresh();
        $ciphertext = explode(':', $fresh->exam_passkey_encrypted);

        $this->assertSame(3, count($ciphertext));
        $this->assertSame($key, $service->revealPasskey($fresh->exam_passkey_encrypted));
        $this->assertSame('used', $fresh->passkey_status);
        $this->assertSame('failed', $fresh->result_status->value);
        $this->assertSame('37.00', $fresh->score);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'examination_passkeys_regenerated',
            'subject_id' => $schedule->id,
        ]);
    }

    public function test_non_admin_cannot_read_plaintext_passkeys(): void
    {
        $studentRole = Role::query()->firstOrCreate(['slug' => 'student'], ['name' => 'Student']);
        $student = User::factory()->create(['role_id' => $studentRole->id]);
        [$schedule] = $this->registration();

        $this->actingAs($student)->getJson("/api/examination-schedules/{$schedule->id}")
            ->assertForbidden();
        $this->getJson("/api/examination-schedules/{$schedule->id}/passkeys")
            ->assertForbidden();
    }

    public function test_passkey_encryption_key_must_decode_to_exactly_32_bytes(): void
    {
        config(['passkeys.encryption_key' => 'too-short']);

        $this->expectException(\RuntimeException::class);
        new PasskeyCipher();
    }

    public function test_admin_can_reset_a_finished_attempt_and_get_a_visible_testing_key(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule, $registration] = $this->registration();
        $service = app(ExaminationPasskeyService::class);
        $oldKey = $service->generateForSchedule($schedule)['keys'][0]['key'];
        $registration->forceFill([
            'passkey_status' => 'used',
            'passkey_used_at' => now(),
            'result_status' => 'failed',
            'attendance_status' => 'present',
            'score' => 42,
            'items_correct' => 7,
            'items_total' => 20,
        ])->save();

        $response = $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/passkeys/reset-for-testing")
            ->assertOk()
            ->assertJsonPath('data.registration_id', $registration->id);
        $newKey = $response->json('data.key');
        $fresh = $registration->fresh();

        $this->assertNotSame($oldKey, $newKey);
        $this->assertSame('not_sent', $fresh->passkey_status);
        $this->assertSame('pending', $fresh->result_status->value);
        $this->assertSame('pending', $fresh->attendance_status->value);
        $this->assertNull($fresh->score);
        $this->assertNull($fresh->items_correct);
        $this->assertNull($fresh->items_total);
        $this->assertSame($newKey, $service->revealPasskey($fresh->exam_passkey_encrypted));
        $this->assertSame($newKey, $service->listForSchedule($schedule)->items()[0]['exam_passkey']);
    }

    public function test_admin_cannot_reset_a_passkey_while_exam_is_not_finished(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [, $registration] = $this->registration();
        $registration->forceFill([
            'exam_passkey_hash' => app(ExaminationPasskeyService::class)->hashPasskey('TESTKEY123456789'),
            'passkey_status' => 'used',
            'result_status' => 'pending',
            'lobby_status' => 'taking_exam',
        ])->save();

        $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/passkeys/reset-for-testing")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The examination attempt is not finished yet and cannot be reset.');
    }

    public function test_admin_can_generate_a_visible_key_for_a_legacy_hash_only_registration_without_email(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule, $registration] = $this->registration(gmail: null);
        $service = app(ExaminationPasskeyService::class);
        $oldKey = $service->generateForSchedule($schedule)['keys'][0]['key'];
        DB::table('exam_registrations')->where('id', $registration->id)->update([
            'exam_passkey_encrypted' => null,
        ]);

        $generated = $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/passkeys/regenerate")
            ->assertOk();
        $newKey = $generated->json('data.key');

        $this->assertNotSame($oldKey, $newKey);
        $this->getJson("/api/examination-schedules/{$schedule->id}/passkeys")
            ->assertOk()
            ->assertJsonPath('data.0.exam_passkey', $newKey)
            ->assertJsonPath('data.0.passkey_exists', true);
        $this->assertNotEmpty(DB::table('exam_registrations')
            ->where('id', $registration->id)
            ->value('exam_passkey_encrypted'));
    }

    public function test_admin_can_generate_revoke_and_regenerate_one_time_key(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule, $registration] = $this->registration();

        $generated = $this->actingAs($admin)->postJson("/api/examination-schedules/{$schedule->id}/passkeys/generate")
            ->assertOk()
            ->assertJsonPath('data.generated', 1);
        $firstKey = $generated->json('data.keys.0.key');
        $this->assertNotEmpty($firstKey);

        $this->postJson("/api/examination-registrations/{$registration->id}/passkeys/revoke")
            ->assertOk();
        $this->assertSame('revoked', $registration->fresh()->passkey_status);

        $regenerated = $this->postJson("/api/examination-registrations/{$registration->id}/passkeys/regenerate")
            ->assertOk();
        $this->assertNotSame($firstKey, $regenerated->json('data.key'));
        $this->assertSame('not_sent', $registration->fresh()->passkey_status);
    }

    public function test_generate_endpoint_regenerate_flag_reissues_keys_and_replaces_previous_codes(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule, $registration] = $this->registration();

        $first = $this->actingAs($admin)
            ->postJson("/api/examination-schedules/{$schedule->id}/passkeys/generate", ['regenerate' => false])
            ->assertOk()
            ->assertJsonPath('data.generated', 1);
        $firstKey = $first->json('data.keys.0.key');

        // Idempotent: while a valid hash exists there is nothing new to hand out.
        $this->actingAs($admin)
            ->postJson("/api/examination-schedules/{$schedule->id}/passkeys/generate", ['regenerate' => false])
            ->assertOk()
            ->assertJsonPath('data.generated', 0)
            ->assertJsonCount(0, 'data.keys');

        // Reveal / re-issue: a fresh code is returned and the previous one stops working.
        $reissued = $this->actingAs($admin)
            ->postJson("/api/examination-schedules/{$schedule->id}/passkeys/generate", ['regenerate' => true])
            ->assertOk()
            ->assertJsonPath('data.generated', 1);
        $newKey = $reissued->json('data.keys.0.key');

        $service = app(ExaminationPasskeyService::class);
        $this->assertNotEmpty($newKey);
        $this->assertNotSame($firstKey, $newKey);
        $this->assertSame($service->hashPasskey($newKey), $registration->fresh()->exam_passkey_hash);
        $this->assertNull($service->findByPasskeyForSchedule($schedule, $firstKey));
        $this->atScheduleWindow($schedule, function () use ($service, $schedule, $newKey, $registration): void {
            $this->assertSame($registration->id, $service->findByPasskeyForSchedule($schedule, $newKey)?->id);
        });
    }

    public function test_batch_send_automatically_generates_and_previews_a_new_key(): void
    {
        Mail::fake();
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        [$schedule, $registration] = $this->registration();

        $response = $this->actingAs($admin)->postJson("/api/examination-schedules/{$schedule->id}/passkeys/send")
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $key = $response->json('data.keys.0.key');
        $this->assertNotEmpty($key);
        $this->assertSame('sent', $registration->fresh()->passkey_status);
        $this->assertSame(app(ExaminationPasskeyService::class)->hashPasskey($key), $registration->fresh()->exam_passkey_hash);
    }

    public function test_rescheduling_keeps_the_same_passkey_without_creating_delivery_or_queue_entries(): void
    {
        Mail::fake();
        Queue::fake();
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration(Carbon::tomorrow()->toDateString());
        $key = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $before = $registration->fresh();
        $target = $this->targetSchedule(Carbon::today()->addDays(3)->toDateString());
        $logCount = DB::table('exam_passkey_delivery_logs')->count();
        $registrationCount = ExaminationRegistration::query()->count();

        $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/reschedule", [
                'target_schedule_id' => $target->id,
                'reason' => 'Schedule conflict',
            ])
            ->assertOk();

        $after = $registration->fresh();
        $this->assertSame($before->exam_passkey_hash, $after->exam_passkey_hash);
        $this->assertSame($before->exam_passkey_encrypted, $after->exam_passkey_encrypted);
        $this->assertSame($registrationCount, ExaminationRegistration::query()->count());
        $this->assertSame($logCount, DB::table('exam_passkey_delivery_logs')->count());
        $this->assertSame($key, app(ExaminationPasskeyService::class)->revealPasskey($after->exam_passkey_encrypted));
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_rescheduling_sets_expiry_using_the_target_schedules_passkey_expiry_rule(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration(Carbon::tomorrow()->toDateString());
        app(ExaminationPasskeyService::class)->generateForSchedule($schedule);
        $target = $this->targetSchedule(Carbon::today()->addDays(3)->toDateString(), '17:30:00');
        $expectedExpiry = Carbon::parse($target->exam_date->toDateString().' '.$target->end_time);

        $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/reschedule", [
                'target_schedule_id' => $target->id,
                'reason' => 'Schedule conflict',
            ])
            ->assertOk();

        $this->assertTrue($expectedExpiry->equalTo($registration->fresh()->passkey_expires_at));
    }

    public function test_same_passkey_remains_valid_after_its_old_expiry_when_rescheduled_to_a_later_date(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration(Carbon::tomorrow()->toDateString());
        $key = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $target = $this->targetSchedule(Carbon::today()->addDays(3)->toDateString());
        $validationTime = Carbon::parse($schedule->exam_date->toDateString().' '.$schedule->end_time, 'Asia/Manila')->addSecond();

        Carbon::setTestNow($validationTime);

        try {
            $this->actingAs($admin)
                ->postJson("/api/examination-registrations/{$registration->id}/reschedule", [
                    'target_schedule_id' => $target->id,
                    'reason' => 'Schedule conflict',
                ])
                ->assertOk();

            Carbon::setTestNow(Carbon::parse($target->exam_date->toDateString().' 10:00:00', 'Asia/Manila'));
            $result = app(\App\Services\ExamSessionService::class)->validatePasskey(
                $this->joinableSessionFor($target),
                $key,
            );
            $this->assertSame($registration->id, $result['registration']->id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_same_passkey_is_rejected_for_the_old_schedule_after_rescheduling(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration(Carbon::tomorrow()->toDateString());
        $key = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $target = $this->targetSchedule(Carbon::today()->addDays(3)->toDateString());

        $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/reschedule", [
                'target_schedule_id' => $target->id,
                'reason' => 'Schedule conflict',
            ])
            ->assertOk();

        $oldSession = \App\Models\ExamSession::query()->create([
            'examination_schedule_id' => $schedule->id,
            'proctor_id' => $admin->id,
            'exam_code' => 'OLDKEY01',
            'status' => 'lobby_open',
        ]);

        $this->postJson('/api/v1/exam/passkey/validate', [
            'code' => $oldSession->exam_code,
            'passkey' => $key,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This key is for a different date.');
    }

    public function test_rescheduling_an_incomplete_used_key_rearms_it_and_clears_the_previous_attempt_state(): void
    {
        Mail::fake();
        Queue::fake();
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration(Carbon::tomorrow()->toDateString());
        $service = app(ExaminationPasskeyService::class);
        $key = $service->generateForSchedule($schedule)['keys'][0]['key'];
        $oldSession = \App\Models\ExamSession::query()->create([
            'examination_schedule_id' => $schedule->id,
            'proctor_id' => $admin->id,
            'exam_code' => 'USEDKEY1',
            'status' => 'lobby_open',
        ]);
        $registration->forceFill([
            'exam_session_id' => $oldSession->id,
            'participation_token' => (string) \Illuminate\Support\Str::uuid(),
            'lobby_status' => 'waiting',
            'joined_lobby_at' => now(),
            'last_heartbeat_at' => now(),
            'agreed_at' => now(),
            'passkey_status' => 'used',
            'passkey_used_at' => now(),
            'passkey_email_sent' => true,
            'passkey_sent_to_email' => 'student@example.test',
            'reconnect_allowed' => true,
            'reconnect_code' => '123456',
            'reconnect_code_expires_at' => now()->addMinutes(10),
        ])->save();
        $before = $registration->fresh();
        $target = $this->targetSchedule(Carbon::today()->addDays(3)->toDateString());
        $logCount = DB::table('exam_passkey_delivery_logs')->count();

        $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/reschedule", [
                'target_schedule_id' => $target->id,
                'reason' => 'Schedule conflict',
            ])
            ->assertOk();

        $after = $registration->fresh();
        $this->assertSame($before->exam_passkey_hash, $after->exam_passkey_hash);
        $this->assertSame($before->exam_passkey_encrypted, $after->exam_passkey_encrypted);
        $this->assertSame('sent', $after->passkey_status);
        $this->assertNull($after->passkey_used_at);
        $this->assertNull($after->exam_session_id);
        $this->assertNull($after->participation_token);
        $this->assertNull($after->lobby_status);
        $this->assertNull($after->joined_lobby_at);
        $this->assertNull($after->last_heartbeat_at);
        $this->assertNull($after->agreed_at);
        $this->assertFalse($after->reconnect_allowed);
        $this->assertNull($after->reconnect_code);
        $this->assertNull($after->reconnect_code_expires_at);
        $this->atScheduleWindow($target, function () use ($service, $target, $key, $registration): void {
            $this->assertSame($registration->id, $service->findByPasskeyForSchedule($target, $key)?->id);
        });
        $this->assertSame($logCount, DB::table('exam_passkey_delivery_logs')->count());
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_completed_registration_cannot_be_rescheduled_or_reused_for_a_retake(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration(Carbon::tomorrow()->toDateString());
        $key = app(ExaminationPasskeyService::class)->generateForSchedule($schedule)['keys'][0]['key'];
        $registration->forceFill([
            'attendance_status' => 'present',
            'result_status' => 'passed',
            'score' => 95,
            'passkey_status' => 'used',
        ])->save();
        $target = $this->targetSchedule(Carbon::today()->addDays(3)->toDateString());

        $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/reschedule", [
                'target_schedule_id' => $target->id,
                'reason' => 'Attempted retake',
            ])
            ->assertUnprocessable();

        $this->assertSame($schedule->id, $registration->fresh()->examination_schedule_id);
        $this->assertNull(app(ExaminationPasskeyService::class)->findByPasskeyForSchedule($schedule, $key));
    }

    public function test_finished_lobby_registration_cannot_be_rescheduled_even_without_a_recorded_result(): void
    {
        $admin = $this->admin();
        [$schedule, $registration] = $this->registration(Carbon::tomorrow()->toDateString());
        app(ExaminationPasskeyService::class)->generateForSchedule($schedule);
        $registration->forceFill([
            'lobby_status' => 'finished',
            'attendance_status' => 'pending',
            'result_status' => 'pending',
            'score' => null,
        ])->save();
        $target = $this->targetSchedule(Carbon::today()->addDays(3)->toDateString());

        $this->actingAs($admin)
            ->postJson("/api/examination-registrations/{$registration->id}/reschedule", [
                'target_schedule_id' => $target->id,
                'reason' => 'Attempted retake',
            ])
            ->assertUnprocessable();

        $this->assertSame($schedule->id, $registration->fresh()->examination_schedule_id);
    }

    public function test_test_schedule_is_hard_disabled_in_production_even_when_flag_is_true(): void
    {
        $admin = $this->admin();
        config(['app.env' => 'production', 'app.exam_test_mode' => true]);

        $this->actingAs($admin)
            ->getJson('/api/examination-schedules/test-mode')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
        $this->postJson('/api/examination-schedules/test-schedule', [])
            ->assertNotFound();
        $this->assertSame(0, ExaminationSchedule::query()->count());
    }

    public function test_test_schedule_reschedules_a_pending_registration_without_rotating_its_key(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'Asia/Manila'));
        try {
            config(['app.env' => 'testing', 'app.exam_test_mode' => true]);
            $admin = $this->admin();
            $proctorRole = Role::query()->firstOrCreate(['slug' => Role::SLUG_PROCTOR], ['name' => 'Examination Proctor']);
            $proctor = User::factory()->create(['role_id' => $proctorRole->id, 'status' => 'active']);
            [$sourceSchedule, $registration] = $this->registration('2026-10-04');
            $service = app(ExaminationPasskeyService::class);
            $key = $service->generateForSchedule($sourceSchedule)['keys'][0]['key'];
            $before = $registration->fresh();

            $this->actingAs($admin)
                ->getJson('/api/examination-schedules/test-students?search=Test%20Examinee')
                ->assertOk()
                ->assertJsonPath('data.0.registration_id', $registration->id);

            $response = $this->postJson('/api/examination-schedules/test-schedule', [
                'registration_ids' => [$registration->id],
                'proctor_id' => $proctor->id,
            ])->assertCreated()
                ->assertJsonPath('data.schedule.exam_date', '2026-10-06')
                ->assertJsonPath('data.schedule.expected_examinees', 1)
                ->assertJsonPath('data.schedule.room_name', 'Room 01');

            $schedule = ExaminationSchedule::query()->findOrFail($response->json('data.schedule.id'));
            $movedRegistration = $registration->fresh();
            $this->assertSame($proctor->id, $schedule->proctor_id);
            $this->assertSame(1, $schedule->rooms()->count());
            $this->assertSame($schedule->id, $movedRegistration->examination_schedule_id);
            $this->assertSame($sourceSchedule->id, $movedRegistration->previous_schedule_id);
            $this->assertSame($before->exam_passkey_hash, $movedRegistration->exam_passkey_hash);
            $this->assertSame($before->exam_passkey_encrypted, $movedRegistration->exam_passkey_encrypted);
            $this->assertTrue(app(ExaminationPasskeyService::class)->isWithinScheduleWindow(
                $schedule,
                Carbon::now('Asia/Manila'),
            ));

            $listResponse = $this->getJson("/api/examination-schedules/{$schedule->id}/passkeys")
                ->assertOk()
                ->assertJsonPath('data.0.exam_passkey', $key)
                ->assertJsonPath('data.0.passkey_status', 'not_sent')
                ->assertJsonPath('data.0.passkey_email_status', 'pending');
            $this->assertArrayNotHasKey('exam_passkey_hash', $listResponse->json('data.0'));
            $this->assertSame(1, ExaminationRegistration::query()->where('applicant_id', $registration->applicant_id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    private function admin(): User
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SLUG_ADMIN], ['name' => 'Administrator']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function targetSchedule(string $date, string $endTime = '16:00:00'): ExaminationSchedule
    {
        return ExaminationSchedule::query()->create([
            'title' => 'Rescheduled passkey exam',
            'exam_date' => $date,
            'start_time' => '09:00:00',
            'end_time' => $endTime,
            'time_slot' => '09:00 AM - 04:00 PM',
            'batch_code' => 'B2',
            'status' => 'scheduled',
        ]);
    }

    private function joinableSessionFor(ExaminationSchedule $schedule): \App\Models\ExamSession
    {
        $session = new \App\Models\ExamSession([
            'exam_code' => 'TESTCODE',
            'status' => 'lobby_open',
        ]);
        $session->setRelation('schedule', $schedule);

        return $session;
    }

    private function atScheduleWindow(ExaminationSchedule $schedule, callable $callback): void
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse($schedule->exam_date->toDateString().' 10:00:00', 'Asia/Manila'));
        try {
            $callback();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    private function registration(?string $date = null, string $endTime = '16:00:00', ?string $gmail = 'student@example.test'): array
    {
        $schedule = ExaminationSchedule::query()->create([
            'title' => 'Passkey security exam',
            'exam_date' => $date ?: Carbon::tomorrow()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => $endTime,
            'time_slot' => '09:00 AM - 04:00 PM',
            'batch_code' => 'B1',
            'status' => 'scheduled',
        ]);
        $applicant = Applicant::query()->create([
            'applicant_code' => 'APP-TEST-'.uniqid(),
            'name' => 'Test Examinee',
            'gmail' => $gmail,
        ]);
        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => 'pending',
            'result_status' => 'pending',
        ]);

        return [$schedule, $registration];
    }
}
