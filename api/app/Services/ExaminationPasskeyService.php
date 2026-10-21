<?php

namespace App\Services;

use App\Enums\ResultEmailStatus;
use App\Enums\ResultStatus;
use App\Mail\ExaminationPasskeyMail;
use App\Models\ExaminationPasskeyEmailLog;
use App\Models\EmailNotification;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\User;
use App\Support\PasskeyCipher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ExaminationPasskeyService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    private const SCHEDULE_TIME_ZONE = 'Asia/Manila';

    public function __construct(private PasskeyCipher $cipher)
    {
    }

    public function generateUnique(): string
    {
        do {
            $code = $this->randomCode();
        } while (ExaminationRegistration::query()->where('exam_passkey_hash', $this->hashPasskey($code))->exists());

        return $code;
    }

    public function hashPasskey(string $passkey): string
    {
        return hash('sha256', strtoupper(trim($passkey)));
    }

    public function encryptPasskey(string $passkey): string
    {
        return $this->cipher->encryptPasskey(strtoupper(trim($passkey)));
    }

    /** Decrypt a stored passkey for the admin dashboard. Returns null when unavailable. */
    public function revealPasskey(?string $encrypted): ?string
    {
        $encrypted = trim((string) $encrypted);
        if ($encrypted === '') {
            return null;
        }

        try {
            return strtoupper($this->cipher->decryptPasskey($encrypted));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
    * Decrypt a stored key for admin display; validation still enforces its schedule window.
     *
     * @return array{exam_passkey:?string, passkey_decryption_error:bool, passkey_unavailable_reason:?string}
     */
    public function adminPasskeyView(ExaminationRegistration $registration): array
    {
        $registration->loadMissing('schedule');
        $status = $registration->passkey_status ?: 'not_sent';

        if ($status === 'used') {
            return $this->unavailablePasskeyView('This examination key has already been used.');
        }
        if ($status === 'revoked') {
            return $this->unavailablePasskeyView('This examination key has been revoked.');
        }
        if (! in_array($status, ['not_sent', 'sent'], true)) {
            return $this->unavailablePasskeyView('This examination key is not available for validation.');
        }

        $encrypted = trim((string) $registration->exam_passkey_encrypted);
        if ($encrypted === '') {
            return $this->unavailablePasskeyView('No encrypted examination key is stored.', true);
        }
        if (! $registration->exam_passkey_hash) {
            return $this->unavailablePasskeyView('No examination-key verification hash is stored.', true);
        }

        try {
            $code = strtoupper($this->cipher->decryptPasskey($encrypted));
        } catch (RuntimeException) {
            return $this->unavailablePasskeyView('The stored examination key cannot be decrypted.', true);
        }

        if (preg_match('/\A[A-Z0-9]{8}\z/', $code) !== 1) {
            return $this->unavailablePasskeyView('The stored examination key is not a valid 8-character code.', true);
        }
        if (! hash_equals($registration->exam_passkey_hash, $this->hashPasskey($code))) {
            return $this->unavailablePasskeyView('The stored examination key does not match its verification hash.', true);
        }

        return [
            'exam_passkey' => $code,
            'passkey_decryption_error' => false,
            'passkey_unavailable_reason' => null,
        ];
    }

    /**
     * @return array{exam_passkey:null, passkey_decryption_error:bool, passkey_unavailable_reason:string}
     */
    private function unavailablePasskeyView(string $reason, bool $decryptionError = false): array
    {
        return [
            'exam_passkey' => null,
            'passkey_decryption_error' => $decryptionError,
            'passkey_unavailable_reason' => $reason,
        ];
    }

    public function scheduleExpiry(ExaminationSchedule $schedule): ?string
    {
        if (! $schedule->exam_date) return null;

        try {
            $date = Carbon::parse($schedule->exam_date, self::SCHEDULE_TIME_ZONE)->toDateString();

            return Carbon::parse($date.' '.($schedule->end_time ?: '23:59:59'), self::SCHEDULE_TIME_ZONE)
                ->toDateTimeString();
        } catch (\Throwable) {
            return Carbon::parse($schedule->exam_date, self::SCHEDULE_TIME_ZONE)
                ->endOfDay()
                ->toDateTimeString();
        }
    }

    public function scheduleWindowState(ExaminationSchedule $schedule, ?Carbon $at = null): string
    {
        $bounds = $this->scheduleWindowBounds($schedule);
        if (! $bounds) return 'invalid';

        $now = ($at ?? Carbon::now(self::SCHEDULE_TIME_ZONE))->copy()->setTimezone(self::SCHEDULE_TIME_ZONE);
        if ($now->lt($bounds['start']->copy()->subMinutes((int) config('examination.join_early_minutes', 30)))) {
            return 'not_started';
        }
        if ($now->gt($bounds['end'])) return 'expired';

        return 'valid';
    }

    public function scheduleWindowMessage(ExaminationSchedule $schedule, ?Carbon $at = null): string
    {
        $bounds = $this->scheduleWindowBounds($schedule);
        if (! $bounds) return 'This examination key has no valid schedule window.';

        $state = $this->scheduleWindowState($schedule, $at);
        $opensAt = $bounds['start']->copy()->subMinutes((int) config('examination.join_early_minutes', 30));

        return $state === 'not_started'
            ? 'Joining opens at '.$opensAt->format('g:i A').'.'
            : 'This examination schedule has ended.';
    }

    /** @return array{start:Carbon,end:Carbon}|null */
    private function scheduleWindowBounds(ExaminationSchedule $schedule): ?array
    {
        if (! $schedule->exam_date) return null;

        try {
            $date = Carbon::parse($schedule->exam_date, self::SCHEDULE_TIME_ZONE)->toDateString();
            $start = Carbon::parse($date.' '.($schedule->start_time ?: '00:00:00'), self::SCHEDULE_TIME_ZONE);
            $end = Carbon::parse($date.' '.($schedule->end_time ?: '23:59:59'), self::SCHEDULE_TIME_ZONE);
            if (! $end->gt($start)) return null;

            return [
                'start' => $start,
                'end' => $end,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function isWithinScheduleWindow(ExaminationSchedule $schedule, ?Carbon $at = null): bool
    {
        return $this->scheduleWindowState($schedule, $at) === 'valid';
    }

    private function issuePasskey(ExaminationRegistration $registration): string
    {
        $registration->loadMissing('schedule');
        $code = $this->generateUnique();
        $registration->forceFill([
            'exam_passkey_hash' => $this->hashPasskey($code),
            'exam_passkey_encrypted' => $this->encryptPasskey($code),
            'passkey_status' => 'not_sent',
            'passkey_sent_to_email' => null,
            'passkey_used_at' => null,
            'passkey_expires_at' => $this->scheduleExpiry($registration->schedule),
            'passkey_email_sent' => false,
            'passkey_email_sent_at' => null,
            'passkey_email_status' => ResultEmailStatus::Pending,
            'passkey_email_error' => null,
        ])->save();

        return $code;
    }

    /**
     * Generate a passkey unique against an in-memory set of fingerprints.
     *
     * @param  array<string, true>  $used  SHA-256 fingerprints already taken; the new one is added.
     */
    public function generateAgainst(array &$used): string
    {
        do {
            $code = $this->randomCode();
            $fingerprint = $this->hashPasskey($code);
        } while (isset($used[$fingerprint]));

        $used[$fingerprint] = true;

        return $code;
    }

    private function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return strtoupper($code);
    }

    public function ensurePasskey(ExaminationRegistration $registration): ExaminationRegistration
    {
        if ($registration->exam_passkey_hash
            && $registration->passkey_status !== 'revoked'
            && $registration->passkey_status !== 'used'
            && $registration->passkey_expires_at?->isFuture()
        ) {
            return $registration;
        }

        if (in_array($registration->passkey_status, ['used', 'revoked'], true)) return $registration;

        $this->issuePasskey($registration);

        return $registration->fresh();
    }

    /**
     * Issue examination keys for a schedule.
     *
     * Only hashes are stored on the registrations, so the plaintext code is
     * returned in `keys` exactly once — the caller must display it immediately.
     *
     * @param  bool  $regenerate  When true, re-issues a fresh key for every student who
     *                            has not already used theirs (the previous code, if any,
     *                            stops working). Used by the "Generate / reveal keys"
     *                            action so an admin can see and distribute the codes
     *                            again without the DB ever storing plaintext.
     * @return array{generated:int, already_had:int, total:int, keys:list<array{registration_id:int, name:?string, applicant_code:?string, key:string}>}
     */
    public function generateForSchedule(ExaminationSchedule $schedule, ?array $registrationIds = null, bool $regenerate = false): array
    {
        $generated = 0;
        $alreadyHad = 0;
        $keys = [];

        $schedule->registrations()->with('applicant')->when($registrationIds, fn ($query) => $query->whereIn('id', $registrationIds))
            ->orderBy('id')->chunkById(100, function ($regs) use (&$generated, &$alreadyHad, &$keys, $regenerate) {
            /** @var Collection<int, ExaminationRegistration> $regs */
            foreach ($regs as $reg) {
                // A used key can never be rotated — the student already took the exam.
                if ($reg->passkey_status === 'used') {
                    $alreadyHad++;
                    continue;
                }
                if (! $regenerate) {
                    if ($reg->passkey_status === 'revoked') {
                        $alreadyHad++;
                        continue;
                    }
                    // Skip only when the key is both valid AND still displayable. Rows
                    // created before the encrypted copy existed are re-issued so admins
                    // can finally see/distribute their codes.
                    if ($this->hasValidEncryptedPasskey($reg)) {
                        $alreadyHad++;
                        continue;
                    }
                }
                $code = $this->issuePasskey($reg);
                $keys[] = [
                    'registration_id' => $reg->id,
                    'name' => $reg->applicant?->name,
                    'applicant_code' => $reg->applicant?->applicant_code,
                    'key' => $code,
                ];
                $generated++;
            }
        });

        return [
            'generated' => $generated,
            'already_had' => $alreadyHad,
            'total' => $generated + $alreadyHad,
            'keys' => $keys,
        ];
    }

    /**
     * Rotate every passkey in a batch for admin verification. Used and revoked
     * registrations keep their status; completed attempts are not reopened.
     *
     * @return array{generated:int, total:int, keys:list<array{registration_id:int, name:?string, applicant_code:?string, key:string}>}
     */
    public function regenerateForAdminSchedule(ExaminationSchedule $schedule): array
    {
        $generated = 0;
        $keys = [];

        $schedule->registrations()->with('applicant')->orderBy('id')->chunkById(100, function ($registrations) use (&$generated, &$keys) {
            foreach ($registrations as $registration) {
                $entry = DB::transaction(function () use ($registration) {
                    $locked = ExaminationRegistration::query()
                        ->with(['applicant', 'schedule'])
                        ->lockForUpdate()
                        ->findOrFail($registration->id);
                    $previousStatus = $locked->passkey_status ?: 'not_sent';
                    $key = $this->generateUnique();
                    $payload = [
                        'exam_passkey_hash' => $this->hashPasskey($key),
                        'exam_passkey_encrypted' => $this->encryptPasskey($key),
                    ];

                    if (! in_array($previousStatus, ['used', 'revoked'], true)) {
                        $payload += [
                            'passkey_status' => 'not_sent',
                            'passkey_sent_to_email' => null,
                            'passkey_used_at' => null,
                            'passkey_expires_at' => $this->scheduleExpiry($locked->schedule),
                            'passkey_email_sent' => false,
                            'passkey_email_sent_at' => null,
                            'passkey_email_status' => ResultEmailStatus::Pending,
                            'passkey_email_error' => null,
                        ];
                    }

                    $locked->forceFill($payload)->save();

                    return [
                        'registration_id' => $locked->id,
                        'name' => $locked->applicant?->name,
                        'applicant_code' => $locked->applicant?->applicant_code,
                        'key' => $key,
                    ];
                });

                $keys[] = $entry;
                $generated++;
            }
        });

        return ['generated' => $generated, 'total' => $generated, 'keys' => $keys];
    }

    private function hasValidEncryptedPasskey(ExaminationRegistration $registration): bool
    {
        $encrypted = trim((string) $registration->exam_passkey_encrypted);
        if (! $registration->exam_passkey_hash || $encrypted === '' || ! $registration->passkey_expires_at?->isFuture()) {
            return false;
        }

        try {
            $plain = $this->cipher->decryptPasskey($encrypted);

            return hash_equals($registration->exam_passkey_hash, $this->hashPasskey($plain));
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{queued:int, sent:int, failed:int, skipped:int}
     */
    public function sendForSchedule(
        ExaminationSchedule $schedule,
        ?User $admin = null,
        ?array $registrationIds = null,
        array $keys = [],
    ): array
    {
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $queued = 0;
        $failures = [];
        $generatedKeys = [];
        $keyByRegistration = collect($keys)->mapWithKeys(fn (array $item) => [
            (int) ($item['registration_id'] ?? 0) => (string) ($item['key'] ?? ''),
        ])->all();

        $schedule->registrations()
            ->with('applicant')
            ->when($registrationIds, fn ($query) => $query->whereIn('id', $registrationIds))
            ->orderBy('id')
            ->chunkById(50, function ($regs) use ($admin, $keyByRegistration, &$sent, &$failed, &$skipped, &$queued, &$failures, &$generatedKeys) {
                /** @var Collection<int, ExaminationRegistration> $regs */
                foreach ($regs as $reg) {
                    $queued++;
                    try {
                        $providedKey = $keyByRegistration[$reg->id] ?? null;
                        $key = $this->sendOne($reg, $admin, $providedKey);
                        if ($key && ! $providedKey) {
                            $generatedKeys[] = [
                                'registration_id' => $reg->id,
                                'name' => $reg->applicant?->name,
                                'applicant_code' => $reg->applicant?->applicant_code,
                                'key' => $key,
                            ];
                        }
                        $sent++;
                    } catch (RuntimeException $e) {
                        if (str_contains(strtolower($e->getMessage()), 'no gmail')
                            || str_contains(strtolower($e->getMessage()), 'no email')
                        ) {
                            $skipped++;
                            $this->markFailed($reg, $e->getMessage(), $admin);
                        } else {
                            $failed++;
                            $this->markFailed($reg, $e->getMessage(), $admin);
                        }
                        $failures[] = $this->failureRow($reg, $e->getMessage());
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->markFailed($reg, $e->getMessage(), $admin);
                        $failures[] = $this->failureRow($reg, 'Unable to send examination key.');
                    }
                }
            });

        return [
            'queued' => $queued,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'failures' => $failures,
            'keys' => $generatedKeys,
        ];
    }

    /**
     * @return array{generated:int, already_had:int, total:int, schedules:int, keys:list<array{registration_id:int, name:?string, applicant_code:?string, key:string}>}
     */
    public function generateForDate(string $examDate, ?array $registrationIds = null, bool $regenerate = false): array
    {
        $schedules = ExaminationSchedule::query()->whereDate('exam_date', $examDate)->get();
        $generated = 0;
        $alreadyHad = 0;
        $keys = [];

        foreach ($schedules as $schedule) {
            $result = $this->generateForSchedule($schedule, $registrationIds, $regenerate);
            $generated += $result['generated'];
            $alreadyHad += $result['already_had'];
            $keys = [...$keys, ...$result['keys']];
        }

        return [
            'generated' => $generated,
            'already_had' => $alreadyHad,
            'total' => $generated + $alreadyHad,
            'schedules' => $schedules->count(),
            'keys' => $keys,
        ];
    }

    /**
     * Send examination keys for every registration on an exam date (all time slots).
     *
     * @return array{queued:int, sent:int, failed:int, skipped:int, schedules:int, exam_date:string}
     */
    public function sendForDate(string $examDate, ?User $admin = null, ?array $registrationIds = null, array $keys = []): array
    {
        $schedules = ExaminationSchedule::query()->whereDate('exam_date', $examDate)->orderBy('start_time')->get();
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $queued = 0;
        $failures = [];

        foreach ($schedules as $schedule) {
            $result = $this->sendForSchedule($schedule, $admin, $registrationIds, $keys);
            $sent += $result['sent'];
            $failed += $result['failed'];
            $skipped += $result['skipped'];
            $queued += $result['queued'];
            $failures = [...$failures, ...$result['failures']];
            $keys = [...$keys, ...$result['keys']];
        }

        return [
            'queued' => $queued,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'schedules' => $schedules->count(),
            'exam_date' => $examDate,
            'failures' => $failures,
            'keys' => $keys,
        ];
    }

    public function sendOne(ExaminationRegistration $registration, ?User $admin = null, ?string $oneTimeKey = null): string
    {
        $registration = $registration->fresh(['applicant', 'schedule']);
        $applicant = $registration->applicant;
        $email = $applicant?->notificationEmail();

        if (! $email) {
            EmailNotification::query()->create([
                'requested_by' => $admin?->id,
                'registration_id' => $registration->id,
                'schedule_id' => $registration->examination_schedule_id,
                'recipient_name' => $applicant?->name,
                'recipient_email' => null,
                'type' => 'passkey',
                'status' => 'failed',
                'error_details' => 'Student has no registered email address.',
            ]);
            throw new RuntimeException('Applicant has no Gmail address.');
        }

        if (in_array($registration->passkey_status, ['used', 'revoked'], true)) {
            throw new RuntimeException('This examination key cannot be sent because it is used or revoked.');
        }

        if ($oneTimeKey !== null) {
            if (
                preg_match('/\A[A-Z0-9]{8}\z/', strtoupper($oneTimeKey)) !== 1
                || ! hash_equals((string) $registration->exam_passkey_hash, $this->hashPasskey($oneTimeKey))
            ) {
                throw new RuntimeException('The one-time generated key is no longer current. Generate a new key.');
            }
            if (! $registration->passkey_expires_at?->isFuture()) {
                throw new RuntimeException('The examination key has expired. Generate a new key.');
            }
        } else {
            $oneTimeKey = $this->revealPasskey($registration->exam_passkey_encrypted);
            if (
                ! $oneTimeKey
                || preg_match('/\A[A-Z0-9]{8}\z/', $oneTimeKey) !== 1
                || ! $registration->exam_passkey_hash
                || ! hash_equals($registration->exam_passkey_hash, $this->hashPasskey($oneTimeKey))
                || ! $registration->passkey_expires_at?->isFuture()
            ) {
                $oneTimeKey = $this->issuePasskey($registration);
                $registration = $registration->fresh(['applicant', 'schedule']);
            }
        }

        $registration->increment('passkey_email_attempts');
        $notification = EmailNotification::query()->create([
            'requested_by' => $admin?->id,
            'registration_id' => $registration->id,
            'schedule_id' => $registration->examination_schedule_id,
            'recipient_name' => $applicant?->name,
            'recipient_email' => $email,
            'type' => 'passkey',
            'status' => 'processing',
        ]);
        try {
            app(ConfiguredMailer::class)->send($email, new ExaminationPasskeyMail($registration->fresh(['applicant', 'schedule']), $oneTimeKey));
            $notification->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        } catch (\Throwable $exception) {
            $classified = app(EmailFailureClassifier::class)->classify($exception);
            $notification->forceFill(['status' => 'failed', 'error_details' => $classified['message']])->save();
            throw $exception;
        }

        $registration->forceFill([
            'exam_passkey_hash' => $this->hashPasskey($oneTimeKey),
            'exam_passkey_encrypted' => $this->encryptPasskey($oneTimeKey),
            'passkey_status' => 'sent',
            'passkey_sent_to_email' => $email,
            'passkey_used_at' => null,
            'passkey_expires_at' => $this->scheduleExpiry($registration->schedule),
            'passkey_email_status' => ResultEmailStatus::Sent,
            'passkey_email_sent' => true,
            'passkey_email_sent_at' => now(),
            'passkey_email_error' => null,
        ])->save();

        ExaminationPasskeyEmailLog::query()->create([
            'examination_registration_id' => $registration->id,
            'sent_by' => $admin?->id,
            'email' => $email,
            'status' => ResultEmailStatus::Sent,
            'error_message' => null,
        ]);
        return $oneTimeKey;
    }

    public function resend(ExaminationRegistration $registration, ?User $admin = null): string
    {
        return $this->sendOne($registration, $admin);
    }

    public function regenerate(ExaminationRegistration $registration): array
    {
        $registration->loadMissing(['applicant', 'schedule']);
        if ($registration->passkey_status === 'used') {
            throw new RuntimeException('A used examination key cannot be regenerated.');
        }
        $key = $this->issuePasskey($registration);

        return [
            'registration_id' => $registration->id,
            'name' => $registration->applicant?->name,
            'applicant_code' => $registration->applicant?->applicant_code,
            'key' => $key,
        ];
    }

    /**
     * Reset a finished attempt and issue a new key for an explicitly requested admin test.
     *
     * @return array{registration_id:int, name:?string, applicant_code:?string, key:string}
     */
    public function resetAndReissueForTesting(ExaminationRegistration $registration): array
    {
        return DB::transaction(function () use ($registration): array {
            $registration = ExaminationRegistration::query()
                ->with(['applicant', 'schedule', 'examSession'])
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($registration->passkey_status !== 'used') {
                throw new RuntimeException('Only an already-used examination key can be reset for testing.');
            }

            $sessionStatus = $registration->examSession?->status?->value ?? $registration->examSession?->status;
            if (in_array($sessionStatus, ['open', 'lobby_open', 'in_progress'], true)) {
                throw new RuntimeException('This student is attached to an active examination session and cannot be reset.');
            }

            $resultStatus = $registration->result_status?->value ?? $registration->result_status;
            $attemptIsFinished = in_array($resultStatus, [
                ResultStatus::Passed->value,
                ResultStatus::Failed->value,
            ], true)
                || $registration->lobby_status === 'finished'
                || in_array($sessionStatus, ['ended', 'closed'], true);
            if (! $attemptIsFinished) {
                throw new RuntimeException('The examination attempt is not finished yet and cannot be reset.');
            }

            $registration->answers()->delete();
            $registration->forceFill([
                'exam_session_id' => null,
                'participation_token' => null,
                'lobby_status' => null,
                'joined_lobby_at' => null,
                'last_heartbeat_at' => null,
                'reconnect_allowed' => false,
                'reconnect_code' => null,
                'reconnect_code_expires_at' => null,
                'agreed_at' => null,
                'attendance_status' => 'pending',
                'result_status' => ResultStatus::Pending,
                'score' => null,
                'items_correct' => null,
                'items_total' => null,
                'grade_point' => null,
                'sync_status' => 'synced',
                'synced_at' => null,
                'sync_error' => null,
            ])->save();

            $key = $this->issuePasskey($registration);

            return [
                'registration_id' => $registration->id,
                'name' => $registration->applicant?->name,
                'applicant_code' => $registration->applicant?->applicant_code,
                'key' => $key,
            ];
        });
    }

    public function revoke(ExaminationRegistration $registration): void
    {
        if ($registration->passkey_status === 'used') {
            throw new RuntimeException('A used examination key cannot be revoked.');
        }
        $registration->forceFill([
            'exam_passkey_hash' => null,
            'exam_passkey_encrypted' => null,
            'passkey_status' => 'revoked',
            'passkey_email_sent' => false,
        ])->save();
    }

    public function markUsed(ExaminationRegistration $registration, int $sessionId): void
    {
        $locked = ExaminationRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();
        if ($locked->passkey_status === 'used') {
            if ((int) $locked->exam_session_id === $sessionId) return;
            throw new RuntimeException('This examination key has already been used.');
        }
        $locked->loadMissing('schedule');
        if ($locked->passkey_status === 'revoked') {
            throw new RuntimeException('This examination key has been revoked.');
        }
        if (! $locked->schedule || ! $this->isWithinScheduleWindow($locked->schedule)
            || ! $locked->passkey_expires_at
            || $locked->passkey_expires_at->isPast()
        ) {
            throw new RuntimeException($locked->schedule
                ? $this->scheduleWindowMessage($locked->schedule)
                : 'This examination key has no valid schedule window.');
        }
        $locked->forceFill(['passkey_status' => 'used', 'passkey_used_at' => now()])->save();
    }

    private function failureRow(ExaminationRegistration $registration, string $message): array
    {
        $registration->loadMissing('applicant');

        return [
            'registration_id' => $registration->id,
            'name' => $registration->applicant?->name,
            'applicant_code' => $registration->applicant?->applicant_code,
            'message' => Str::limit($message, 300),
        ];
    }

    public function markFailed(ExaminationRegistration $registration, string $message, ?User $admin = null): void
    {
        $registration->forceFill([
            'passkey_email_status' => ResultEmailStatus::Failed,
            'passkey_email_error' => Str::limit($message, 500),
        ])->save();

        ExaminationPasskeyEmailLog::query()->create([
            'examination_registration_id' => $registration->id,
            'sent_by' => $admin?->id,
            'email' => $registration->applicant?->notificationEmail(),
            'status' => ResultEmailStatus::Failed,
            'error_message' => Str::limit($message, 500),
        ]);
    }

    public function findByPasskeyForSchedule(ExaminationSchedule $schedule, string $passkey): ?ExaminationRegistration
    {
        $registration = ExaminationRegistration::query()
            ->with(['applicant', 'schedule', 'examSession'])
            ->where('examination_schedule_id', $schedule->id)
            ->where('exam_passkey_hash', $this->hashPasskey($passkey))
            ->whereIn('passkey_status', ['not_sent', 'sent'])
            ->where('passkey_expires_at', '>=', now())
            ->first();

        return $registration && $this->isWithinScheduleWindow($schedule) ? $registration : null;
    }

    public function findByPasskeyAnywhere(string $passkey): ?ExaminationRegistration
    {
        return ExaminationRegistration::query()
            ->with(['applicant', 'schedule', 'examSession'])
            ->where('exam_passkey_hash', $this->hashPasskey($passkey))
            ->first();
    }

    public function listForSchedule(
        ExaminationSchedule $schedule,
        ?string $search = null,
        ?string $emailStatus = null,
        ?string $joinStatus = null,
        int $perPage = 25,
        int $page = 1,
        ?string $status = null,
    ): LengthAwarePaginator {
        $query = ExaminationRegistration::query()
            ->with(['applicant', 'examSession', 'schedule'])
            ->where('examination_schedule_id', $schedule->id)
            ->orderBy('id');

        return $this->paginatePasskeyRows($query, $search, $emailStatus, $joinStatus, $perPage, $page, $status);
    }

    public function listForDate(
        string $examDate,
        ?string $search = null,
        ?string $emailStatus = null,
        ?string $joinStatus = null,
        int $perPage = 25,
        int $page = 1,
        ?int $scheduleId = null,
        ?string $status = null,
    ): LengthAwarePaginator {
        $scheduleIds = ExaminationSchedule::query()
            ->whereDate('exam_date', $examDate)
            ->when($scheduleId, fn ($q) => $q->whereKey($scheduleId))
            ->pluck('id');

        $query = ExaminationRegistration::query()
            ->with(['applicant', 'examSession', 'schedule'])
            ->whereIn('examination_schedule_id', $scheduleIds)
            ->orderBy('examination_schedule_id')
            ->orderBy('id');

        return $this->paginatePasskeyRows($query, $search, $emailStatus, $joinStatus, $perPage, $page, $status);
    }

    /**
     * Batch searches are typed loosely ("10:30-11:30") while slots are stored as
     * written labels ("10:30 AM - 11:30 AM"). Matching a range literally fails, so
     * a range is split and each side matched on its own.
     *
     * Terms inside a group must all match; the groups themselves are OR'd.
     *
     * @return list<list<string>>
     */
    private function scheduleSearchGroups(string $search): array
    {
        $groups = [[$search]];

        $parts = preg_split('/\s*(?:[-–—~]|\bto\b)\s*/iu', $search) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));

        if (count($parts) > 1) {
            $groups[] = $parts;
        }

        return $groups;
    }

    /**
     * Time slots available on an exam day, for the monitoring page batch filter.
     *
     * @return list<array{id:int,time_slot:string|null,batch_code:string|null,label:string}>
     */
    public function slotsForDate(string $examDate): array
    {
        return ExaminationSchedule::query()
            ->whereDate('exam_date', $examDate)
            ->orderBy('start_time')
            ->orderBy('id')
            ->get(['id', 'time_slot', 'batch_code', 'start_time', 'end_time'])
            ->map(fn (ExaminationSchedule $s) => [
                'id' => $s->id,
                'time_slot' => $s->time_slot,
                'batch_code' => $s->batch_code,
                'label' => trim(($s->time_slot ?: '').($s->batch_code ? " · {$s->batch_code}" : '')) ?: "Slot {$s->id}",
            ])
            ->values()
            ->all();
    }

    public function countsForSchedule(ExaminationSchedule $schedule): array
    {
        $registrations = $schedule->registrations();
        $statuses = (clone $registrations)->selectRaw('passkey_status, count(*) as aggregate')
            ->groupBy('passkey_status')->pluck('aggregate', 'passkey_status');

        return [
            'total' => (clone $registrations)->count(),
            'sent' => (clone $registrations)->where('passkey_email_sent', true)->count(),
            'not_sent' => (int) ($statuses['not_sent'] ?? 0),
            'used' => (int) ($statuses['used'] ?? 0),
            'revoked' => (int) ($statuses['revoked'] ?? 0),
        ];
    }

    public function countsForDate(string $examDate, ?int $scheduleId = null): array
    {
        $scheduleIds = ExaminationSchedule::query()
            ->whereDate('exam_date', $examDate)
            ->when($scheduleId, fn ($query) => $query->whereKey($scheduleId))
            ->pluck('id');
        $registrations = ExaminationRegistration::query()->whereIn('examination_schedule_id', $scheduleIds);
        $statuses = (clone $registrations)->selectRaw('passkey_status, count(*) as aggregate')
            ->groupBy('passkey_status')->pluck('aggregate', 'passkey_status');

        return [
            'total' => (clone $registrations)->count(),
            'sent' => (clone $registrations)->where('passkey_email_sent', true)->count(),
            'not_sent' => (int) ($statuses['not_sent'] ?? 0),
            'used' => (int) ($statuses['used'] ?? 0),
            'revoked' => (int) ($statuses['revoked'] ?? 0),
        ];
    }

    private function paginatePasskeyRows(
        $query,
        ?string $search,
        ?string $emailStatus,
        ?string $joinStatus,
        int $perPage,
        int $page,
        ?string $status,
    ): LengthAwarePaginator {
        if ($search = trim((string) $search)) {
            $scheduleGroups = $this->scheduleSearchGroups($search);

            $query->where(function ($q) use ($search, $scheduleGroups) {
                $q->where('passkey_status', 'like', "%{$search}%")
                    ->orWhere('passkey_email_status', 'like', "%{$search}%")
                    ->orWhereHas('applicant', function ($aq) use ($search) {
                        $aq->where('name', 'like', "%{$search}%")
                            ->orWhere('applicant_code', 'like', "%{$search}%")
                            ->orWhere('gmail', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('schedule', function ($sq) use ($scheduleGroups) {
                        $sq->where(function ($outer) use ($scheduleGroups) {
                            foreach ($scheduleGroups as $group) {
                                $outer->orWhere(function ($all) use ($group) {
                                    foreach ($group as $term) {
                                        $all->where(function ($field) use ($term) {
                                            $field->where('time_slot', 'like', "%{$term}%")
                                                ->orWhere('batch_code', 'like', "%{$term}%")
                                                ->orWhere('title', 'like', "%{$term}%");
                                        });
                                    }
                                });
                            }
                        });
                    });
            });
        }

        if ($emailStatus = trim((string) $emailStatus)) {
            $query->where('passkey_email_status', $emailStatus);
        }

        if ($status = trim((string) $status)) {
            $query->where('passkey_status', $status);
        }

        if ($joinStatus = trim((string) $joinStatus)) {
            if ($joinStatus === 'joined') {
                $query->whereNotNull('exam_session_id')
                    ->whereNotNull('lobby_status')
                    ->where('lobby_status', '!=', '');
            } elseif ($joinStatus === 'not_joined') {
                $query->where(function ($q) {
                    $q->whereNull('exam_session_id')
                        ->orWhereNull('lobby_status')
                        ->orWhere('lobby_status', '');
                });
            } else {
                $query->where('lobby_status', $joinStatus);
            }
        }

        return $query->paginate($perPage, ['*'], 'page', max(1, $page))->through(function (ExaminationRegistration $reg) {
            $applicant = $reg->applicant;
            $schedule = $reg->schedule;
            $passkeyView = $this->adminPasskeyView($reg);

            return [
                'registration_id' => $reg->id,
                'applicant_id' => $reg->applicant_id,
                'applicant_code' => $applicant?->applicant_code,
                'name' => $applicant?->name,
                'gmail' => $applicant?->notificationEmail(),
                'program' => $applicant?->course_applied,
                'schedule_id' => $reg->examination_schedule_id,
                'exam_title' => $schedule?->title ?: 'Entrance Examination',
                'exam_date' => optional($schedule?->exam_date)?->toDateString(),
                'time_slot' => $schedule?->time_slot,
                'batch_code' => $schedule?->batch_code,
                ...$passkeyView,
                'passkey_exists' => (bool) $reg->exam_passkey_hash,
                'passkey_encrypted_exists' => (bool) $reg->exam_passkey_encrypted,
                'passkey_email_sent' => (bool) $reg->passkey_email_sent,
                'passkey_email_sent_at' => optional($reg->passkey_email_sent_at)?->toIso8601String(),
                'passkey_sent_to_email' => $reg->passkey_sent_to_email,
                'passkey_status' => $reg->passkey_status ?: 'not_sent',
                'passkey_used_at' => optional($reg->passkey_used_at)?->toIso8601String(),
                'passkey_expires_at' => optional($reg->passkey_expires_at)?->toIso8601String(),
                'passkey_email_status' => $reg->passkey_email_status?->value ?? $reg->passkey_email_status,
                'passkey_email_attempts' => (int) $reg->passkey_email_attempts,
                'passkey_email_error' => $reg->passkey_email_error,
                'join_status' => $reg->lobby_status ?: 'not_joined',
                'exam_session_id' => $reg->exam_session_id,
                'attendance_status' => $reg->attendance_status?->value ?? $reg->attendance_status,
                'result_status' => $reg->result_status?->value ?? $reg->result_status,
                'score' => $reg->score,
            ];
        });
    }
}
