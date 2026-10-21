<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ActivityLog;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Services\AdminResultSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResultSyncRescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin_sync.token' => 'test-result-sync-token']);
    }

    public function test_exam_results_endpoint_rejects_old_schedule_result_after_reschedule_and_logs_it(): void
    {
        [$applicant, $oldSchedule, $newSchedule, $registration] = $this->rescheduledRegistration();

        $response = $this->withToken('test-result-sync-token')->postJson('/api/v1/sync/exam-results', [
            'results' => [$this->resultPayload($applicant, $oldSchedule)],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonCount(0, 'data.accepted_ids')
            ->assertJsonPath('data.rejected.0.examination_schedule_id', $oldSchedule->id)
            ->assertJsonPath('data.rejected.0.reason', 'Result schedule does not match the current registration after rescheduling.');

        $this->assertSame('pending', $registration->fresh()->result_status->value);
        $this->assertNull($registration->fresh()->score);
        $this->assertDatabaseMissing('exam_registrations', [
            'applicant_id' => $applicant->id,
            'examination_schedule_id' => $oldSchedule->id,
        ]);
        $this->assertSame($newSchedule->id, $registration->fresh()->examination_schedule_id);
        $this->assertRejectedResultWasLogged($registration, $oldSchedule, $newSchedule);
    }

    public function test_offline_results_endpoint_rejects_old_schedule_result_after_reschedule_and_logs_it(): void
    {
        [$applicant, $oldSchedule, $newSchedule, $registration] = $this->rescheduledRegistration();

        $response = $this->withToken('test-result-sync-token')->postJson('/api/v1/sync/offline-results', [
            'results' => [$this->resultPayload($applicant, $oldSchedule)],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonCount(0, 'data.accepted_ids')
            ->assertJsonPath('data.rejected.0.examination_schedule_id', $oldSchedule->id)
            ->assertJsonPath('data.rejected.0.reason', 'Result schedule does not match the current registration after rescheduling.');

        $this->assertSame('pending', $registration->fresh()->result_status->value);
        $this->assertNull($registration->fresh()->score);
        $this->assertDatabaseMissing('exam_registrations', [
            'applicant_id' => $applicant->id,
            'examination_schedule_id' => $oldSchedule->id,
        ]);
        $this->assertSame($newSchedule->id, $registration->fresh()->examination_schedule_id);
        $this->assertRejectedResultWasLogged($registration, $oldSchedule, $newSchedule);
    }

    public function test_exam_results_endpoint_rejects_any_non_current_schedule_for_a_rescheduled_registration(): void
    {
        [$applicant, , $newSchedule, $registration] = $this->rescheduledRegistration();
        $unrelatedSchedule = $this->createSchedule('2026-10-24', 'OTHER');

        $response = $this->withToken('test-result-sync-token')->postJson('/api/v1/sync/exam-results', [
            'results' => [$this->resultPayload($applicant, $unrelatedSchedule)],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonCount(0, 'data.accepted_ids')
            ->assertJsonPath('data.rejected.0.examination_schedule_id', $unrelatedSchedule->id);

        $this->assertSame('pending', $registration->fresh()->result_status->value);
        $this->assertSame($newSchedule->id, $registration->fresh()->examination_schedule_id);
        $activity = ActivityLog::query()
            ->where('action', 'rescheduled_result_sync_rejected')
            ->where('subject_id', $registration->id)
            ->firstOrFail();
        $this->assertStringContainsString((string) $unrelatedSchedule->id, $activity->description);
    }

    public function test_lan_push_does_not_mark_a_rejected_result_as_synced(): void
    {
        config([
            'services.admin_sync.url' => 'https://admin.example.test/api/v1',
            'services.admin_sync.token' => 'test-result-sync-token',
        ]);
        Http::fake([
            'https://admin.example.test/api/v1/sync/exam-results' => Http::response([
                'success' => true,
                'message' => 'Results ingested.',
                'data' => [
                    'accepted_ids' => [],
                    'accepted_client_ids' => [],
                    'accepted_keys' => [],
                    'rejected' => [[
                        'client_local_id' => 'local-result-1',
                        'applicant_code' => 'SYNC-RESCHEDULE',
                        'examination_schedule_id' => 1,
                        'reason' => 'Result schedule does not match the current registration.',
                    ]],
                    'created' => 0,
                    'updated' => 0,
                ],
            ]),
        ]);

        [, , , $registration] = $this->rescheduledRegistration();
        $registration->forceFill([
            'score' => 90,
            'items_correct' => 45,
            'items_total' => 50,
            'sync_status' => 'pending',
        ])->save();

        app(AdminResultSyncService::class)->sync();

        $this->assertSame('failed', $registration->fresh()->sync_status);
        $this->assertSame(
            'Not accepted by admin server (applicant/schedule mismatch?).',
            $registration->fresh()->sync_error
        );
        Http::assertSent(fn ($request) => $request->url() === 'https://admin.example.test/api/v1/sync/exam-results');
    }

    private function assertRejectedResultWasLogged(
        ExaminationRegistration $registration,
        ExaminationSchedule $oldSchedule,
        ExaminationSchedule $newSchedule,
    ): void {
        $activity = ActivityLog::query()
            ->where('action', 'rescheduled_result_sync_rejected')
            ->where('subject_type', ExaminationRegistration::class)
            ->where('subject_id', $registration->id)
            ->firstOrFail();

        $this->assertStringContainsString((string) $oldSchedule->id, $activity->description);
        $this->assertStringContainsString((string) $newSchedule->id, $activity->description);
    }

    private function rescheduledRegistration(): array
    {
        $applicant = Applicant::query()->create([
            'applicant_code' => 'SYNC-RESCHEDULE-'.uniqid(),
            'name' => 'Rescheduled Examinee',
            'course_applied' => 'General',
        ]);
        $oldSchedule = $this->createSchedule('2026-10-10', 'OLD');
        $newSchedule = $this->createSchedule('2026-10-17', 'NEW');

        $registration = ExaminationRegistration::query()->create([
            'examination_schedule_id' => $newSchedule->id,
            'previous_schedule_id' => $oldSchedule->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => 'pending',
            'result_status' => 'pending',
            'passkey_status' => 'sent',
        ]);

        return [$applicant, $oldSchedule, $newSchedule, $registration];
    }

    private function resultPayload(Applicant $applicant, ExaminationSchedule $schedule): array
    {
        return [
            'applicant_code' => $applicant->applicant_code,
            'examination_schedule_id' => $schedule->id,
            'lan_registration_id' => 987654,
            'attendance_status' => 'present',
            'result_status' => 'passed',
            'score' => 90,
            'items_correct' => 45,
            'items_total' => 50,
        ];
    }

    private function createSchedule(string $date, string $batchCode): ExaminationSchedule
    {
        return ExaminationSchedule::query()->create([
            'title' => 'Result sync '.$batchCode,
            'exam_date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'time_slot' => '09:00 AM - 12:00 PM',
            'batch_code' => $batchCode,
            'status' => 'scheduled',
        ]);
    }
}
