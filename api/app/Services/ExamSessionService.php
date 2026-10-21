<?php

namespace App\Services;

use App\Enums\ApplicantStatus;
use App\Enums\AttendanceStatus;
use App\Enums\ExaminationStatus;
use App\Enums\ExamSessionStatus;
use App\Enums\ResultStatus;
use App\Models\Applicant;
use App\Models\ExaminationAnswer;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationRoom;
use App\Models\ExaminationSchedule;
use App\Models\ExaminationSetting;
use App\Models\ExaminationViolation;
use App\Models\ExamQuestion;
use App\Models\ExamSession;
use App\Models\QuestionBank;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ExamSessionService
{
    /**
     * Ensure a schedule has examination rooms (defaults to Room 01–03).
     *
     * @return Collection<int, ExaminationRoom>
     */
    public function ensureDefaultRooms(ExaminationSchedule $schedule, int $count = 3): Collection
    {
        $rooms = $schedule->rooms()->orderBy('id')->get();
        if ($schedule->rooms_managed || $rooms->isNotEmpty()) {
            return $rooms;
        }

        $capacity = max(20, (int) ceil(($schedule->expected_examinees ?: 60) / $count));

        for ($i = 1; $i <= $count; $i++) {
            ExaminationRoom::query()->create([
                'examination_schedule_id' => $schedule->id,
                'room_name' => sprintf('Room %02d', $i),
                'capacity' => $capacity,
                'proctor_id' => $schedule->proctor_id,
            ]);
        }

        return $schedule->rooms()->orderBy('id')->get();
    }

    /**
     * List rooms for a schedule with their active exam session (if any).
     *
     * @return list<array<string, mixed>>
     */
    public function roomsForSchedule(ExaminationSchedule $schedule): array
    {
        $rooms = $this->ensureDefaultRooms($schedule);

        return $rooms->map(function (ExaminationRoom $room) use ($schedule) {
            $session = ExamSession::query()
                ->where('examination_schedule_id', $schedule->id)
                ->where('examination_room_id', $room->id)
                ->whereIn('status', [ExamSessionStatus::LobbyOpen, ExamSessionStatus::InProgress])
                ->latest('id')
                ->first();

            $endedSession = null;
            if (! $session) {
                $endedSession = ExamSession::query()
                    ->where('examination_schedule_id', $schedule->id)
                    ->where('examination_room_id', $room->id)
                    ->where('status', ExamSessionStatus::Ended)
                    ->latest('id')
                    ->first();
            }

            if ($session) {
                $session->loadMissing('proctor:id,name');
            } elseif ($endedSession) {
                $endedSession->loadMissing('proctor:id,name');
            }

            $activeOrEnded = $session ?? $endedSession;
            $connected = $activeOrEnded
                ? ExaminationRegistration::query()
                    ->where('exam_session_id', $activeOrEnded->id)
                    ->whereNotNull('agreed_at')
                    ->count()
                : 0;

            $proctor = $activeOrEnded?->proctor;

            return [
                'id' => $room->id,
                'room_name' => $room->room_name,
                'capacity' => $room->capacity,
                'managed' => (bool) $schedule->rooms_managed,
                'assigned_proctor_id' => $room->proctor_id,
                'assigned_proctor_name' => $room->proctor?->name,
                'student_count' => $room->registrations()->count(),
                'owner_id' => $session?->proctor_id,
                'owner_name' => $session?->proctor?->name,
                'proctor_id' => $proctor?->id ?? $room->proctor_id,
                'proctor_name' => $proctor?->name ?? $room->proctor?->name,
                'exam_session_id' => $activeOrEnded?->id,
                'examination_code' => $session?->exam_code,
                'status' => $session?->status?->value
                    ?? ($endedSession ? ExamSessionStatus::Ended->value : 'idle'),
                'connected_count' => $connected,
                'finished_count' => $activeOrEnded
                    ? ExaminationRegistration::query()
                        ->where('exam_session_id', $activeOrEnded->id)
                        ->whereNotNull('agreed_at')
                        ->where('lobby_status', 'finished')
                        ->count()
                    : 0,
                'can_reopen' => $endedSession === null,
            ];
        })->values()->all();
    }

    /**
     * True when every room for this schedule has finished its exam session.
     */
    public function isBatchDone(ExaminationSchedule $schedule): bool
    {
        $rooms = $this->roomsForSchedule($schedule);
        if ($rooms === []) {
            return false;
        }

        return collect($rooms)->every(fn (array $room) => ($room['status'] ?? '') === ExamSessionStatus::Ended->value);
    }

    /**
     * @return array{batch_done: bool, ended_rooms: int, active_rooms: int, idle_rooms: int, total_rooms: int}
     */
    public function batchProgress(ExaminationSchedule $schedule): array
    {
        $rooms = $this->roomsForSchedule($schedule);
        $ended = collect($rooms)->where('status', ExamSessionStatus::Ended->value)->count();
        $active = collect($rooms)->whereIn('status', [
            ExamSessionStatus::LobbyOpen->value,
            ExamSessionStatus::InProgress->value,
        ])->count();
        $idle = collect($rooms)->where('status', 'idle')->count();

        return [
            'batch_done' => $rooms !== [] && $ended === count($rooms),
            'ended_rooms' => $ended,
            'active_rooms' => $active,
            'idle_rooms' => $idle,
            'total_rooms' => count($rooms),
        ];
    }

    public function openLobby(
        User $proctor,
        ExaminationSchedule $schedule,
        ?int $questionBankId = null,
        ?ExaminationRoom $room = null,
        ?string $wifiSsid = null,
        ?string $localServerIp = null,
        ?string $wifiBssid = null,
    ): ExamSession {
        return DB::transaction(function () use ($proctor, $schedule, $questionBankId, $room, $wifiSsid, $localServerIp, $wifiBssid) {
            User::whereKey($proctor->id)->lockForUpdate()->firstOrFail();
            $schedule = ExaminationSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($room) {
                $room = ExaminationRoom::whereKey($room->id)->lockForUpdate()->firstOrFail();
            }
            return $this->openLockedLobby($proctor, $schedule, $questionBankId, $room, $wifiSsid, $localServerIp, $wifiBssid);
        }, 3);
    }

    private function openLockedLobby(User $proctor, ExaminationSchedule $schedule, ?int $questionBankId,
        ?ExaminationRoom $room, ?string $wifiSsid, ?string $localServerIp, ?string $wifiBssid): ExamSession
    {
        if (! $proctor->isProctor() && ! $proctor->isAdmin()) {
            throw new RuntimeException('Only proctors can open an examination lobby.');
        }

        if (! $room) {
            throw new RuntimeException(
                'Select a room before opening the lobby. Each room has its own examination code and QR.'
            );
        }

        if ((int) $room->examination_schedule_id !== (int) $schedule->id) {
            throw new RuntimeException('Room does not belong to this examination schedule.');
        }

        if (! $proctor->isAdmin() && $room->proctor_id && (int) $room->proctor_id !== (int) $proctor->id) {
            throw new RuntimeException("{$room->room_name} is assigned to {$room->proctor?->name}.");
        }
        if (! $proctor->isAdmin() && $schedule->rooms()->where('proctor_id', $proctor->id)
            ->where('id', '!=', $room->id)->exists()) {
            throw new RuntimeException('You can only open your assigned examination room.');
        }
        if (ExamSession::where('proctor_id', $proctor->id)
            ->whereIn('status', ['open', 'lobby_open', 'in_progress'])
            ->where('examination_room_id', '!=', $room->id)->exists()) {
            throw new RuntimeException('You already manage another examination room. Release it before opening this room.');
        }
        if ($schedule->rooms_managed && ! $schedule->rooms_published_at) {
            throw new RuntimeException('Room assignments must be published before opening the examination.');
        }
        if ($room->room_id) {
            \App\Models\Room::whereKey($room->room_id)->lockForUpdate()->firstOrFail();
            if (ExamSession::whereHas('room', fn ($q) => $q->where('room_id', $room->room_id))
                ->where('examination_room_id', '!=', $room->id)
                ->whereIn('status', ['open', 'lobby_open', 'in_progress'])->exists()) {
                throw new RuntimeException('This physical room is still occupied by another examination.');
            }
        }

        $passkeys = app(ExaminationPasskeyService::class);
        if (! $passkeys->isWithinScheduleWindow($schedule)) {
            throw new RuntimeException($passkeys->scheduleWindowMessage($schedule));
        }

        $bank = $questionBankId
            ? QuestionBank::query()->findOrFail($questionBankId)
            : QuestionBank::query()->where('is_active', true)->first();

        if (! $bank) {
            throw new RuntimeException('No active question bank is available. Ask an admin to activate one.');
        }

        $selectedCount = ExamQuestion::query()
            ->where('is_selected_for_exam', true)
            ->where('status', 'active')
            ->whereHas('subject', fn ($q) => $q->where('question_bank_id', $bank->id))
            ->count();

        if ($selectedCount < 1) {
            throw new RuntimeException('The selected question bank has no exam questions.');
        }

        // One active lobby per room under the schedule.
        $existing = ExamSession::query()
            ->where('examination_schedule_id', $schedule->id)
            ->where('examination_room_id', $room->id)
            ->whereIn('status', [ExamSessionStatus::LobbyOpen, ExamSessionStatus::InProgress])
            ->latest('id')
            ->first();

        // Reuse the open lobby; refresh LAN host IP / Wi‑Fi when the proctor phone reports them.
        if ($existing) {
            if (! $existing->isOwnedBy($proctor)) {
                throw new RuntimeException("{$room->room_name} is currently managed by {$existing->proctor?->name}.");
            }
            $patch = [];
            if ($wifiSsid) {
                $patch['wifi_ssid'] = $wifiSsid;
            }
            if ($wifiBssid) {
                $patch['wifi_bssid'] = $wifiBssid;
            }
            if ($localServerIp) {
                $patch['local_server_ip'] = $localServerIp;
            }
            if ($patch !== []) {
                $existing->update($patch);
            }

            return $existing->fresh(['schedule', 'room', 'questionBank', 'proctor']);
        }

        // Once a room session has ended for this schedule, it cannot be reopened.
        $alreadyEnded = ExamSession::query()
            ->where('examination_schedule_id', $schedule->id)
            ->where('examination_room_id', $room->id)
            ->where('status', ExamSessionStatus::Ended)
            ->exists();

        if ($alreadyEnded) {
            throw new RuntimeException(
                'This room examination has already ended and cannot be opened again.'
            );
        }

        $session = $this->createUniqueSession([
            'examination_schedule_id' => $schedule->id,
            'examination_room_id' => $room->id,
            'question_bank_id' => $bank->id,
            'proctor_id' => $proctor->id,
            'status' => ExamSessionStatus::LobbyOpen,
            'wifi_ssid' => $wifiSsid,
            'wifi_bssid' => $wifiBssid,
            'local_server_ip' => $localServerIp,
        ], $room);

        if ($schedule->status === ExaminationStatus::Scheduled) {
            $schedule->forceFill(['status' => ExaminationStatus::Ongoing])->save();
        }

        return $session->load(['schedule', 'room', 'questionBank', 'proctor']);
    }

    public function regenerateCode(ExamSession $session): ExamSession
    {
        if ($session->status === ExamSessionStatus::Ended) {
            throw new RuntimeException('Cannot regenerate code for an ended session.');
        }

        $session->loadMissing('room');

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $session->forceFill([
                'exam_code' => $this->generateUniqueCode($session->room),
            ]);

            try {
                $session->save();

                return $session->fresh(['schedule', 'room', 'questionBank', 'proctor']);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintCollision($e)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not generate a unique exam code for this open session.');
    }

    /**
     * Proctor phone changed Wi‑Fi / DHCP: update stored LAN host IP (and SSID).
     * Optionally mint a new exam code when nobody has joined yet so the QR matches.
     *
     * @return array{session: ExamSession, code_regenerated: bool, joined_count: int}
     */
    public function updateNetwork(
        ExamSession $session,
        ?string $localServerIp,
        ?string $wifiSsid = null,
        bool $regenerateIfEmpty = true,
        ?string $wifiBssid = null,
    ): array {
        if (in_array($session->status, [ExamSessionStatus::Ended, ExamSessionStatus::Closed], true)) {
            throw new RuntimeException('Cannot update network for an ended or closed session.');
        }

        $joinedCount = ExaminationRegistration::query()
            ->where('exam_session_id', $session->id)
            ->count();

        $payload = [];
        if ($localServerIp !== null && $localServerIp !== '') {
            $payload['local_server_ip'] = $localServerIp;
        }
        if ($wifiSsid !== null) {
            $payload['wifi_ssid'] = $wifiSsid !== '' ? $wifiSsid : null;
        }
        if ($wifiBssid !== null) {
            $payload['wifi_bssid'] = $wifiBssid !== '' ? $wifiBssid : null;
        }

        $codeRegenerated = false;
        if (
            $regenerateIfEmpty
            && $joinedCount === 0
            && $session->status === ExamSessionStatus::LobbyOpen
        ) {
            $session->loadMissing('room');
            $payload['exam_code'] = $this->generateUniqueCode($session->room);
            $codeRegenerated = true;
        }

        if ($payload !== []) {
            try {
                $session->forceFill($payload)->save();
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintCollision($e)) {
                    throw $e;
                }

                $session->loadMissing('room');
                $payload['exam_code'] = $this->generateUniqueCode($session->room);
                $session->forceFill($payload)->save();
            }
        }

        return [
            'session' => $session->fresh(['schedule', 'room', 'questionBank', 'proctor']),
            'code_regenerated' => $codeRegenerated,
            'joined_count' => $joinedCount,
        ];
    }

    public function start(ExamSession $session): ExamSession
    {
        if ($session->status !== ExamSessionStatus::LobbyOpen) {
            throw new RuntimeException('Only a lobby session can be started.');
        }

        $session->forceFill([
            'status' => ExamSessionStatus::InProgress,
            'started_at' => now(),
        ])->save();

        ExaminationRegistration::query()
            ->where('exam_session_id', $session->id)
            ->where('lobby_status', 'waiting')
            ->update([
                'lobby_status' => 'taking_exam',
                'last_heartbeat_at' => now(),
            ]);

        return $session->fresh(['schedule', 'room', 'questionBank', 'proctor']);
    }

    /**
     * Close a lobby that never started — room can be opened again later.
     * Unlike end(), this does not mark the session as ended.
     */
    public function closeLobby(ExamSession $session): void
    {
        if ($session->status !== ExamSessionStatus::LobbyOpen) {
            throw new RuntimeException(
                'Only an open lobby can be closed. Use End Examination if the exam has already started.'
            );
        }

        $this->releasePendingReservations($session);

        ExaminationRegistration::query()
            ->where('exam_session_id', $session->id)
            ->whereIn('lobby_status', ['waiting', 'connected', 'disconnected'])
            ->update([
                'exam_session_id' => null,
                'lobby_status' => null,
                'last_heartbeat_at' => null,
            ]);

        // Mark the session as closed rather than deleting it so the record can
        // be inspected later and so that closing the lobby remains distinct from
        // ending the examination. Closed sessions are treated as 'idle' by the
        // room listing code and can be reopened by creating a new lobby.
        $session->forceFill([
            'status' => ExamSessionStatus::Closed,
            'started_at' => null,
            'ended_at' => null,
        ])->save();
    }

    /**
     * Remove a registration from the current lobby (kick/remove applicant).
     * This clears the registration's association with the exam session so the
     * student can rejoin later or pick another room.
     */
    public function removeRegistration(ExaminationRegistration $registration, string $reason = 'proctor_removed'): void
    {
        $registration->forceFill([
            'exam_session_id' => null,
            'lobby_status' => 'terminated',
            'joined_lobby_at' => null,
            'last_heartbeat_at' => null,
            'reconnect_allowed' => false,
            'reconnect_code' => null,
            'reconnect_code_expires_at' => null,
        ])->save();
    }

    public function end(ExamSession $session, bool $forceSubmitRemaining = true): ExamSession
    {
        $this->releasePendingReservations($session);

        if ($forceSubmitRemaining && $session->status !== ExamSessionStatus::Ended) {
            $this->forceCompleteRemaining($session, 'exam_ended');
        }

        $session->forceFill([
            'status' => ExamSessionStatus::Ended,
            'ended_at' => now(),
            'sync_status' => 'pending',
            // Expire the code so it cannot be reused after the session closes.
            'exam_code' => 'ENDED-'.$session->id.'-'.strtoupper(Str::random(4)),
        ])->save();

        return $session->fresh(['schedule', 'room', 'questionBank', 'proctor']);
    }

    private function releasePendingReservations(ExamSession $session): void
    {
        ExaminationRegistration::query()
            ->where('exam_session_id', $session->id)
            ->whereNull('agreed_at')
            ->get()
            ->each(function (ExaminationRegistration $registration): void {
                $this->releasePendingReservation($registration);
            });
    }

    private function releasePendingReservation(ExaminationRegistration $registration): void
    {
        $updates = [
            'exam_session_id' => null,
            'participation_token' => null,
            'pending_device_id' => null,
            'lobby_status' => null,
            'joined_lobby_at' => null,
            'last_heartbeat_at' => null,
            'reconnect_allowed' => false,
            'reconnect_code' => null,
            'reconnect_code_expires_at' => null,
        ];

        if ($registration->passkey_status === 'used') {
            $updates['passkey_status'] = $registration->passkey_email_sent ? 'sent' : 'not_sent';
            $updates['passkey_used_at'] = null;
        }

        $registration->forceFill($updates)->save();
    }

    private function isPendingPasskeyReservationExpired(ExaminationRegistration $registration): bool
    {
        if (
            $registration->agreed_at
            || $registration->passkey_status !== 'used'
            || ! $registration->exam_session_id
            || ! $registration->passkey_used_at
        ) {
            return false;
        }

        $timeoutMinutes = max(1, (int) config('exam_session.pending_agreement_timeout_minutes', 15));

        return $registration->passkey_used_at->lte(now()->subMinutes($timeoutMinutes));
    }

    private function releaseExpiredPendingPasskeyReservation(int $registrationId): bool
    {
        return DB::transaction(function () use ($registrationId): bool {
            $registration = ExaminationRegistration::query()
                ->whereKey($registrationId)
                ->lockForUpdate()
                ->first();

            if (! $registration || ! $this->isPendingPasskeyReservationExpired($registration)) {
                return false;
            }

            $this->releasePendingReservation($registration);

            return true;
        }, 3);
    }

    /**
     * Grade and finish every student still actively taking (or waiting in) the exam.
     */
    public function forceCompleteRemaining(ExamSession $session, string $reason = 'time_expired'): int
    {
        $results = app(ExaminationResultService::class);
        $completed = 0;

        $registrations = ExaminationRegistration::query()
            ->where('exam_session_id', $session->id)
            ->whereIn('lobby_status', ['waiting', 'connected', 'taking_exam', 'warning', 'disconnected'])
            ->get();

        foreach ($registrations as $registration) {
            if ($this->isExamCompleted($registration)) {
                continue;
            }

            $answers = ExaminationAnswer::query()
                ->where('examination_registration_id', $registration->id)
                ->get()
                ->map(fn (ExaminationAnswer $a) => [
                    'exam_question_id' => $a->exam_question_id,
                    'selected_answer' => $a->selected_answer,
                ])
                ->values()
                ->all();

            $results->submitAnswers($registration, $answers);
            $registration->refresh();
            $registration->forceFill([
                'lobby_status' => 'finished',
            ])->save();
            $completed++;
        }

        return $completed;
    }

    /**
     * When every examinee has finished, close the session automatically.
     */
    public function maybeEndWhenAllFinished(ExamSession $session): ExamSession
    {
        $session->refresh();
        if ($session->status !== ExamSessionStatus::InProgress) {
            return $session;
        }

        $active = ExaminationRegistration::query()
            ->where('exam_session_id', $session->id)
            ->whereIn('lobby_status', ['waiting', 'connected', 'taking_exam', 'warning', 'disconnected'])
            ->count();

        $joined = ExaminationRegistration::query()
            ->where('exam_session_id', $session->id)
            ->whereNotNull('agreed_at')
            ->count();

        if ($joined > 0 && $active === 0) {
            return $this->end($session, forceSubmitRemaining: false);
        }

        return $session;
    }

    /**
     * Auto-submit and end when the configured duration elapses after start.
     */
    public function syncExpiredSession(ExamSession $session): ExamSession
    {
        $session->refresh();
        if ($session->status !== ExamSessionStatus::InProgress || ! $session->started_at) {
            return $session;
        }

        $duration = max(1, (int) ExaminationSetting::current()->duration_minutes);
        $endsAt = $session->started_at->copy()->addMinutes($duration);

        if (now()->greaterThanOrEqualTo($endsAt)) {
            return $this->end($session, forceSubmitRemaining: true);
        }

        return $session;
    }

    public function remainingSeconds(ExamSession $session): ?int
    {
        if ($session->status !== ExamSessionStatus::InProgress || ! $session->started_at) {
            return null;
        }

        $duration = max(1, (int) ExaminationSetting::current()->duration_minutes);
        $endsAt = $session->started_at->copy()->addMinutes($duration);

        return max(0, $endsAt->getTimestamp() - now()->getTimestamp());
    }

    public function durationMinutes(): int
    {
        return max(1, (int) ExaminationSetting::current()->duration_minutes);
    }

    public function findByCode(string $code, bool $joinableOnly = true): ?ExamSession
    {
        $normalized = $this->normalizeExamCode($code);

        $query = ExamSession::query()
            ->with(['schedule', 'room', 'questionBank', 'proctor'])
            ->whereRaw('UPPER(exam_code) = ?', [$normalized]);

        if ($joinableOnly) {
            $settings = ExaminationSetting::current();
            if ($settings->allow_late_entry) {
                $query->whereIn('status', [
                    ExamSessionStatus::LobbyOpen,
                    ExamSessionStatus::InProgress,
                ]);
            } else {
                $query->where('status', ExamSessionStatus::LobbyOpen);
            }
        } else {
            $query->whereIn('status', [
                ExamSessionStatus::LobbyOpen,
                ExamSessionStatus::InProgress,
                ExamSessionStatus::Ended,
            ]);
        }

        return $query->first();
    }

    /**
     * Extract the typed/scan exam code from plain text or METCC QR JSON.
     */
    public function normalizeExamCode(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && str_starts_with($raw, '{')) {
            try {
                $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $raw = (string) ($parsed['examinationCode']
                    ?? $parsed['exam_code']
                    ?? $parsed['code']
                    ?? $raw);
            } catch (\Throwable) {
                // keep raw
            }
        }

        return strtoupper(trim($raw));
    }

    /**
     * QR payload is unique per room session (code + room + session ids).
     * Typed join still uses examinationCode alone.
     */
    public function qrPayload(ExamSession $session): string
    {
        $session->loadMissing('room');

        return json_encode([
            'v' => 1,
            'type' => 'metcc_exam',
            'code' => $session->exam_code,
            'examinationCode' => $session->exam_code,
            'exam_session_id' => $session->id,
            'room_id' => $session->examination_room_id,
            'room_name' => $session->room?->room_name,
            'schedule_id' => $session->examination_schedule_id,
            // LAN host hints so examinees can refresh after proctor Wi‑Fi/DHCP change.
            'h' => $session->local_server_ip,
            'host' => $session->local_server_ip,
            'p' => 9777,
            'port' => 9777,
            'w' => $session->wifi_ssid,
            'wifi_ssid' => $session->wifi_ssid,
        ], JSON_UNESCAPED_SLASHES) ?: (string) $session->exam_code;
    }

    public function findActiveForRoom(ExaminationSchedule $schedule, ExaminationRoom $room): ?ExamSession
    {
        return ExamSession::query()
            ->with(['schedule', 'room', 'questionBank', 'proctor'])
            ->where('examination_schedule_id', $schedule->id)
            ->where('examination_room_id', $room->id)
            ->whereIn('status', [ExamSessionStatus::LobbyOpen, ExamSessionStatus::InProgress])
            ->latest('id')
            ->first();
    }

    /**
     * Reserve a student after QR scan without adding them to the lobby before consent.
     */
    public function claim(ExamSession $session, Applicant $applicant): ExaminationRegistration
    {
        if (! $session->isJoinable()) {
            $lateAllowed = (bool) ExaminationSetting::current()->allow_late_entry;
            throw new RuntimeException(
                $session->status === ExamSessionStatus::InProgress && ! $lateAllowed
                    ? 'The examination has already started. Late entry is not allowed.'
                    : 'This examination session is not accepting students.'
            );
        }

        return DB::transaction(function () use ($session, $applicant) {
            $registration = ExaminationRegistration::query()
                ->where('examination_schedule_id', $session->examination_schedule_id)
                ->where('applicant_id', $applicant->id)
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                throw new RuntimeException('You are not registered for this examination schedule.');
            }

            $this->assertAssignedRoom($session, $registration);

            $registration->load('examSession');

            if ($this->isExamCompleted($registration)) {
                throw new RuntimeException('This student has already submitted the examination.');
            }

            if ($registration->exam_session_id && ! $registration->agreed_at) {
                if ((int) $registration->exam_session_id === (int) $session->id) {
                    return $registration->fresh(['applicant', 'schedule', 'examSession']);
                }

                throw new RuntimeException('This student is already reserved for another examination room.');
            }

            if ($this->isActivelyClaimed($registration)) {
                $claimedSessionId = (int) $registration->exam_session_id;
                if ($claimedSessionId === (int) $session->id) {
                    // Duplicate scan of the same student in the same room — idempotent.
                    return $registration->fresh(['applicant', 'schedule', 'examSession']);
                }

                $claimedScheduleId = (int) ($registration->examSession?->examination_schedule_id
                    ?? $registration->examination_schedule_id);

                if ($claimedScheduleId !== (int) $session->examination_schedule_id) {
                    throw new RuntimeException('This student is already checked in for another examination schedule.');
                }

                // Same schedule, different room — move claim to the scanned room.
            }

            $registration->forceFill([
                'exam_session_id' => $session->id,
                'participation_token' => $registration->participation_token ?: (string) Str::uuid(),
                'lobby_status' => null,
                'joined_lobby_at' => null,
            ])->save();

            return $registration->fresh(['applicant', 'schedule', 'examSession']);
        });
    }

    /**
     * Release a soft claim, or cancel a waiting-lobby registration before the exam starts.
     * Does not allow cancel once the student is taking / finished / terminated.
     */
    public function releaseClaim(ExamSession $session, Applicant $applicant): void
    {
        DB::transaction(function () use ($session, $applicant) {
            $registration = ExaminationRegistration::query()
                ->where('examination_schedule_id', $session->examination_schedule_id)
                ->where('applicant_id', $applicant->id)
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                return;
            }

            if ($this->isExamCompleted($registration)) {
                throw new RuntimeException('Cannot cancel — this examination was already submitted.');
            }

            if (in_array($registration->lobby_status, ['taking_exam', 'warning'], true)) {
                throw new RuntimeException('Cannot cancel registration after the examination has started.');
            }

            $pendingAgreement = $registration->exam_session_id
                && (int) $registration->exam_session_id === (int) $session->id
                && ! $registration->agreed_at
                && $registration->lobby_status === null;
            if (! $pendingAgreement && ! in_array($registration->lobby_status, ['connected', 'waiting'], true)) {
                return;
            }

            if ($registration->exam_session_id
                && (int) $registration->exam_session_id !== (int) $session->id
            ) {
                throw new RuntimeException('This student is checked in on a different room session.');
            }

            $registration->forceFill([
                'exam_session_id' => null,
                'participation_token' => null,
                'pending_device_id' => null,
                'lobby_status' => null,
                'joined_lobby_at' => null,
                'agreed_at' => null,
                'last_heartbeat_at' => null,
                'reconnect_allowed' => false,
                'reconnect_code' => null,
                'reconnect_code_expires_at' => null,
            ])->save();

            if ($pendingAgreement && $registration->passkey_status === 'used') {
                $registration->forceFill([
                    'passkey_status' => $registration->passkey_email_sent ? 'sent' : 'not_sent',
                    'passkey_used_at' => null,
                ])->save();
            }
        });
    }

    public function join(ExamSession $session, Applicant $applicant, ?string $gmail = null): ExaminationRegistration
    {
        if (! $session->isJoinable()) {
            $lateAllowed = (bool) ExaminationSetting::current()->allow_late_entry;
            throw new RuntimeException(
                $session->status === ExamSessionStatus::InProgress && ! $lateAllowed
                    ? 'The examination has already started. Late entry is not allowed.'
                    : 'This examination session is not accepting students.'
            );
        }

        if ($gmail) {
            $gmail = Str::lower(trim($gmail));
            if (! preg_match('/^[a-z0-9._%+\-]+@gmail\.com$/i', $gmail)) {
                throw new RuntimeException('Please enter a valid Gmail address.');
            }
            $applicant->forceFill([
                'gmail' => $gmail,
                'gmail_submitted_at' => $applicant->gmail_submitted_at ?: now(),
            ])->save();
        }

        return DB::transaction(function () use ($session, $applicant) {
            $registration = ExaminationRegistration::query()
                ->where('examination_schedule_id', $session->examination_schedule_id)
                ->where('applicant_id', $applicant->id)
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                $registration = ExaminationRegistration::query()->create([
                    'examination_schedule_id' => $session->examination_schedule_id,
                    'applicant_id' => $applicant->id,
                    'attendance_status' => AttendanceStatus::Pending,
                    'result_status' => ResultStatus::Pending,
                ]);
                app(ExaminationPasskeyService::class)->ensurePasskey($registration);
                $registration = ExaminationRegistration::query()
                    ->whereKey($registration->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $this->assertAssignedRoom($session, $registration);
            $registration->loadMissing('examSession');

            if ($this->isExamCompleted($registration)) {
                throw new RuntimeException('You have already submitted this examination.');
            }

            if ($registration->exam_session_id && ! $registration->agreed_at) {
                if ((int) $registration->exam_session_id === (int) $session->id) {
                    return $registration->fresh(['applicant', 'schedule', 'examSession']);
                }

                throw new RuntimeException('You are already reserved for another examination room.');
            }

            if ($registration->lobby_status === 'disconnected'
                && (int) $registration->exam_session_id === (int) $session->id
            ) {
                throw new RuntimeException(
                    'You were disconnected from Wi‑Fi. Ask your proctor for a reconnect code.'
                );
            }

            // Same session re-join is OK (reconnect). Block only when another active
            // claim exists on a different session for a different schedule.
            if ($this->isActivelyClaimed($registration)
                && (int) $registration->exam_session_id !== (int) $session->id
            ) {
                $claimedSession = $registration->examSession;
                $sameSchedule = $claimedSession
                    && (int) $claimedSession->examination_schedule_id === (int) $session->examination_schedule_id;

                if (! $sameSchedule) {
                    throw new RuntimeException('You are already connected to another examination schedule.');
                }
            }

            // Already waiting/taking on this exact session — return as-is (no duplicate session).
            if ($this->isActivelyClaimed($registration)
                && (int) $registration->exam_session_id === (int) $session->id
                && in_array($registration->lobby_status, ['waiting', 'taking_exam', 'warning'], true)
            ) {
                return $registration->fresh(['applicant', 'schedule', 'examSession']);
            }

            $registration->forceFill([
                'exam_session_id' => $session->id,
                'participation_token' => $registration->participation_token ?: (string) Str::uuid(),
                'lobby_status' => null,
                'joined_lobby_at' => null,
                'last_heartbeat_at' => null,
                'reconnect_allowed' => false,
                'reconnect_code' => null,
                'reconnect_code_expires_at' => null,
            ])->save();

            if ($applicant->status === ApplicantStatus::Registered) {
                $applicant->forceFill(['status' => ApplicantStatus::Confirmed])->save();
            }

            return $registration->fresh(['applicant', 'schedule', 'examSession']);
        });
    }

    public function agreeToTerms(ExaminationRegistration $registration): ExaminationRegistration
    {
        $reservationExpired = false;
        $agreedRegistration = DB::transaction(function () use ($registration, &$reservationExpired): ExaminationRegistration {
            $locked = ExaminationRegistration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $session = $locked->examSession;

            if ($this->isPendingPasskeyReservationExpired($locked)) {
                $this->releasePendingReservation($locked);
                $reservationExpired = true;

                return $locked->fresh(['applicant', 'schedule', 'examSession']);
            }

            if (! $session || ! in_array($session->status, [ExamSessionStatus::LobbyOpen, ExamSessionStatus::InProgress], true)) {
                throw new RuntimeException('This examination session is no longer accepting students.');
            }

            if (! $locked->agreed_at) {
                $now = now();
                $takingExam = $session->status === ExamSessionStatus::InProgress;
                $locked->forceFill([
                    'agreed_at' => $now,
                    'joined_lobby_at' => $locked->joined_lobby_at ?: $now,
                    'lobby_status' => $takingExam ? 'taking_exam' : 'waiting',
                    'last_heartbeat_at' => $takingExam ? $now : $locked->last_heartbeat_at,
                    'pending_device_id' => null,
                ])->save();
            }

            return $locked->fresh(['applicant', 'schedule', 'examSession']);
        }, 3);

        if ($reservationExpired) {
            throw new RuntimeException('This pending examination reservation expired. Re-enter your examination key to rejoin.');
        }

        return $agreedRegistration;
    }

    /**
     * Join lobby using examination session code + student passkey (replaces name-list claim).
     */
    public function joinWithPasskey(
        ExamSession $session,
        string $passkey,
        ?string $gmail = null,
        ?string $deviceId = null,
    ): ExaminationRegistration {
        $passkeys = app(ExaminationPasskeyService::class);
        $deviceId = $deviceId !== null ? trim($deviceId) : null;
        $deviceId = $deviceId !== '' ? $deviceId : null;

        $registration = $passkeys->findByPasskeyForSchedule($session->schedule, $passkey);

        if (! $registration) {
            $other = $passkeys->findByPasskeyAnywhere($passkey);

            if ($other && $this->isPendingPasskeyReservationExpired($other)) {
                $this->releaseExpiredPendingPasskeyReservation((int) $other->id);
                $registration = $passkeys->findByPasskeyForSchedule($session->schedule, $passkey);
            }

            if (! $registration) {
                $other = $passkeys->findByPasskeyAnywhere($passkey);
            }

            if (! $registration && $other
                && $other->passkey_status === 'used'
                && ! $other->agreed_at
                && $other->exam_session_id
            ) {
                if ((int) $other->examination_schedule_id !== (int) $session->examination_schedule_id) {
                    throw new RuntimeException($this->passkeyScheduleMismatchMessage($other, $session));
                }

                if ((int) $other->exam_session_id !== (int) $session->id) {
                    throw new RuntimeException('This examination key is already reserved for another examination room.');
                }

                if (! $deviceId || ! $other->pending_device_id) {
                    throw new RuntimeException('Invalid examination key for this session.');
                }

                if (! hash_equals((string) $other->pending_device_id, $deviceId)) {
                    throw new RuntimeException('This examination key is already reserved on another device.');
                }

                return $other->fresh(['applicant', 'schedule', 'examSession']);
            }

            if (! $registration) {
                if ($other && (int) $other->examination_schedule_id !== (int) $session->examination_schedule_id) {
                    throw new RuntimeException($this->passkeyScheduleMismatchMessage($other, $session));
                }
                if ($other
                    && in_array($other->passkey_status, ['not_sent', 'sent'], true)
                    && $other->schedule
                    && ! $passkeys->isWithinScheduleWindow($other->schedule)
                ) {
                    throw new RuntimeException($passkeys->scheduleWindowMessage($other->schedule));
                }

                throw new RuntimeException('Invalid examination key for this session.');
            }
        }

        if (! $registration) {
            throw new RuntimeException('Invalid examination key for this session.');
        }

        return DB::transaction(function () use ($session, $registration, $gmail, $passkeys, $deviceId): ExaminationRegistration {
            $lockedRegistration = ExaminationRegistration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isPendingPasskeyReservationExpired($lockedRegistration)) {
                $this->releasePendingReservation($lockedRegistration);
                $lockedRegistration->refresh();
            } elseif ($lockedRegistration->passkey_status === 'used'
                && ! $lockedRegistration->agreed_at
                && (int) $lockedRegistration->exam_session_id === (int) $session->id
            ) {
                if (! $deviceId || ! $lockedRegistration->pending_device_id) {
                    throw new RuntimeException('Invalid examination key for this session.');
                }

                if (! hash_equals((string) $lockedRegistration->pending_device_id, $deviceId)) {
                    throw new RuntimeException('This examination key is already reserved on another device.');
                }

                return $lockedRegistration->fresh(['applicant', 'schedule', 'examSession']);
            } elseif (! in_array($lockedRegistration->passkey_status, ['not_sent', 'sent'], true)
                || ! $lockedRegistration->passkey_expires_at
                || $lockedRegistration->passkey_expires_at->isPast()
            ) {
                throw new RuntimeException('This examination key has already been used, revoked, or expired.');
            } elseif (! $passkeys->isWithinScheduleWindow($lockedRegistration->schedule)) {
                throw new RuntimeException($passkeys->scheduleWindowMessage($lockedRegistration->schedule));
            }

            $applicant = $lockedRegistration->applicant;
            if (! $applicant) {
                throw new RuntimeException('Student record not found for this examination key.');
            }

            $joined = $this->join($session, $applicant, $gmail);
            $passkeys->markUsed($lockedRegistration, (int) $session->id);
            $joined->forceFill(['pending_device_id' => $deviceId])->save();

            return $joined->fresh(['applicant', 'schedule', 'examSession']);
        }, 3);
    }

    /**
     * Validate passkey against session schedule without joining.
     *
     * @return array{registration: ExaminationRegistration, applicant: Applicant}
     */
    public function validatePasskey(ExamSession $session, string $passkey): array
    {
        $passkeys = app(ExaminationPasskeyService::class);
        $scheduleId = (int) ($session->examination_schedule_id ?: $session->schedule?->id);
        $registration = $passkeys->findByPasskeyForSchedule($session->schedule, $passkey);

        if (! $registration) {
            $other = $passkeys->findByPasskeyAnywhere($passkey);
            if ($other && (int) $other->examination_schedule_id !== $scheduleId) {
                throw new RuntimeException($this->passkeyScheduleMismatchMessage($other, $session));
            }
            if ($other && (int) $other->examination_schedule_id === $scheduleId) {
                if (in_array($other->passkey_status, ['not_sent', 'sent'], true)
                    && $other->schedule
                    && ! $passkeys->isWithinScheduleWindow($other->schedule)
                ) {
                    throw new RuntimeException($passkeys->scheduleWindowMessage($other->schedule));
                }
            }
            if (! $session->isJoinable()) {
                $lateAllowed = (bool) ExaminationSetting::current()->allow_late_entry;
                throw new RuntimeException(
                    $session->status === ExamSessionStatus::InProgress && ! $lateAllowed
                        ? 'The examination has already started. Late entry is not allowed.'
                        : 'This examination session is not accepting students.'
                );
            }
            throw new RuntimeException('Invalid examination key for this session.');
        }

        if (! $session->isJoinable()) {
            $lateAllowed = (bool) ExaminationSetting::current()->allow_late_entry;
            throw new RuntimeException(
                $session->status === ExamSessionStatus::InProgress && ! $lateAllowed
                    ? 'The examination has already started. Late entry is not allowed.'
                    : 'This examination session is not accepting students.'
            );
        }

        if ($this->isExamCompleted($registration)) {
            throw new RuntimeException('You have already submitted this examination.');
        }

        if ($registration->lobby_status === 'disconnected'
            && (int) $registration->exam_session_id === (int) $session->id
        ) {
            throw new RuntimeException(
                'You were disconnected from Wi‑Fi. Ask your proctor for a reconnect code.'
            );
        }

        if ($this->isActivelyClaimed($registration)
            && (int) $registration->exam_session_id !== (int) $session->id
        ) {
            $claimedSession = $registration->examSession;
            $sameSchedule = $claimedSession
                && (int) $claimedSession->examination_schedule_id === (int) $session->examination_schedule_id;

            if (! $sameSchedule) {
                throw new RuntimeException('You are already connected to another examination schedule.');
            }
        }

        $this->assertAssignedRoom($session, $registration);
        $applicant = $registration->applicant;
        if (! $applicant) {
            throw new RuntimeException('Student record not found for this examination key.');
        }

        return [
            'registration' => $registration,
            'applicant' => $applicant,
        ];
    }

    private function passkeyScheduleMismatchMessage(
        ExaminationRegistration $registration,
        ExamSession $session,
    ): string {
        $keyDate = optional($registration->schedule?->exam_date)->toDateString();
        $roomDate = optional($session->schedule?->exam_date)->toDateString();

        return $keyDate && $roomDate && $keyDate !== $roomDate
            ? 'This key is for a different date.'
            : 'This examination key belongs to a different examination schedule.';
    }

    public function recordViolation(
        ExaminationRegistration $registration,
        string $type,
        ?string $message = null,
    ): ?ExaminationViolation {
        // Violations are disabled: mandatory Android screen pinning (startLockTask) and FLAG_SECURE
        // physically prevent cheating at the operating system level.
        return null;
    }

    public function lobbySnapshot(ExamSession $session, bool $includeReconnectCodes = false): array
    {
        $session = $this->syncExpiredSession($session);
        $session->loadMissing(['schedule', 'room', 'questionBank', 'proctor']);
        $schedule = $session->schedule;
        $room = $session->room;

        /** @var Collection<int, ExaminationRegistration> $registrations */
        $registrations = ExaminationRegistration::query()
            ->with('applicant')
            ->where('exam_session_id', $session->id)
            ->whereNotNull('agreed_at')
            ->orderBy('joined_lobby_at')
            ->get();

        $registeredTotal = ExaminationRegistration::query()
            ->where('examination_schedule_id', $session->examination_schedule_id)
            ->count();

        $students = $registrations->map(function (ExaminationRegistration $reg) use ($session, $includeReconnectCodes) {
            $applicant = $reg->applicant;
            $name = $applicant?->name ?? 'Unknown';
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $initials = collect($parts)->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');

            $status = $this->mapLobbyStatus($reg->lobby_status, $reg);
            $reconnectCode = null;
            $reconnectExpiresAt = null;

            if ($status === 'disconnected') {
                $issued = $this->ensureReconnectPin($reg);
                $reconnectCode = $issued['reconnect_code'];
                $reconnectExpiresAt = $issued['expires_at'];
                $reg->refresh();
            }

            $row = [
                'id' => (string) $reg->id,
                'studentId' => $applicant?->applicant_code ?? (string) $applicant?->id,
                'applicant_id' => $applicant?->id,
                'fullName' => $name,
                'email' => $applicant?->gmail ?: ($applicant?->email ?: ''),
                'programCode' => $applicant?->course_applied ?? '',
                'programName' => $applicant?->course_applied ?? '',
                'avatarInitials' => $initials ?: 'ST',
                'status' => $status,
                'joinedAt' => optional($reg->joined_lobby_at)?->toISOString() ?? $reg->created_at?->toISOString(),
                'startedAt' => $session->started_at?->toISOString(),
                'lastActivityAt' => optional($reg->last_heartbeat_at ?? $reg->updated_at)?->toISOString(),
                'violationCount' => 0,
                'terminationReason' => $reg->lobby_status === 'terminated' ? 'proctor_terminated' : (
                    $reg->lobby_status === 'finished' ? 'submitted' : null
                ),
                'reconnectAllowed' => (bool) $reg->reconnect_allowed,
                'applicantCode' => $applicant?->applicant_code,
                'submittedAt' => optional($reg->submitted_at)?->toISOString(),
                'score' => $reg->score,
            ];

            // Only proctor lobby may see reconnect PINs (never public student polls).
            if ($includeReconnectCodes && $status === 'disconnected') {
                $row['reconnectCode'] = $reconnectCode;
                $row['reconnectCodeExpiresAt'] = $reconnectExpiresAt;
            }

            return $row;
        })->values()->all();

        $status = match ($session->status) {
            ExamSessionStatus::LobbyOpen => 'lobby_open',
            ExamSessionStatus::InProgress => 'in_progress',
            ExamSessionStatus::Ended => 'ended',
            default => 'scheduled',
        };

        $examDate = optional($schedule?->exam_date)?->toDateString();
        $timeSlot = $schedule?->time_slot
            ?: trim(($this->formatTime($schedule?->start_time) ?? '').'–'.($this->formatTime($schedule?->end_time) ?? ''), '–');

        $mobileSchedule = [
            'id' => 'date-'.($examDate ?: 'unknown'),
            'name' => $schedule?->title ?: 'Entrance Examination',
            'schoolYear' => (string) now()->year,
            'examinationDate' => $examDate
                ? \Illuminate\Support\Carbon::parse($examDate)->format('M j, Y')
                : '',
            'examinationDateIso' => $examDate,
            'batchCount' => 1,
            'description' => $schedule?->course,
        ];

        $roomName = $room?->room_name ?: ($schedule?->venue ?: 'TBD');

        $mobileSession = [
            'id' => (string) $schedule?->id,
            'scheduleId' => $mobileSchedule['id'],
            'examSessionId' => $session->id,
            'roomId' => $room?->id ? (string) $room->id : null,
            'roomName' => $room?->room_name,
            'timeLabel' => $timeSlot,
            'startTime' => $this->formatTime($schedule?->start_time) ?? '',
            'endTime' => $this->formatTime($schedule?->end_time) ?? '',
            'venue' => $roomName,
            'batchNumber' => $schedule?->batch_code ?: 'Batch',
            'registeredStudents' => $registeredTotal,
            'durationMinutes' => $this->durationMinutes(),
            'remainingSeconds' => $this->remainingSeconds($session),
            'endsAt' => $session->started_at
                ? $session->started_at->copy()->addMinutes($this->durationMinutes())->toIso8601String()
                : null,
            'totalQuestions' => ExamQuestion::query()
                ->where('is_selected_for_exam', true)
                ->where('status', 'active')
                ->whereHas('subject', fn ($q) => $q->where('question_bank_id', $session->question_bank_id))
                ->count(),
            'questionBankId' => $session->question_bank_id,
            'localServerIp' => $session->local_server_ip,
            'wifiSsid' => $session->wifi_ssid,
            'peerPort' => 9777,
        ];

        $connected = count($students);
        $waiting = collect($students)->whereIn('status', ['waiting', 'connected'])->count();
        $taking = collect($students)->where('status', 'taking_exam')->count();
        $disconnected = collect($students)->where('status', 'disconnected')->count();
        $finished = collect($students)->whereIn('status', ['finished', 'done'])->count();
        $warning = collect($students)->where('status', 'warning')->count();
        $terminated = collect($students)->where('status', 'terminated')->count();

        return [
            'schedule' => $mobileSchedule,
            'session' => $mobileSession,
            'status' => $status,
            'examinationCode' => $session->exam_code,
            // Plain code is more reliable for Expo Go / phone cameras than dense JSON QR.
            'qrValue' => (string) $session->exam_code,
            'qrPayload' => $this->qrPayload($session),
            'roomName' => $room?->room_name,
            'roomId' => $room?->id,
            'registeredCount' => $registeredTotal,
            'connectedCount' => $connected,
            'notYetConnectedCount' => max(0, $registeredTotal - $connected),
            'waitingCount' => $waiting,
            'takingCount' => $taking,
            'disconnectedCount' => $disconnected,
            'finishedCount' => $finished,
            'warningCount' => $warning,
            'terminatedCount' => $terminated,
            'violationsDetected' => 0,
            'recentViolations' => [],
            'students' => $students,
            'exam_session_id' => $session->id,
            'proctor_id' => $session->proctor_id,
            'proctor_name' => $session->proctor?->name,
            'remainingSeconds' => $this->remainingSeconds($session),
            'allow_late_entry' => (bool) ExaminationSetting::current()->allow_late_entry,
            'wifiSsid' => $session->wifi_ssid,
            'localServerIp' => $session->local_server_ip,
            'peerPort' => 9777,
        ];
    }

    public function studentsForSchedule(ExaminationSchedule $schedule, ?string $search = null): array
    {
        $query = ExaminationRegistration::query()
            ->with(['applicant', 'examSession'])
            ->where('examination_schedule_id', $schedule->id);

        if ($search = trim((string) $search)) {
            $query->whereHas('applicant', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('applicant_code', 'like', "%{$search}%")
                    ->orWhere('course_applied', 'like', "%{$search}%")
                    ->orWhere('gmail', 'like', "%{$search}%");
            });
        }

        /** @var Collection<int, ExaminationRegistration> $regs */
        return $query->get()
            /** @var Collection<int, ExaminationRegistration> $regs */
            ->map(function (ExaminationRegistration $reg) {
                $applicant = $reg->applicant;
                if (! $applicant) {
                    return null;
                }
                $name = $applicant->name;
                $parts = preg_split('/\s+/', trim($name)) ?: [];
                $initials = collect($parts)->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');

                [$selectionStatus, $statusLabel, $selectable] = $this->selectionState($reg);

                return [
                    'id' => (string) $applicant->id,
                    'studentId' => $applicant->applicant_code,
                    'firstName' => $parts[0] ?? $name,
                    'middleName' => '',
                    'lastName' => $parts[count($parts) - 1] ?? '',
                    'fullName' => $name,
                    'examination_schedule_id' => $reg->examination_schedule_id,
                    'email' => $applicant->gmail ?: ($applicant->email ?: ''),
                    'programId' => $applicant->course_applied ?? '',
                    'programCode' => $applicant->course_applied ?? '',
                    'programName' => $applicant->course_applied ?? '',
                    'sex' => 'Male',
                    'avatarInitials' => $initials ?: 'ST',
                    'registration_id' => $reg->id,
                    'selectionStatus' => $selectionStatus,
                    'statusLabel' => $statusLabel,
                    'selectable' => $selectable,
                    'lobby_status' => $reg->lobby_status,
                    'exam_session_id' => $reg->exam_session_id,
                ];
            })
            ->filter()
            ->sortBy('fullName')
            ->values()
            ->all();
    }

    public function questionsForSession(ExamSession $session): array
    {
        $settings = ExaminationSetting::current();
        $shuffleBoth = (bool) $settings->shuffle_both;
        $shuffleQuestions = $shuffleBoth || (bool) $settings->shuffle_questions;
        $shuffleCategories = $shuffleBoth || (bool) $settings->shuffle_categories;

        $questions = ExamQuestion::query()
            ->with('subject:id,name,code,question_bank_id,sort_order')
            ->where('is_selected_for_exam', true)
            ->where('status', 'active')
            ->whereHas('subject', fn ($q) => $q->where('question_bank_id', $session->question_bank_id)->where('is_active', true))
            ->orderBy('sort_order')
            ->get();

        // Group by category (exam subject), respecting subject sort order.
        $grouped = $questions->groupBy('exam_subject_id');

        $categoryOrder = $grouped->keys()->values()->all();
        if ($shuffleCategories) {
            shuffle($categoryOrder);
        } else {
            $categoryOrder = $questions
                ->sortBy(fn (ExamQuestion $q) => (int) ($q->subject?->sort_order ?? 0))
                ->pluck('exam_subject_id')
                ->unique()
                ->values()
                ->all();
        }

        /** @var Collection<int, ExamQuestion> $ordered */
        $ordered = collect();
        foreach ($categoryOrder as $subjectId) {
            $bucket = $grouped->get($subjectId, collect());
            $list = $bucket->values();
            if ($shuffleQuestions) {
                $list = $list->shuffle()->values();
            }
            $ordered = $ordered->concat($list);
        }

        return $ordered->values()->map(function (ExamQuestion $q, int $index) {
            $options = is_array($q->options) ? $q->options : [];
            $choices = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];

            foreach ($options as $key => $value) {
                if (is_array($value)) {
                    $letter = strtoupper((string) ($value['key'] ?? ''));
                    $text = (string) ($value['text'] ?? $value['label'] ?? $value['value'] ?? '');
                    if ($letter !== '' && array_key_exists($letter, $choices)) {
                        $choices[$letter] = $text;
                    }

                    continue;
                }

                $letter = is_string($key) ? strtoupper($key) : '';
                if ($letter !== '' && array_key_exists($letter, $choices)) {
                    $choices[$letter] = (string) $value;
                }
            }

            if (implode('', $choices) === '') {
                $letters = ['A', 'B', 'C', 'D'];
                foreach (array_values($options) as $i => $value) {
                    if ($i > 3) {
                        break;
                    }
                    $choices[$letters[$i]] = is_scalar($value) ? (string) $value : '';
                }
            }

            return [
                'id' => (string) $q->id,
                'number' => $index + 1,
                'subjectId' => (string) $q->exam_subject_id,
                'category' => $q->subject?->name ?? 'General',
                'categoryCode' => $q->subject?->code ?? '',
                'type' => 'multiple_choice',
                'question' => $q->stem,
                'choices' => $choices,
                // Never send correct answer to the examinee device.
                'correctAnswer' => 'A',
                'explanation' => '',
            ];
        })->all();
    }

    public function findRegistrationByToken(string $token): ?ExaminationRegistration
    {
        return ExaminationRegistration::query()
            ->with(['applicant', 'examSession', 'schedule'])
            ->where('participation_token', $token)
            ->first();
    }

    public function markFinished(ExaminationRegistration $registration, string $reason = 'submitted'): void
    {
        $registration->forceFill([
            'lobby_status' => $reason === 'proctor_terminated' || $reason === 'policy_violation'
                ? 'terminated'
                : 'finished',
            'reconnect_allowed' => false,
            'reconnect_code' => null,
            'reconnect_code_expires_at' => null,
        ])->save();

        if ($registration->exam_session_id) {
            $session = ExamSession::query()->find($registration->exam_session_id);
            if ($session) {
                $this->maybeEndWhenAllFinished($session);
            }
        }
    }

    public function terminateStudent(ExaminationRegistration $registration): void
    {
        $registration->forceFill([
            'lobby_status' => 'terminated',
            'reconnect_allowed' => false,
            'reconnect_code' => null,
            'reconnect_code_expires_at' => null,
        ])->save();
    }

    public function heartbeat(ExaminationRegistration $registration): ExaminationRegistration
    {
        if ($this->isExamCompleted($registration)) {
            throw new RuntimeException('This examination is already finished.');
        }

        if ($registration->lobby_status === 'disconnected') {
            throw new RuntimeException(
                'You are disconnected. Ask your proctor for a reconnect code.'
            );
        }

        if (! in_array($registration->lobby_status, ['waiting', 'connected', 'taking_exam', 'warning'], true)) {
            throw new RuntimeException('Cannot heartbeat in the current examination state.');
        }

        $registration->forceFill([
            'last_heartbeat_at' => now(),
        ])->save();

        return $registration->fresh(['applicant', 'examSession', 'schedule']);
    }

    public function markWifiDisconnected(ExaminationRegistration $registration): ExaminationRegistration
    {
        if ($this->isExamCompleted($registration)) {
            return $registration;
        }

        if (! in_array($registration->lobby_status, ['taking_exam', 'warning', 'waiting', 'connected'], true)) {
            return $registration;
        }

        $this->ensureReconnectPin($registration, forceNew: true);

        return $registration->fresh(['applicant', 'examSession', 'schedule']);
    }

    /**
     * Persist disconnected status and ensure a fresh 6-digit reconnect PIN is available.
     * Numeric only — never the examination/QR alphanumeric code.
     *
     * @return array{reconnect_code: string, expires_at: string}
     */
    public function ensureReconnectPin(ExaminationRegistration $registration, bool $forceNew = false): array
    {
        $validExisting = ! $forceNew
            && $registration->reconnect_allowed
            && $registration->reconnect_code
            && preg_match('/^\d{6}$/', (string) $registration->reconnect_code)
            && (! $registration->reconnect_code_expires_at || ! $registration->reconnect_code_expires_at->isPast());

        if ($validExisting) {
            return [
                'reconnect_code' => (string) $registration->reconnect_code,
                'expires_at' => optional($registration->reconnect_code_expires_at)?->toIso8601String()
                    ?? now()->addMinutes(10)->toIso8601String(),
            ];
        }

        $code = (string) random_int(100000, 999999);
        $expires = now()->addMinutes(10);

        $registration->forceFill([
            'lobby_status' => 'disconnected',
            'reconnect_allowed' => true,
            'reconnect_code' => $code,
            'reconnect_code_expires_at' => $expires,
        ])->save();

        return [
            'reconnect_code' => $code,
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * @return array{registration: ExaminationRegistration, reconnect_code: string, expires_at: string}
     */
    public function allowReconnect(ExaminationRegistration $registration): array
    {
        if ($this->isExamCompleted($registration)) {
            throw new RuntimeException('This student has already finished the examination.');
        }

        $mapped = $this->mapLobbyStatus($registration->lobby_status, $registration);
        if ($mapped !== 'disconnected' && $registration->lobby_status !== 'disconnected') {
            throw new RuntimeException('Reconnect is only available for disconnected students.');
        }

        $issued = $this->ensureReconnectPin($registration, forceNew: true);

        return [
            'registration' => $registration->fresh(['applicant', 'examSession', 'schedule']),
            'reconnect_code' => $issued['reconnect_code'],
            'expires_at' => $issued['expires_at'],
        ];
    }

    public function reconnectWithCode(ExaminationRegistration $registration, string $code): ExaminationRegistration
    {
        if ($this->isExamCompleted($registration)) {
            throw new RuntimeException('You have already submitted this examination.');
        }

        if (! $registration->reconnect_allowed || ! $registration->reconnect_code) {
            throw new RuntimeException('Reconnect is not allowed yet. Ask your proctor for a code.');
        }

        if ($registration->reconnect_code_expires_at && $registration->reconnect_code_expires_at->isPast()) {
            throw new RuntimeException('Reconnect code expired. Ask your proctor for a new code.');
        }

        $entered = preg_replace('/\D+/', '', trim($code)) ?? '';
        $expected = preg_replace('/\D+/', '', (string) $registration->reconnect_code) ?? '';
        if ($entered === '' || strlen($entered) < 6 || ! hash_equals($expected, $entered)) {
            throw new RuntimeException('Invalid reconnect code. Enter the 6-digit number from your proctor — not the examination QR code.');
        }

        $session = $registration->examSession;
        if (! $session || $session->status === ExamSessionStatus::Ended) {
            throw new RuntimeException('This examination has ended.');
        }

        $lobbyStatus = $session->status === ExamSessionStatus::InProgress ? 'taking_exam' : 'waiting';

        $registration->forceFill([
            'lobby_status' => $lobbyStatus,
            'last_heartbeat_at' => now(),
            'reconnect_allowed' => false,
            'reconnect_code' => null,
            'reconnect_code_expires_at' => null,
        ])->save();

        return $registration->fresh(['applicant', 'examSession', 'schedule']);
    }

    private const HEARTBEAT_STALE_SECONDS = 90;

    public function assertAssignedRoom(ExamSession $session, ExaminationRegistration $registration): void
    {
        if ($session->schedule?->rooms_managed
            && (int) $registration->assigned_room_id !== (int) $session->examination_room_id) {
            throw new RuntimeException('Your assigned room is '.($registration->assignedRoom?->room_name ?? 'not yet published').'. Scan the QR code in your assigned room.');
        }
    }

    /**
     * Creates a new active exam session and retries only when the DB rejects the generated code due to a race.
     *
     * @param  array<string, mixed>  $attributes
     * @return ExamSession
     */
    private function createUniqueSession(array $attributes, ?ExaminationRoom $room = null): ExamSession
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $attributes['exam_code'] = $attributes['exam_code'] ?? $this->generateUniqueCode($room);

            try {
                return ExamSession::query()->create($attributes);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintCollision($e)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not generate a unique exam code for this room.');
    }

    private function isUniqueConstraintCollision(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate key')
            || str_contains($message, 'exam_code')
            || str_contains($message, 'session_code')
            || str_contains($message, 'qr_token');
    }
        
    /**
     * Globally unique alphanumeric code (letters + digits), e.g. K7M2P9QX.
     * Each room session gets its own code; QR JSON also carries room_id.
     */
    private function generateUniqueCode(?ExaminationRoom $room = null): string
    {
        // Avoid ambiguous 0/O, 1/I.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $length = 8;

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (ExamSession::query()->whereRaw('UPPER(exam_code) = ?', [strtoupper($code)])->exists());

        return strtoupper($code);
    }

    private function mapLobbyStatus(?string $status, ExaminationRegistration $reg): string
    {
        if ($reg->score !== null && in_array($reg->result_status?->value ?? $reg->result_status, ['passed', 'failed'], true)) {
            return 'finished';
        }

        if ($status === 'finished') {
            return 'finished';
        }

        if ($status === 'terminated') {
            return 'terminated';
        }

        if ($status === 'disconnected') {
            return 'disconnected';
        }

        if (in_array($status, ['taking_exam', 'warning'], true) && $this->isHeartbeatStale($reg)) {
            return 'disconnected';
        }

        return match ($status) {
            'taking_exam' => 'taking_exam',
            'warning' => 'warning',
            'connected' => 'connected',
            default => 'waiting',
        };
    }

    private function isHeartbeatStale(ExaminationRegistration $reg): bool
    {
        $session = $reg->relationLoaded('examSession')
            ? $reg->examSession
            : $reg->examSession()->first();

        if (! $session || $session->status !== ExamSessionStatus::InProgress) {
            return false;
        }

        if (! $reg->last_heartbeat_at) {
            // Match the normal stale-heartbeat grace before treating a missing heartbeat as disconnect.
            $startedAt = $session->started_at;
            if ($startedAt && $startedAt->greaterThan(now()->subSeconds(self::HEARTBEAT_STALE_SECONDS))) {
                return false;
            }

            return true;
        }

        return $reg->last_heartbeat_at->lessThan(now()->subSeconds(self::HEARTBEAT_STALE_SECONDS));
    }

    /**
     * @return array{0: string, 1: string, 2: bool} [selectionStatus, statusLabel, selectable]
     */
    private function selectionState(ExaminationRegistration $reg): array
    {
        if ($this->isExamCompleted($reg)) {
            return ['completed', 'Exam Submitted', false];
        }

        if ($this->isActivelyClaimed($reg)) {
            return ['ready', 'Ready', false];
        }

        return ['available', 'Not scanned', true];
    }

    private function isExamCompleted(ExaminationRegistration $reg): bool
    {
        if (in_array($reg->lobby_status, ['finished', 'terminated'], true)) {
            return true;
        }

        return $reg->score !== null
            && in_array($reg->result_status, [ResultStatus::Passed, ResultStatus::Failed], true);
    }

    private function isActivelyClaimed(ExaminationRegistration $reg): bool
    {
        if (! $reg->exam_session_id) {
            return false;
        }

        if (! in_array($reg->lobby_status, ['connected', 'waiting', 'taking_exam', 'warning', 'disconnected'], true)) {
            return false;
        }

        $session = $reg->relationLoaded('examSession')
            ? $reg->examSession
            : $reg->examSession()->first();

        if (! $session) {
            return false;
        }

        return in_array($session->status, [
            ExamSessionStatus::LobbyOpen,
            ExamSessionStatus::InProgress,
        ], true);
    }

    private function formatTime(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($time)->format('H:i');
        } catch (\Throwable) {
            return (string) $time;
        }
    }
}
