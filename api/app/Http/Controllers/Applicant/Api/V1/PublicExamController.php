<?php

namespace App\Http\Controllers\Applicant\Api\V1;

use App\Enums\ExamSessionStatus;
use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExaminationResultResource;
use App\Models\Applicant;
use App\Models\ExaminationSchedule;
use App\Models\ExaminationSetting;
use App\Models\ExamSession;
use App\Services\ExaminationResultService;
use App\Services\ExamSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PublicExamController extends Controller
{
    public function __construct(
        private ExamSessionService $sessions,
        private ExaminationResultService $results,
    ) {
    }

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:80'],
            'qr' => ['nullable', 'string', 'max:800'],
        ]);

        $raw = trim((string) ($validated['code'] ?? $validated['qr'] ?? ''));
        if ($raw === '') {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Enter an examination code.',
            ], 422);
        }

        // JSON QR payload support (also handled in ExamSessionService::normalizeExamCode)
        $session = $this->sessions->findByCode($raw, joinableOnly: true);
        if (! $session) {
            $any = $this->sessions->findByCode($raw, joinableOnly: false);
            if ($any?->status === ExamSessionStatus::InProgress) {
                $late = (bool) \App\Models\ExaminationSetting::current()->allow_late_entry;
                if (! $late) {
                    return response()->json([
                        'success' => true,
                        'valid' => false,
                        'message' => 'The examination has already started. Late entry is not allowed.',
                    ]);
                }
                $session = $any;
            } elseif ($any?->status === ExamSessionStatus::Ended) {
                return response()->json([
                    'success' => true,
                    'valid' => false,
                    'message' => 'This examination session has ended. The examination code is no longer valid.',
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'valid' => false,
                    'message' => 'Code not active. Ask your proctor to open the lobby and share the current code.',
                ]);
            }
        }

        if (! isset($session) || ! $session) {
            return response()->json([
                'success' => true,
                'valid' => false,
                'message' => 'Code not active. Ask your proctor to open the lobby and share the current code.',
            ]);
        }

        $snapshot = $this->sessions->lobbySnapshot($session);

        return response()->json([
            'success' => true,
            'valid' => true,
            'examinationCode' => $session->exam_code,
            'schedule' => $snapshot['schedule'],
            'session' => $snapshot['session'],
            'exam_session_id' => $session->id,
            'status' => $snapshot['status'],
            // Latest proctor LAN host — examinees must refresh this after Wi‑Fi/DHCP changes.
            'local_server_ip' => $session->local_server_ip,
            'wifi_ssid' => $session->wifi_ssid,
            'peer_port' => 9777,
            'localServerIp' => $session->local_server_ip,
            'wifiSsid' => $session->wifi_ssid,
            'peerPort' => 9777,
        ]);
    }

    public function students(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $session = $this->sessions->findByCode($validated['code']);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive examination code.'], 404);
        }

        $students = $this->sessions->studentsForSchedule(
            $session->schedule,
            $validated['search'] ?? null,
        );

        // Only students registered to this session's schedule (never other schedules).
        $students = collect($students)
            ->filter(fn ($row) => (int) ($row['examination_schedule_id'] ?? $session->examination_schedule_id)
                === (int) $session->examination_schedule_id)
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $students,
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'applicant_id' => ['required', 'integer', 'exists:applicants,id'],
        ]);

        $session = $this->sessions->findByCode($validated['code']);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive examination code.'], 404);
        }

        try {
            $applicant = Applicant::query()->findOrFail($validated['applicant_id']);
            $registration = $this->sessions->claim($session, $applicant);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Student name reserved.',
            'data' => [
                'registration_id' => $registration->id,
                'assigned_room' => $registration->assignedRoom?->room_name,
                'assigned_room_id' => $registration->assigned_room_id,
                'selectionStatus' => 'ready',
                'lobby' => $this->sessions->lobbySnapshot($session->fresh()),
            ],
        ]);
    }

    public function releaseClaim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'applicant_id' => ['required', 'integer', 'exists:applicants,id'],
        ]);

        $session = $this->sessions->findByCode($validated['code']);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive examination code.'], 404);
        }

        try {
            $applicant = Applicant::query()->findOrFail($validated['applicant_id']);
            $this->sessions->releaseClaim($session, $applicant);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Student name released.',
        ]);
    }

    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'applicant_id' => ['required', 'integer', 'exists:applicants,id'],
            'gmail' => ['nullable', 'email', 'max:255'],
        ]);

        $session = $this->sessions->findByCode($validated['code']);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive examination code.'], 404);
        }

        // Applicant must be registered to this schedule.
        $onSchedule = $session->schedule
            ->registrations()
            ->where('applicant_id', $validated['applicant_id'])
            ->exists();

        if (! $onSchedule) {
            return response()->json([
                'success' => false,
                'message' => 'You are not registered for this examination schedule.',
            ], 403);
        }

        try {
            $applicant = Applicant::query()->findOrFail($validated['applicant_id']);
            $registration = $this->sessions->join(
                $session,
                $applicant,
                $validated['gmail'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Joined examination lobby.',
            'data' => [
                'registration_id' => $registration->id,
                'participation_token' => $registration->participation_token,
                'lobby' => $this->sessions->lobbySnapshot($session->fresh()),
            ],
        ]);
    }

    public function validatePasskey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'passkey' => ['required', 'string', 'max:8', 'regex:/\A[A-Za-z0-9]{8}\z/'],
        ]);

        $session = $this->sessions->findByCode($validated['code'], joinableOnly: false);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive examination code.'], 404);
        }

        try {
            $result = $this->sessions->validatePasskey($session, $validated['passkey']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $applicant = $result['applicant'];
        $registration = $result['registration'];
        $name = $applicant->name ?? 'Unknown';
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = collect($parts)->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');

        return response()->json([
            'success' => true,
            'message' => 'Examination key verified.',
            'data' => [
                'registration_id' => $registration->id,
                'applicant_id' => $applicant->id,
                'assigned_room' => $registration->assignedRoom?->room_name,
                'assigned_room_id' => $registration->assigned_room_id,
                'student' => [
                    'id' => (string) $applicant->id,
                    'registration_id' => $registration->id,
                    'studentId' => $applicant->applicant_code ?? (string) $applicant->id,
                    'fullName' => $name,
                    'email' => $applicant->gmail ?: ($applicant->email ?: ''),
                    'gmail' => $applicant->gmail,
                    'hasGmail' => (bool) $applicant->gmail,
                    'programCode' => $applicant->course_applied ?? '',
                    'programName' => $applicant->course_applied ?? '',
                    'avatarInitials' => $initials ?: 'ST',
                    'selectionStatus' => 'ready',
                    'selectable' => true,
                    'assigned_room' => $registration->assignedRoom?->room_name,
                    'assigned_room_id' => $registration->assigned_room_id,
                ],
                'schedule' => [
                    'id' => $session->examination_schedule_id,
                    'title' => $session->schedule?->title,
                    'exam_date' => optional($session->schedule?->exam_date)?->toDateString(),
                    'time_slot' => $session->schedule?->time_slot,
                    'batch_code' => $session->schedule?->batch_code,
                    'assigned_room' => $registration->assignedRoom?->room_name,
                    'assigned_room_id' => $registration->assigned_room_id,
                ],
            ],
        ]);
    }

    public function joinPasskey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'passkey' => ['required', 'string', 'max:8', 'regex:/\A[A-Za-z0-9]{8}\z/'],
            'gmail' => ['nullable', 'email', 'max:255'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $session = $this->sessions->findByCode($validated['code'], joinableOnly: false);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive examination code.'], 404);
        }

        try {
            $registration = $this->sessions->joinWithPasskey(
                $session,
                $validated['passkey'],
                $validated['gmail'] ?? null,
                isset($validated['device_id']) ? trim($validated['device_id']) : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Joined examination lobby.',
            'data' => [
                'registration_id' => $registration->id,
                'participation_token' => $registration->participation_token,
                'applicant_id' => $registration->applicant_id,
                'lobby' => $this->sessions->lobbySnapshot($session->fresh()),
            ],
        ]);
    }

    public function reportViolation(Request $request): JsonResponse
    {
        // Violations are disabled: mandatory Android screen pinning (startLockTask) and FLAG_SECURE
        // physically prevent cheating at the operating system level.
        return response()->json([
            'success' => true,
            'message' => 'Violation tracking is disabled. Mandatory screen pinning is active.',
            'data' => [
                'violation_id' => null,
                'violation_count' => 0,
                'violation_limit' => 0,
                'lobby_status' => 'taking_exam',
            ],
        ]);
    }

    public function lobbyStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required_without:participation_token', 'nullable', 'string', 'max:40'],
            'participation_token' => ['required_without:code', 'nullable', 'uuid'],
        ]);

        if (! empty($validated['participation_token'])) {
            $registration = $this->sessions->findRegistrationByToken($validated['participation_token']);
            if (! $registration?->examSession) {
                return response()->json(['success' => false, 'message' => 'Invalid participation token.'], 404);
            }
            $session = $registration->examSession;

            return response()->json([
                'success' => true,
                'data' => [
                    ...$this->sessions->lobbySnapshot($session),
                    'my_status' => $registration->lobby_status,
                    'registration_id' => $registration->id,
                ],
            ]);
        }

        $session = $this->sessions->findByCode((string) $validated['code']);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive examination code.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessions->lobbySnapshot($session),
        ]);
    }

    public function questions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'participation_token' => ['required', 'uuid'],
        ]);

        $registration = $this->sessions->findRegistrationByToken($validated['participation_token']);
        if (! $registration?->examSession) {
            return response()->json(['success' => false, 'message' => 'Invalid participation token.'], 404);
        }

        $session = $registration->examSession;
        if ($session->status === ExamSessionStatus::LobbyOpen) {
            return response()->json([
                'success' => false,
                'message' => 'The examination has not started yet. Please wait for your proctor.',
            ], 409);
        }

        if ($session->status === ExamSessionStatus::Ended) {
            return response()->json(['success' => false, 'message' => 'This examination has ended.'], 422);
        }

        if ($registration->lobby_status === 'terminated') {
            return response()->json(['success' => false, 'message' => 'Your examination was terminated.'], 403);
        }

        if ($registration->lobby_status === 'disconnected') {
            return response()->json([
                'success' => false,
                'message' => 'You are disconnected. Ask your proctor for a reconnect code.',
            ], 403);
        }

        if ($registration->score !== null) {
            return response()->json(['success' => false, 'message' => 'You already submitted this examination.'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessions->questionsForSession($session),
        ]);
    }

    public function saveAnswers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'participation_token' => ['required', 'uuid'],
            'answers' => ['required', 'array'],
            'answers.*.exam_question_id' => ['required', 'integer', 'exists:exam_questions,id'],
            'answers.*.selected_answer' => ['nullable', 'string', 'max:10'],
        ]);

        $registration = $this->sessions->findRegistrationByToken($validated['participation_token']);
        if (! $registration?->examSession) {
            return response()->json(['success' => false, 'message' => 'Invalid participation token.'], 404);
        }

        $session = $registration->examSession;
        if ($session->status === ExamSessionStatus::LobbyOpen) {
            return response()->json([
                'success' => false,
                'message' => 'The examination has not started yet.',
            ], 409);
        }

        if ($registration->lobby_status === 'terminated') {
            return response()->json(['success' => false, 'message' => 'Your examination was terminated.'], 403);
        }

        if ($registration->lobby_status === 'disconnected') {
            return response()->json([
                'success' => false,
                'message' => 'You are disconnected. Ask your proctor for a reconnect code.',
            ], 403);
        }

        if ($registration->score !== null) {
            return response()->json(['success' => false, 'message' => 'Already submitted.'], 422);
        }

        // After the session ends, autosave is ignored (force-submit already ran or will grade DB answers).
        if ($session->status === ExamSessionStatus::Ended) {
            return response()->json([
                'success' => true,
                'message' => 'Examination ended; answers were not updated.',
                'data' => ['saved' => false],
            ]);
        }

        $this->results->saveProgress($registration, $validated['answers']);

        return response()->json([
            'success' => true,
            'message' => 'Progress saved.',
            'data' => [
                'saved' => true,
                'saved_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'participation_token' => ['required', 'uuid'],
            'answers' => ['required', 'array'],
            'answers.*.exam_question_id' => ['required', 'integer', 'exists:exam_questions,id'],
            'answers.*.selected_answer' => ['nullable', 'string', 'max:10'],
        ]);

        $registration = $this->sessions->findRegistrationByToken($validated['participation_token']);
        if (! $registration?->examSession) {
            return response()->json(['success' => false, 'message' => 'Invalid participation token.'], 404);
        }

        if ($registration->score !== null
            && in_array($registration->result_status, [ResultStatus::Passed, ResultStatus::Failed], true)
        ) {
            // A prior submit may have committed while the response was lost.
            // Return the existing receipt so the client can safely retry.
            $registration->loadMissing('applicant');
            return response()->json([
                'success' => true,
                'message' => 'Examination submission was already confirmed.',
                'data' => new ExaminationResultResource($registration),
                'requires_gmail' => ! $registration->applicant?->gmail,
                'submittedAt' => $registration->submitted_at?->toISOString() ?? now()->toISOString(),
                'already_submitted' => true,
            ]);
        }

        if ($registration->lobby_status === 'terminated') {
            return response()->json(['success' => false, 'message' => 'Your examination was terminated.'], 403);
        }

        if ($registration->lobby_status === 'disconnected') {
            return response()->json([
                'success' => false,
                'message' => 'You are disconnected. Ask your proctor for a reconnect code.',
            ], 403);
        }

        $session = $registration->examSession;
        // Allow a short grace window after End so a student submit in flight is not lost.
        if ($session->status === ExamSessionStatus::Ended) {
            $endedAt = $session->ended_at;
            $withinGrace = $endedAt && $endedAt->greaterThan(now()->subMinutes(2));
            if (! $withinGrace || $registration->score !== null) {
                return response()->json(['success' => false, 'message' => 'This examination has ended.'], 422);
            }
        }

        $registration = $this->results->submitAnswers($registration, $validated['answers']);
        $this->sessions->markFinished($registration, 'submitted');

        return response()->json([
            'success' => true,
            'message' => 'Examination submitted successfully.',
            'data' => new ExaminationResultResource($registration),
            'requires_gmail' => ! $registration->applicant?->gmail,
            'submittedAt' => now()->toISOString(),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'participation_token' => ['required', 'uuid'],
        ]);

        $registration = $this->sessions->findRegistrationByToken($validated['participation_token']);
        if (! $registration?->examSession) {
            return response()->json(['success' => false, 'message' => 'Invalid participation token.'], 404);
        }

        try {
            $registration = $this->sessions->heartbeat($registration);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'lobby_status' => $registration->lobby_status,
                'last_heartbeat_at' => optional($registration->last_heartbeat_at)?->toIso8601String(),
            ],
        ]);
    }

    public function wifiDisconnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'participation_token' => ['required', 'uuid'],
        ]);

        $registration = $this->sessions->findRegistrationByToken($validated['participation_token']);
        if (! $registration?->examSession) {
            return response()->json(['success' => false, 'message' => 'Invalid participation token.'], 404);
        }

        $registration = $this->sessions->markWifiDisconnected($registration);

        return response()->json([
            'success' => true,
            'message' => 'Marked disconnected. Ask your proctor for a reconnect code.',
            'data' => [
                'lobby_status' => $registration->lobby_status,
            ],
        ]);
    }

    public function reconnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'participation_token' => ['required', 'uuid'],
            'reconnect_code' => ['required', 'string', 'max:12'],
        ]);

        $registration = $this->sessions->findRegistrationByToken($validated['participation_token']);
        if (! $registration?->examSession) {
            return response()->json(['success' => false, 'message' => 'Invalid participation token.'], 404);
        }

        try {
            $registration = $this->sessions->reconnectWithCode(
                $registration,
                (string) $validated['reconnect_code'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reconnected. You may continue the examination.',
            'data' => [
                'lobby_status' => $registration->lobby_status,
                'last_heartbeat_at' => optional($registration->last_heartbeat_at)?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/exam/agree
     *
     * Records the student's acceptance of the Terms & Agreement screen.
     * Sets agreed_at on the ExaminationRegistration identified by the
     * participation_token in the request body. Idempotent — safe to call
     * multiple times; only sets the timestamp on the first call.
     */
    public function agree(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'participation_token' => ['required', 'string', 'max:255'],
        ]);

        $registration = $this->sessions->findRegistrationByToken($validated['participation_token']);
        if (! $registration) {
            return response()->json(['success' => false, 'message' => 'Invalid participation token.'], 404);
        }

        try {
            $registration = $this->sessions->agreeToTerms($registration);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'agreed_at' => $registration->agreed_at?->toIso8601String(),
        ]);
    }
}
