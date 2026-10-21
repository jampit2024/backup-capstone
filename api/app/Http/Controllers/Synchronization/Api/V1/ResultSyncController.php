<?php

namespace App\Http\Controllers\Synchronization\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExaminationSchedule;
use App\Models\ExamSession;
use App\Services\AdminResultSyncService;
use App\Services\ExamDayPackService;
use App\Support\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ResultSyncController extends Controller
{
    public function __construct(
        private AdminResultSyncService $sync,
        private ExamDayPackService $pack,
    ) {
    }

    /** Proctor (LAN): pending count for a session or all. */
    public function pending(Request $request): JsonResponse
    {
        $this->ensureProctor($request);
        $sessionId = $request->integer('exam_session_id') ?: null;
        $session = $sessionId ? ExamSession::query()->find($sessionId) : null;

        return response()->json([
            'success' => true,
            'data' => [
                'pending' => $this->sync->pendingCount($session),
                'configured' => $this->syncConfigured(),
                'mode' => 'option_b_hybrid',
            ],
        ]);
    }

    /** Proctor (LAN): push pending results to the central admin API (needs internet). */
    public function push(Request $request): JsonResponse
    {
        $this->ensureProctor($request);

        $sessionId = $request->integer('exam_session_id') ?: null;
        $session = $sessionId ? ExamSession::query()->find($sessionId) : null;

        try {
            $result = $this->sync->sync($session);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $result,
        ]);
    }

    /**
     * Proctor (LAN): pull schedules/applicants/banks/proctors from central admin.
     * Requires internet on the LAN server. Run before exam day.
     */
    public function pull(Request $request): JsonResponse
    {
        $this->ensureProctor($request);

        $examDate = $request->validate(['exam_date' => ['nullable', 'date']])['exam_date'] ?? null;
        $this->ensureCurrentAcademicYearDate($examDate);

        try {
            $result = $this->pack->pullFromCloud($examDate);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $result,
        ]);
    }

    /**
     * Central admin cloud: export exam-day pack for LAN hosts.
     * Auth: Bearer ADMIN_SYNC_TOKEN (or Sanctum admin).
     */
    public function exportPack(Request $request): JsonResponse
    {
        if (! $this->authorizeSyncToken($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized sync.'], 401);
        }

        $validated = $request->validate([
            'exam_date' => ['nullable', 'date'],
            'schedule_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $examDate = $validated['exam_date'] ?? null;
        $scheduleId = isset($validated['schedule_id']) ? (int) $validated['schedule_id'] : null;
        $this->ensureCurrentAcademicYearDate($examDate);
        $this->ensureCurrentAcademicYearScheduleId($scheduleId);
        $includeAuth = filter_var($request->query('include_auth', false), FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'success' => true,
            'data' => $this->pack->export($examDate, $includeAuth, $scheduleId),
        ]);
    }

    /**
     * Central admin cloud: lightweight pack freshness / reschedule check.
     * Auth: Bearer ADMIN_SYNC_TOKEN (or Sanctum admin).
     */
    public function packStatus(Request $request): JsonResponse
    {
        if (! $this->authorizeSyncToken($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized sync.'], 401);
        }

        $validated = $request->validate([
            'exam_date' => ['nullable', 'date'],
            'schedule_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $examDate = $validated['exam_date'] ?? null;
        $scheduleId = isset($validated['schedule_id']) ? (int) $validated['schedule_id'] : null;
        $this->ensureCurrentAcademicYearDate($examDate);
        $this->ensureCurrentAcademicYearScheduleId($scheduleId);
        $since = $request->query('since');
        $since = is_string($since) && $since !== '' ? $since : null;
        $fingerprint = $request->query('fingerprint');
        $fingerprint = is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : null;

        return response()->json([
            'success' => true,
            'data' => $this->pack->status($examDate, $since, $fingerprint, $scheduleId),
        ]);
    }

    /**
     * Admin cloud: receive a batch of LAN exam results.
     * Auth: Bearer ADMIN_SYNC_TOKEN (or Sanctum admin).
     */
    public function ingest(Request $request): JsonResponse
    {
        if (! $this->authorizeSyncToken($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized sync.'], 401);
        }

        $validated = $request->validate([
            'results' => ['required', 'array', 'min:1'],
            'results.*.applicant_code' => ['required', 'string'],
            'results.*.examination_schedule_id' => ['required', 'integer'],
            'results.*.client_local_id' => ['nullable', 'string', 'max:120'],
            'results.*.lan_registration_id' => ['nullable', 'integer'],
            'results.*.attendance_status' => ['nullable', 'string', 'max:40'],
            'results.*.result_status' => ['nullable', 'string', 'in:pending,passed,failed'],
            'results.*.score' => ['nullable', 'numeric'],
            'results.*.items_correct' => ['nullable', 'integer'],
            'results.*.items_total' => ['nullable', 'integer'],
            'results.*.grade_point' => ['nullable', 'numeric'],
            'results.*.answers' => ['nullable', 'array'],
        ]);

        $result = $this->sync->ingest($validated['results']);

        return response()->json([
            'success' => true,
            'message' => 'Results ingested.',
            'data' => $result,
        ]);
    }

    private function authorizeSyncToken(Request $request): bool
    {
        $expected = (string) (config('services.admin_sync.token') ?: env('ADMIN_SYNC_TOKEN', ''));
        $bearer = (string) $request->bearerToken();
        $user = $request->user();

        $tokenOk = $expected !== '' && hash_equals($expected, $bearer);
        $adminOk = $user && method_exists($user, 'isAdmin') && $user->isAdmin();

        return $tokenOk || $adminOk;
    }

    private function ensureCurrentAcademicYearDate(?string $examDate): void
    {
        if ($examDate === null) {
            return;
        }

        abort_unless(
            AcademicYear::isCurrent(AcademicYear::forDate($examDate)),
            403,
            AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE
        );
    }

    private function ensureCurrentAcademicYearScheduleId(?int $scheduleId): void
    {
        if ($scheduleId === null) {
            return;
        }

        $schedule = ExaminationSchedule::query()->find($scheduleId);
        abort_unless(
            $schedule && AcademicYear::isCurrent($schedule->academic_year),
            403,
            AcademicYear::OUTSIDE_CURRENT_YEAR_MESSAGE
        );
    }

    private function syncConfigured(): bool
    {
        $url = (string) (config('services.admin_sync.url') ?: env('ADMIN_SYNC_URL', ''));
        $token = (string) (config('services.admin_sync.token') ?: env('ADMIN_SYNC_TOKEN', ''));

        return $url !== '' && $token !== '';
    }

    private function ensureProctor(Request $request)
    {
        $user = $request->user();
        if (! $user || (! $user->isProctor() && ! $user->isAdmin())) {
            abort(403, 'Proctor access only.');
        }

        return $user;
    }
}
