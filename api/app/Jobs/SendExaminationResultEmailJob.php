<?php

namespace App\Jobs;

use App\Models\ExaminationRegistration;
use App\Models\ResultEmailBatch;
use App\Services\ExaminationResultService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendExaminationResultEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $registrationId,
        public ?int $batchId = null,
    ) {
    }

    public function handle(ExaminationResultService $service): void
    {
        $registration = ExaminationRegistration::query()->with('applicant')->find($this->registrationId);
        if (! $registration) {
            return;
        }

        try {
            $service->sendOne($registration);
        } catch (\Throwable $e) {
            Log::warning('Result email failed', [
                'registration_id' => $this->registrationId,
                'exception' => get_class($e),
            ]);
            $service->markFailed($registration, $e->getMessage());
        }

        if ($this->batchId) {
            $batch = ResultEmailBatch::query()->find($this->batchId);
            if ($batch) {
                $service->refreshBatchCounters($batch);
            }
        }
    }
}
