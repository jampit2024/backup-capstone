<?php

namespace App\Http\Controllers\Examination;

use App\Http\Controllers\Controller;

use App\Models\ActivityLog;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Services\ExaminationPasskeyService;
use App\Support\ExamTestMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class ExaminationPasskeyController extends Controller
{
    public function __construct(private ExaminationPasskeyService $passkeys)
    {
    }

    public function testMode(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['enabled' => ExamTestMode::enabled()],
        ]);
    }

    public function testStudents(Request $request): JsonResponse
    {
        if (! ExamTestMode::enabled()) {
            return response()->json(['success' => false, 'message' => 'Test schedules are disabled in this environment.'], 404);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $registrations = ExaminationRegistration::query()
            ->with(['applicant', 'schedule'])
            ->whereIn('passkey_status', ['not_sent', 'sent'])
            ->whereNotNull('exam_passkey_hash')
            ->whereNotNull('exam_passkey_encrypted')
            ->whereNull('exam_session_id')
            ->whereNull('score')
            ->where('result_status', 'pending')
            ->whereHas('applicant', function ($studentQuery) use ($search) {
                if ($search === '') return;
                $studentQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('applicant_code', 'like', "%{$search}%")
                    ->orWhere('gmail', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->orderBy('id')
            ->limit(50)
            ->get();
        $registrations = $registrations->filter(fn (ExaminationRegistration $registration) =>
            $this->passkeys->adminPasskeyView($registration)['exam_passkey'] !== null
        );

        return response()->json([
            'success' => true,
            'data' => $registrations->map(fn (ExaminationRegistration $registration) => [
                'registration_id' => $registration->id,
                'applicant_code' => $registration->applicant?->applicant_code,
                'name' => $registration->applicant?->name,
                'email' => $registration->applicant?->notificationEmail(),
                'current_exam_date' => optional($registration->schedule?->exam_date)?->toDateString(),
                'current_time_slot' => $registration->schedule?->time_slot,
                'passkey_status' => $registration->passkey_status,
            ])->values(),
        ]);
    }

    public function createTestSchedule(Request $request): JsonResponse
    {
        return app(ExaminationRegistrationController::class)->createTestSchedule($request);
    }

    public function generateByDate(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'exam_date' => ['required', 'date'],
            'registration_ids' => ['sometimes', 'array', 'max:5000'],
            'registration_ids.*' => ['integer', 'distinct', 'exists:exam_registrations,id'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);

        $ids = $validated['registration_ids'] ?? null;
        if ($ids && ExaminationRegistration::query()->whereIn('id', $ids)
            ->whereHas('schedule', fn ($query) => $query->whereDate('exam_date', $validated['exam_date']))
            ->count() !== count($ids)) {
            return response()->json(['success' => false, 'message' => 'Every selected student must belong to this exam date.'], 422);
        }

        $regenerate = (bool) ($validated['regenerate'] ?? false);
        $result = $this->passkeys->generateForDate($validated['exam_date'], $ids, $regenerate);
        foreach ($this->passkeys->slotsForDate($validated['exam_date']) as $slot) {
            $this->auditPasskeyAccess($request, (int) $slot['id'], 'generated');
        }

        return response()->json([
            'success' => true,
            'message' => $regenerate
                ? "Re-issued {$result['generated']} examination keys across {$result['schedules']} time slot(s). Previous codes for these students no longer work."
                : "Generated {$result['generated']} passkeys across {$result['schedules']} time slot(s).",
            'data' => $result,
        ]);
    }

    public function sendByDate(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'exam_date' => ['required', 'date'],
            'registration_ids' => ['sometimes', 'array', 'max:5000'],
            'registration_ids.*' => ['integer', 'distinct', 'exists:exam_registrations,id'],
            'keys' => ['sometimes', 'array', 'max:5000'],
            'keys.*.registration_id' => ['required_with:keys', 'integer', 'exists:exam_registrations,id'],
            'keys.*.key' => ['required_with:keys', 'string', 'regex:/\A[A-Za-z0-9]{8}\z/'],
        ]);

        $ids = $validated['registration_ids'] ?? null;
        if ($ids && ExaminationRegistration::query()->whereIn('id', $ids)
            ->whereHas('schedule', fn ($query) => $query->whereDate('exam_date', $validated['exam_date']))
            ->count() !== count($ids)) {
            return response()->json(['success' => false, 'message' => 'Every selected student must belong to this exam date.'], 422);
        }

        $result = $this->passkeys->sendForDate(
            $validated['exam_date'],
            $request->user(),
            $ids,
            $validated['keys'] ?? [],
        );
        foreach ($this->passkeys->slotsForDate($validated['exam_date']) as $slot) {
            $this->auditPasskeyAccess($request, (int) $slot['id'], 'sent');
        }

        $message = "Sent {$result['sent']} examination keys for {$validated['exam_date']}";
        $details = [];
        if ($result['failed'] > 0) {
            $details[] = "{$result['failed']} failed";
        }
        if ($result['skipped'] > 0) {
            $details[] = "{$result['skipped']} skipped (no Gmail on student record — re-import with a Gmail column)";
        }
        $details[] = "{$result['schedules']} time slot(s)";
        $message .= ' ('.implode(', ', $details).').';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $result,
        ]);
    }

    public function indexByDate(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'exam_date' => ['required', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'email_status' => ['nullable', 'string', 'max:20'],
            'join_status' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(['not_sent', 'sent', 'used', 'revoked'])],
            'schedule_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:5000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->passkeys->listForDate(
            $validated['exam_date'],
            $validated['search'] ?? null,
            $validated['email_status'] ?? null,
            $validated['join_status'] ?? null,
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['page'] ?? 1),
            isset($validated['schedule_id']) ? (int) $validated['schedule_id'] : null,
            $validated['status'] ?? null,
        );
        foreach (collect($page->items())->pluck('schedule_id')->filter()->unique() as $scheduleId) {
            $this->auditPasskeyAccess($request, (int) $scheduleId, 'viewed');
        }

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'exam_date' => $validated['exam_date'],
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'counts' => $this->passkeys->countsForDate($validated['exam_date'], isset($validated['schedule_id']) ? (int) $validated['schedule_id'] : null),
                'slots' => $this->passkeys->slotsForDate($validated['exam_date']),
            ],
        ]);
    }

    public function generate(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'registration_ids' => ['sometimes', 'array', 'max:5000'],
            'registration_ids.*' => ['integer', 'distinct', 'exists:exam_registrations,id'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);
        $ids = $validated['registration_ids'] ?? null;
        if ($ids && count($ids) !== $examinationSchedule->registrations()->whereIn('id', $ids)->count()) {
            return response()->json(['success' => false, 'message' => 'Every selected student must belong to this batch.'], 422);
        }

        $regenerate = (bool) ($validated['regenerate'] ?? false);
        $result = $this->passkeys->generateForSchedule($examinationSchedule, $ids, $regenerate);
        $this->auditPasskeyAccess($request, (int) $examinationSchedule->id, 'generated');

        return response()->json([
            'success' => true,
            'message' => $regenerate
                ? "Re-issued {$result['generated']} examination keys ({$result['already_had']} skipped). Previous codes for these students no longer work."
                : "Generated {$result['generated']} passkeys ({$result['already_had']} already had keys).",
            'data' => $result,
        ]);
    }

    public function send(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'registration_ids' => ['sometimes', 'array', 'max:5000'],
            'registration_ids.*' => ['integer', 'distinct', 'exists:exam_registrations,id'],
            'keys' => ['sometimes', 'array', 'max:5000'],
            'keys.*.registration_id' => ['required_with:keys', 'integer', 'exists:exam_registrations,id'],
            'keys.*.key' => ['required_with:keys', 'string', 'regex:/\A[A-Za-z0-9]{8}\z/'],
        ]);
        $ids = $validated['registration_ids'] ?? null;
        if ($ids && count($ids) !== $examinationSchedule->registrations()->whereIn('id', $ids)->count()) {
            return response()->json(['success' => false, 'message' => 'Every selected student must belong to this batch.'], 422);
        }

        $result = $this->passkeys->sendForSchedule($examinationSchedule, $request->user(), $ids, $validated['keys'] ?? []);
        $this->auditPasskeyAccess($request, (int) $examinationSchedule->id, 'sent');

        return response()->json([
            'success' => true,
            'message' => "Sent {$result['sent']} examination keys ({$result['failed']} failed, {$result['skipped']} skipped).",
            'data' => $result,
        ]);
    }

    public function index(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'email_status' => ['nullable', 'string', 'max:20'],
            'join_status' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(['not_sent', 'sent', 'used', 'revoked'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:5000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->passkeys->listForSchedule(
            $examinationSchedule,
            $validated['search'] ?? null,
            $validated['email_status'] ?? null,
            $validated['join_status'] ?? null,
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['page'] ?? 1),
            $validated['status'] ?? null,
        );
        $this->auditPasskeyAccess($request, (int) $examinationSchedule->id, 'viewed');

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'counts' => $this->passkeys->countsForSchedule($examinationSchedule),
            ],
        ]);
    }

    public function regenerateBatch(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $result = $this->passkeys->regenerateForAdminSchedule($examinationSchedule);
        $this->auditPasskeyAccess($request, (int) $examinationSchedule->id, 'regenerated');

        return response()->json([
            'success' => true,
            'message' => "Regenerated {$result['generated']} passkeys for this batch. Used and revoked attempts remain blocked.",
            'data' => $result,
        ]);
    }

    public function resend(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        try {
            $key = $this->passkeys->resend($registration->loadMissing('applicant'), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Unable to resend examination key.'], 500);
        }

        $this->auditPasskeyAccess($request, (int) $registration->examination_schedule_id, 'resent');
        return response()->json([
            'success' => true,
            'message' => 'Examination key resent.',
            'data' => [
                'registration_id' => $registration->id,
                'passkey_email_status' => $registration->fresh()->passkey_email_status?->value
                    ?? $registration->fresh()->passkey_email_status,
                'key' => $key,
                'registration_id' => $registration->id,
                'name' => $registration->applicant?->name,
                'applicant_code' => $registration->applicant?->applicant_code,
            ],
        ]);
    }

    public function regenerate(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }
        try {
            $key = $this->passkeys->regenerate($registration);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->auditPasskeyAccess($request, (int) $registration->examination_schedule_id, 'regenerated');
        return response()->json(['success' => true, 'message' => 'A new one-time examination key was generated.', 'data' => $key]);
    }

    public function resetForTesting(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        try {
            $data = $this->passkeys->resetAndReissueForTesting($registration);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->auditPasskeyAccess($request, (int) $registration->examination_schedule_id, 'reset');
        return response()->json([
            'success' => true,
            'message' => 'Finished attempt reset. A new examination key is ready for testing.',
            'data' => $data,
        ]);
    }

    public function revoke(Request $request, ExaminationRegistration $registration): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }
        try {
            $this->passkeys->revoke($registration);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Examination key revoked.']);
    }

    private function auditPasskeyAccess(Request $request, int $scheduleId, string $action): void
    {
        ActivityLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'examination_passkeys_'.$action,
            'description' => "Admin {$action} examination passkeys for schedule {$scheduleId}.",
            'subject_type' => ExaminationSchedule::class,
            'subject_id' => $scheduleId,
        ]);
    }
}
