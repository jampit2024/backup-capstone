<?php

namespace App\Http\Controllers\Synchronization\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AdminResultSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives offline exam results from mobile devices when they regain internet.
 */
class OfflineResultController extends Controller
{
    public function __construct(private AdminResultSyncService $sync)
    {
    }

    public function ingest(Request $request): JsonResponse
    {
        $expected = (string) (config('services.admin_sync.token') ?: env('ADMIN_SYNC_TOKEN', ''));
        $bearer = (string) $request->bearerToken();
        $user = $request->user();

        $tokenOk = $expected !== '' && hash_equals($expected, $bearer);
        $staffOk = $user && (method_exists($user, 'isAdmin') && $user->isAdmin()
            || method_exists($user, 'isProctor') && $user->isProctor());

        if (! $tokenOk && ! $staffOk) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
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
            'results.*.answers.*.exam_question_id' => ['nullable', 'integer'],
            'results.*.answers.*.selected_answer' => ['nullable', 'string', 'max:10'],
            'results.*.answers.*.is_correct' => ['nullable', 'boolean'],
        ]);

        $result = $this->sync->ingest($validated['results']);

        $totalAccepted = count($result['accepted_ids'] ?? []) + count($result['accepted_client_ids'] ?? []);
        $rejectedCount = count($result['rejected'] ?? []);

        if ($totalAccepted === 0 && $rejectedCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: no results were accepted. Records may have an invalid schedule, an unknown student, or an existing official result that cannot be replaced.',
                'data' => $result,
            ], 422);
        }

        $msg = $rejectedCount > 0
            ? "Sync partial: {$result['created']} created, {$result['updated']} updated, {$rejectedCount} rejected."
            : "Offline results synced to administrator.";

        return response()->json([
            'success' => true,
            'message' => $msg,
            'data' => $result,
        ], 200);
    }
}
