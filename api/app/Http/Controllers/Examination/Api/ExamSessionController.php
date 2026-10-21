<?php

namespace App\Http\Controllers\Examination\Api;

use App\Enums\ExamSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\ExamSession;
use App\Models\ExamSessionJoin;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ExamSessionController extends Controller
{
    /**
     * Open a new unique exam session for a proctor.
     * Closes any existing open session for this proctor (or returns existing if configured).
     */
    public function open(Request $request): JsonResponse
    {
        $user = $request->user() ?: User::find($request->input('proctor_id'));
        abort_unless($user && ($user->isAdmin() || $user->isProctor()), 403);
        if ($request->user()) {
            abort_unless((int) $request->input('proctor_id') === (int) $request->user()->id, 403, 'You may only open your own session.');
        }
        if ($request->input('room_id') || $request->input('schedule_id')) {
            $request->merge([
                'examination_schedule_id' => $request->input('schedule_id'),
                'examination_room_id' => $request->input('room_id'),
                'local_server_ip' => $request->input('local_ip'),
            ]);
            return app(\App\Http\Controllers\Proctor\Api\V1\ExamSessionController::class)->open($request);
        }
        $validator = Validator::make($request->all(), [
            'proctor_id' => ['required', 'exists:users,id'],
            'local_ip' => ['required', 'string', 'max:45'],
            'local_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'schedule_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $proctorId = (int) $request->input('proctor_id');
        $localIp = trim((string) $request->input('local_ip'));
        $localPort = (int) ($request->input('local_port') ?: 9777);
        $reopenBehavior = config('exam_session.reopen_behavior', 'auto_close');

        return DB::transaction(function () use ($proctorId, $localIp, $localPort, $reopenBehavior, $request) {
            $existingOpen = ExamSession::query()
                ->where('proctor_id', $proctorId)
                ->whereIn('status', [ExamSessionStatus::Open, ExamSessionStatus::LobbyOpen])
                ->latest('id')
                ->first();

            if ($existingOpen && $reopenBehavior === 'return_existing' && ! $existingOpen->isExpired()) {
                $existingOpen->update([
                    'proctor_local_ip' => $localIp,
                    'local_server_ip' => $localIp,
                    'proctor_local_port' => $localPort,
                ]);

                return $this->formatSessionResponse($existingOpen, 200);
            }

            // Default 'auto_close': close any existing open sessions for this proctor
            if ($existingOpen) {
                $existingOpen->update([
                    'status' => ExamSessionStatus::Closed,
                    'ended_at' => now(),
                ]);
            }

            $ttlHours = (int) config('exam_session.ttl_hours', 2);
            $sessionCode = ExamSession::generateSessionCode();
            $qrToken = ExamSession::generateQrToken();
            $expiresAt = now()->addHours($ttlHours);

            $session = ExamSession::query()->create([
                'proctor_id' => $proctorId,
                'session_code' => $sessionCode,
                'exam_code' => $sessionCode,
                'qr_token' => $qrToken,
                'proctor_local_ip' => $localIp,
                'local_server_ip' => $localIp,
                'proctor_local_port' => $localPort,
                'status' => ExamSessionStatus::Open,
                'expires_at' => $expiresAt,
                'examination_schedule_id' => $request->input('schedule_id'),
                'examination_room_id' => $request->input('room_id'),
            ]);

            return $this->formatSessionResponse($session, 201);
        });
    }

    /**
     * Resolve a session code or QR token to locate the proctor's LAN host and port.
     */
    public function resolve(Request $request): JsonResponse
    {
        $code = $request->query('code');
        $token = $request->query('token');
        $studentId = $request->query('student_id');
        $deviceId = $request->query('device_id');

        if (! $code && ! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Provide a session code or QR token to resolve.',
            ], 422);
        }

        $query = ExamSession::query();

        if ($code) {
            $cleanedCode = strtoupper(trim((string) $code));
            $query->where(function ($q) use ($cleanedCode) {
                $q->where('session_code', $cleanedCode)
                    ->orWhere('exam_code', $cleanedCode);
            });
        } else {
            $cleanedToken = trim((string) $token);
            $query->where('qr_token', $cleanedToken);
        }

        /** @var ExamSession|null $session */
        $session = $query->latest('id')->first();

        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired examination session code.',
            ], 404);
        }

        // Check expiration
        if ($session->isExpired()) {
            if ($session->status === ExamSessionStatus::Open || $session->status === ExamSessionStatus::LobbyOpen) {
                $session->update([
                    'status' => ExamSessionStatus::Closed,
                    'ended_at' => now(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'This examination code has expired. Please ask your proctor for a new code.',
            ], 404);
        }

        $statusVal = $session->status instanceof ExamSessionStatus
            ? $session->status->value
            : (string) $session->status;

        if (! in_array($statusVal, ['open', 'lobby_open'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This examination session is already in progress or closed.',
            ], 404);
        }

        // Student uniqueness guard: block re-joining from a different device with same student credentials
        if ($studentId) {
            $cleanedStudentId = trim((string) $studentId);
            $cleanedDeviceId = $deviceId ? trim((string) $deviceId) : null;

            $existingJoin = ExamSessionJoin::query()
                ->where('exam_session_id', $session->id)
                ->where('student_id', $cleanedStudentId)
                ->first();

            if ($existingJoin) {
                if ($cleanedDeviceId && $existingJoin->device_id && $existingJoin->device_id !== $cleanedDeviceId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This student account has already joined this examination session from another device.',
                    ], 409);
                }
            } else {
                ExamSessionJoin::query()->create([
                    'exam_session_id' => $session->id,
                    'student_id' => $cleanedStudentId,
                    'device_id' => $cleanedDeviceId,
                    'joined_at' => now(),
                ]);
            }
        }

        // Generate a 6-digit short-lived one-time passkey for LAN handshake
        $passkey = sprintf('%06d', random_int(100000, 999999));

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'session_code' => $session->session_code ?? $session->exam_code,
            'proctor_local_ip' => $session->proctor_local_ip ?? $session->local_server_ip,
            'proctor_local_port' => (int) ($session->proctor_local_port ?: 9777),
            'passkey' => $passkey,
            'status' => $statusVal,
            'schedule_id' => $session->examination_schedule_id,
            'room_id' => $session->examination_room_id,
            'qr_token' => $session->qr_token,
        ]);
    }

    /**
     * Set session status to in_progress when exam starts.
     */
    public function start(ExamSession $session): JsonResponse
    {
        $user = request()->user() ?: $session->proctor;
        abort_unless($user && ($user->isAdmin() || $session->isOwnedBy($user)), 403);
        if ($session->examination_room_id) {
            return app(\App\Http\Controllers\Proctor\Api\V1\ExamSessionController::class)->start(request(), $session);
        }
        $session->update([
            'status' => ExamSessionStatus::InProgress,
            'started_at' => $session->started_at ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Examination session started.',
            'session' => [
                'id' => $session->id,
                'status' => $session->status->value,
                'started_at' => $session->started_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Set session status to closed when room is closed or exam ends.
     */
    public function close(ExamSession $session): JsonResponse
    {
        $user = request()->user() ?: $session->proctor;
        abort_unless($user && ($user->isAdmin() || $session->isOwnedBy($user)), 403);
        if ($session->examination_room_id) {
            return app(\App\Http\Controllers\Proctor\Api\V1\ExamSessionController::class)->close(request(), $session);
        }
        $session->update([
            'status' => ExamSessionStatus::Closed,
            'ended_at' => $session->ended_at ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Examination session closed.',
            'session' => [
                'id' => $session->id,
                'status' => $session->status->value,
                'ended_at' => $session->ended_at?->toIso8601String(),
            ],
        ]);
    }

    private function formatSessionResponse(ExamSession $session, int $statusCode): JsonResponse
    {
        $statusVal = $session->status instanceof ExamSessionStatus
            ? $session->status->value
            : (string) $session->status;

        $qrPayload = json_encode([
            'type' => 'exam_session',
            'token' => $session->qr_token,
            'code' => $session->session_code ?? $session->exam_code,
            'ip' => $session->proctor_local_ip ?? $session->local_server_ip,
            'port' => (int) ($session->proctor_local_port ?: 9777),
        ], JSON_UNESCAPED_SLASHES);

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'session_code' => $session->session_code ?? $session->exam_code,
            'qr_token' => $session->qr_token,
            'proctor_local_ip' => $session->proctor_local_ip ?? $session->local_server_ip,
            'proctor_local_port' => (int) ($session->proctor_local_port ?: 9777),
            'status' => $statusVal,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'qr_payload' => $qrPayload,
        ], $statusCode);
    }
}
