<?php

namespace App\Jobs;

use App\Models\ExaminationRegistration;
use App\Services\ExaminationPasskeyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SendRoomAssignmentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(public int $registrationId) {}

    public function handle(ExaminationPasskeyService $passkeys): void
    {
        $registration = ExaminationRegistration::with('schedule')->find($this->registrationId);
        if (! $registration || ! $registration->schedule?->rooms_published_at) {
            return;
        }
        // Explicit key prevents sendOne's legacy fallback from replacing a key.
        $key = $passkeys->revealPasskey($registration->exam_passkey_encrypted);
        if (! $key) {
            // First publication may precede initial passkey generation.
            if ($registration->exam_passkey_hash) {
                throw new RuntimeException('Existing passkey cannot be recovered. Room notification requires admin review.');
            }
            $passkeys->ensurePasskey($registration);
            $registration->refresh();
            $key = $passkeys->revealPasskey($registration->exam_passkey_encrypted);
        }
        try {
            $passkeys->sendOne($registration, null, $key);
        } catch (\Throwable $e) {
            $passkeys->markFailed($registration, $e->getMessage());
            throw $e;
        }
    }
}
