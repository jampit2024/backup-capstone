<?php

namespace App\Http\Controllers\Proctor\Api\V1;

use App\Enums\ExamSessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExaminationScheduleResource;
use App\Http\Resources\Api\V1\QuestionBankResource;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationRoom;
use App\Models\ExaminationSchedule;
use App\Models\ExamSession;
use App\Models\QuestionBank;
use App\Services\ExamSessionService;
use App\Services\ExaminationPasskeyService;
use App\Support\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class ExamSessionController extends Controller
{
    public function __construct(private ExamSessionService $sessions)
    {
    }

    public function schedules(Request $request): JsonResponse
    {
        $user = $this->ensureProctor($request);

        $query = ExaminationSchedule::query()
            ->when(
                $user->isProctor() && ! $request->boolean('include_history'),
                fn ($query) => $query->inCurrentAcademicYear(),
            )
            ->withCount('registrations')
            ->withCount('rooms')
            ->withCount([
                'rooms as completed_rooms_count' => fn ($query) => $query->whereHas(
                    'examSessions',
                    fn ($sessions) => $sessions->where('status', ExamSessionStatus::Ended),
                ),
            ])
            ->orderBy('exam_date')
            ->orderBy('start_time');

        if ($date = $request->query('date')) {
            $query->whereDate('exam_date', $date);
        }

        $items = $query->paginate($request->integer('per_page', 100));
        $serverNow = Carbon::now('Asia/Manila');

        return response()->json([
            'success' => true,
            'data' => ExaminationScheduleResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'today' => $serverNow->toDateString(),
                'server_now' => $serverNow->toIso8601String(),
            ],
        ]);
    }

    public function rooms(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        $user = $this->ensureProctor($request);
        if ($user->isProctor()) {
            $this->ensureCurrentAcademicYear($examinationSchedule);
        }

        $rooms = $this->sessions->roomsForSchedule($examinationSchedule);
        $assigned = $examinationSchedule->rooms()->where('proctor_id', $user->id)->pluck('id')->all();
        $busy = ExamSession::where('proctor_id', $user->id)->whereIn('status', ['open', 'lobby_open', 'in_progress'])->pluck('examination_room_id')->all();
        $rooms = array_map(function ($room) use ($user, $assigned, $busy) {
            $room['can_open'] = (! $room['owner_id'] || (int) $room['owner_id'] === (int) $user->id)
                && ($user->isAdmin() || ((! $room['assigned_proctor_id'] || (int) $room['assigned_proctor_id'] === (int) $user->id)
                    && (! $assigned || in_array($room['id'], $assigned))))
                && (! $busy || in_array($room['id'], $busy))
                && $room['status'] !== 'ended';
            $room['can_control'] = (int) $room['owner_id'] === (int) $user->id || $user->isAdmin();
            return $room;
        }, $rooms);
        $batch = $this->sessions->batchProgress($examinationSchedule);

        return response()->json([
            'success' => true,
            'data' => $rooms,
            'batch' => $batch,
        ]);
    }

    public function questionBanks(Request $request): JsonResponse
    {
        $this->ensureProctor($request);

        $banks = QuestionBank::query()
            ->withCount('subjects')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => QuestionBankResource::collection($banks),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $user = $this->ensureProctor($request);

        $validated = $request->validate([
            'examination_schedule_id' => ['required', 'integer', 'exists:exam_schedules,id'],
            'examination_room_id' => ['required', 'integer', 'exists:exam_rooms,id'],
            'question_bank_id' => ['nullable', 'integer', 'exists:exam_packs,id'],
            'wifi_ssid' => ['nullable', 'string', 'max:255'],
            'wifi_bssid' => ['nullable', 'string', 'max:64'],
            'local_server_ip' => ['nullable', 'string', 'max:255'],
        ]);

        $schedule = ExaminationSchedule::query()->findOrFail($validated['examination_schedule_id']);
        $passkeys = app(ExaminationPasskeyService::class);
        $manilaToday = Carbon::now('Asia/Manila')->startOfDay();
        $scheduledDate = $schedule->exam_date
            ? Carbon::parse($schedule->exam_date, 'Asia/Manila')->startOfDay()
            : null;
        if (! $scheduledDate || ! $scheduledDate->equalTo($manilaToday)) {
            $todayLabel = $manilaToday->format('D, d M Y');
            $scheduleLabel = $scheduledDate?->format('d M Y') ?? 'an unknown date';
            $message = "You can only open today's schedule ({$todayLabel}). This schedule is for {$scheduleLabel}";
            if ($scheduledDate?->greaterThan($manilaToday)) {
                $message .= '. It will be available on that day.';
            } else {
                $message .= ', so it is view only.';
            }
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }
        if (! $passkeys->isWithinScheduleWindow($schedule)) {
            return response()->json([
                'success' => false,
                'message' => $passkeys->scheduleWindowMessage($schedule),
            ], 403);
        }

        try {
            if ($user->isProctor()) {
                $this->ensureCurrentAcademicYear($schedule);
            }
            $room = ExaminationRoom::query()->findOrFail($validated['examination_room_id']);
            $session = $this->sessions->openLobby(
                $user,
                $schedule,
                $validated['question_bank_id'] ?? null,
                $room,
                $validated['wifi_ssid'] ?? null,
                $validated['local_server_ip'] ?? null,
                $validated['wifi_bssid'] ?? null,
            );
        } catch (RuntimeException $e) {
            if (! $passkeys->isWithinScheduleWindow($schedule)) {
                return response()->json([
                    'success' => false,
                    'message' => $passkeys->scheduleWindowMessage($schedule),
                ], 403);
            }
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->lobbyPayload($request, $session),
            'message' => 'Examination lobby opened.',
        ], 201);
    }

    public function show(Request $request, ExamSession $examSession): JsonResponse
    {
        $this->ensureProctorOwns($request, $examSession);
        $user = $this->ensureProctor($request);
        if ($user->isProctor()) {
            $this->ensureCurrentAcademicYear($examSession->schedule);
        }

        return response()->json([
            'success' => true,
            'data' => $this->lobbyPayload($request, $examSession),
        ]);
    }

    public function lobbyBySchedule(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        $user = $this->ensureProctor($request);
        if ($user->isProctor()) {
            $this->ensureCurrentAcademicYear($examinationSchedule);
        }

        $roomId = $request->integer('examination_room_id') ?: null;

        $query = ExamSession::query()
            ->where('examination_schedule_id', $examinationSchedule->id)
            ->whereIn('status', ['lobby_open', 'in_progress', 'ended']);

        if ($roomId) {
            $query->where('examination_room_id', $roomId);
        }

        // Prefer an active lobby; otherwise the latest ended session (view results / sync).
        $session = (clone $query)->whereIn('status', ['lobby_open', 'in_progress'])->latest('id')->first()
            ?? $query->where('status', 'ended')->latest('id')->first();

        if (! $session) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $this->ensureProctorOwns($request, $session);

        return response()->json([
            'success' => true,
            'data' => $this->lobbyPayload($request, $session),
        ]);
    }

    public function regenerate(Request $request, ExamSession $examSession): JsonResponse
    {
        $this->ensureProctorOwns($request, $examSession);

        try {
            $session = $this->sessions->regenerateCode($examSession);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->lobbyPayload($request, $session),
            'message' => 'Examination code regenerated.',
        ]);
    }

    /**
     * Update the proctor phone's LAN IP / Wi‑Fi SSID after a network change.
     */
    public function updateNetwork(Request $request, ExamSession $examSession): JsonResponse
    {
        $this->ensureProctorOwns($request, $examSession);

        $validated = $request->validate([
            'local_server_ip' => ['required', 'string', 'max:45'],
            'wifi_ssid' => ['nullable', 'string', 'max:255'],
            'wifi_bssid' => ['nullable', 'string', 'max:64'],
            'regenerate_if_empty' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->sessions->updateNetwork(
                $examSession,
                $validated['local_server_ip'],
                $validated['wifi_ssid'] ?? null,
                array_key_exists('regenerate_if_empty', $validated)
                    ? (bool) $validated['regenerate_if_empty']
                    : true,
                $validated['wifi_bssid'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['code_regenerated']
                ? 'Proctor LAN address updated and examination code regenerated (empty lobby).'
                : 'Proctor LAN address updated.',
            'data' => $this->lobbyPayload($request, $result['session']),
            'code_regenerated' => $result['code_regenerated'],
            'joined_count' => $result['joined_count'],
        ]);
    }

    public function start(Request $request, ExamSession $examSession): JsonResponse
    {
        $this->ensureProctorOwns($request, $examSession);

        try {
            $session = $this->sessions->start($examSession);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->lobbyPayload($request, $session),
            'message' => 'Examination started.',
        ]);
    }

    public function close(Request $request, ExamSession $examSession): JsonResponse
    {
        $this->ensureProctorOwns($request, $examSession);

        try {
            $this->sessions->closeLobby($examSession);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lobby closed. You can open this room again when ready.',
        ]);
    }

    /**
     * Close the active lobby for a schedule (optional room query param).
     * This is a convenience endpoint for clients that only have the schedule id.
     */
    public function closeBySchedule(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        $user = $this->ensureProctor($request);
        if ($user->isProctor()) {
            $this->ensureCurrentAcademicYear($examinationSchedule);
        }

        $roomId = $request->integer('examination_room_id') ?: null;

        $query = ExamSession::query()
            ->where('examination_schedule_id', $examinationSchedule->id)
            ->where('status', ExamSessionStatus::LobbyOpen);

        if ($roomId) {
            $query->where('examination_room_id', $roomId);
        }

        $session = $query->latest('id')->first();

        if (! $session) {
            return response()->json(['success' => false, 'message' => 'No open lobby found for this schedule.'], 404);
        }

        $this->ensureProctorOwns($request, $session);

        try {
            $this->sessions->closeLobby($session);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lobby closed. You can open this room again when ready.',
        ]);
    }

    public function end(Request $request, ExamSession $examSession): JsonResponse
    {
        $this->ensureProctorOwns($request, $examSession);
        $session = $this->sessions->end($examSession);

        return response()->json([
            'success' => true,
            'data' => $this->lobbyPayload($request, $session),
            'message' => 'Examination ended.',
        ]);
    }

    public function terminateStudent(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        return $this->removeRegistration($request, $registration);
    }

    public function removeRegistration(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        $this->ensureProctor($request);
        if ($registration->exam_session_id) {
            $session = ExamSession::query()->find($registration->exam_session_id);
            if ($session) {
                $this->ensureProctorOwns($request, $session);
            }
        }

        $reason = (string) ($request->input('reason') ?? 'proctor_removed');
        $this->sessions->removeRegistration($registration, $reason);

        return response()->json([
            'success' => true,
            'message' => 'Student removed from the session.',
        ]);
    }

    public function allowReconnect(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        $this->ensureProctor($request);
        if ($registration->exam_session_id) {
            $session = ExamSession::query()->find($registration->exam_session_id);
            if ($session) {
                $this->ensureProctorOwns($request, $session);
            }
        }

        try {
            $result = $this->sessions->allowReconnect($registration);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reconnect code issued. Tell the student this code.',
            'data' => [
                'registration_id' => $result['registration']->id,
                'reconnect_code' => $result['reconnect_code'],
                'expires_at' => $result['expires_at'],
                'student_name' => $result['registration']->applicant?->name,
            ],
        ]);
    }

    private function lobbyPayload(Request $request, ExamSession $session): array
    {
        $user = $request->user();
        $snapshot = $this->sessions->lobbySnapshot($session, true);
        $canControl = $user && ($user->isAdmin() || $session->isOwnedBy($user));

        return [
            ...$snapshot,
            'can_control' => (bool) $canControl,
            'is_owner' => $user ? $session->isOwnedBy($user) : false,
        ];
    }

    private function ensureProctor(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->isProctor() && ! $user->isAdmin())) {
            abort(403, 'Proctor access only.');
        }

        return $user;
    }

    private function ensureProctorOwns(Request $request, ExamSession $session): void
    {
        $user = $this->ensureProctor($request);
        if ($user->isAdmin()) {
            return;
        }
        $this->ensureCurrentAcademicYear($session->schedule);
        if (! $session->isOwnedBy($user)) {
            abort(403, 'Only the proctor who opened this lobby can control the examination.');
        }
    }

    private function ensureCurrentAcademicYear(?ExaminationSchedule $schedule): void
    {
        abort_unless(
            $schedule && AcademicYear::isCurrent($schedule->academic_year),
            403,
            AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE
        );
    }
}
