<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Services\ExamDayPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExamDayPackFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fingerprint_changes_when_a_registration_passkey_hash_changes(): void
    {
        [, $registration] = $this->createRegistration();
        $before = $this->fingerprint();

        $registration->forceFill(['exam_passkey_hash' => hash('sha256', 'replacement-code')])->save();

        $this->assertNotSame($before, $this->fingerprint());
    }

    public function test_fingerprint_changes_when_a_registration_passkey_status_changes(): void
    {
        [, $registration] = $this->createRegistration();
        $before = $this->fingerprint();

        $registration->forceFill(['passkey_status' => 'sent'])->save();

        $this->assertNotSame($before, $this->fingerprint());
    }

    public function test_fingerprint_changes_when_a_registration_passkey_expiry_changes(): void
    {
        [, $registration] = $this->createRegistration();
        $before = $this->fingerprint();

        $registration->forceFill(['passkey_expires_at' => '2026-10-11 16:00:00'])->save();

        $this->assertNotSame($before, $this->fingerprint());
    }

    public function test_fingerprint_changes_when_a_registration_is_rescheduled(): void
    {
        [$oldSchedule, $registration] = $this->createRegistration();
        $newSchedule = $this->createSchedule('2026-10-12', 'B2');
        $before = $this->fingerprint();

        $registration->forceFill([
            'examination_schedule_id' => $newSchedule->id,
            'previous_schedule_id' => $oldSchedule->id,
            'rescheduled_at' => now(),
        ])->save();

        $this->assertNotSame($before, $this->fingerprint());
    }

    public function test_fingerprint_is_deterministic_when_pack_content_does_not_change(): void
    {
        $this->createRegistration();

        $first = $this->fingerprint();
        $second = $this->fingerprint();

        $this->assertSame($first, $second);
    }

    public function test_status_fingerprint_query_count_does_not_grow_with_registration_count(): void
    {
        $schedule = $this->createSchedule('2026-10-10', 'B1');
        foreach (range(1, 100) as $index) {
            $applicant = Applicant::query()->create([
                'applicant_code' => 'FRESHNESS-'.$index,
                'name' => 'Freshness Examinee '.$index,
            ]);
            ExaminationRegistration::query()->create([
                'examination_schedule_id' => $schedule->id,
                'applicant_id' => $applicant->id,
                'exam_passkey_hash' => hash('sha256', 'fake-passkey-'.$index),
                'passkey_status' => 'not_sent',
                'passkey_expires_at' => '2026-10-10 16:00:00',
                'attendance_status' => 'pending',
                'result_status' => 'pending',
            ]);
        }

        $selectCount = 0;
        DB::listen(function ($query) use (&$selectCount): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selectCount++;
            }
        });

        $this->fingerprint();

        $this->assertSame(4, $selectCount);
    }

    private function fingerprint(): string
    {
        return app(ExamDayPackService::class)
            ->status(examDate: '2026-10-10')['content_fingerprint'];
    }

    private function createRegistration(): array
    {
        $schedule = $this->createSchedule('2026-10-10', 'B1');
        $applicant = Applicant::query()->create([
            'applicant_code' => 'FRESHNESS-'.uniqid(),
            'name' => 'Freshness Examinee',
        ]);
        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id,
            'applicant_id' => $applicant->id,
            'exam_passkey_hash' => hash('sha256', 'fake-passkey'),
            'passkey_status' => 'not_sent',
            'passkey_expires_at' => '2026-10-10 16:00:00',
            'attendance_status' => 'pending',
            'result_status' => 'pending',
        ]);

        return [$schedule, $registration];
    }

    private function createSchedule(string $date, string $batchCode): ExaminationSchedule
    {
        return ExaminationSchedule::query()->create([
            'title' => 'Freshness exam '.$batchCode,
            'exam_date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '16:00:00',
            'time_slot' => '09:00 AM - 04:00 PM',
            'batch_code' => $batchCode,
            'status' => 'scheduled',
        ]);
    }
}
