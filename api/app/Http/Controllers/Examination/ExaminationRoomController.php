<?php

namespace App\Http\Controllers\Examination;

use App\Http\Controllers\Controller;

use App\Models\ExaminationRoom;
use App\Models\ExaminationSchedule;
use App\Models\User;
use App\Services\ExamSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExaminationRoomController extends Controller
{
    public function __construct(private ExamSessionService $sessions)
    {
    }

    public function index(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        // Do not auto-create here — admin manages rooms. Mobile may still seed defaults.
        $rooms = $examinationSchedule->rooms()
            ->with('proctor:id,name,email')
            ->orderBy('room_name')
            ->get()
            ->map(fn (ExaminationRoom $room) => $this->mapRoom($room));

        return response()->json([
            'success' => true,
            'data' => $rooms,
        ]);
    }

    public function store(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'room_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('exam_rooms', 'room_name')
                    ->where(fn ($q) => $q->where('examination_schedule_id', $examinationSchedule->id)),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'reschedule_capacity' => ['sometimes', 'required', 'integer', 'min:0', 'max:10000'],
            'proctor_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (! empty($validated['proctor_id'])) {
            $this->ensureProctorUser((int) $validated['proctor_id']);
        }

        $room = ExaminationRoom::query()->create([
            'examination_schedule_id' => $examinationSchedule->id,
            'room_name' => trim($validated['room_name']),
            'capacity' => $validated['capacity'],
            'reschedule_capacity' => $validated['reschedule_capacity'] ?? 10,
            'proctor_id' => $validated['proctor_id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Examination room created.',
            'data' => $this->mapRoom($room->load('proctor:id,name,email')),
        ], 201);
    }

    public function update(Request $request, ExaminationRoom $examinationRoom): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $validated = $request->validate([
            'room_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('exam_rooms', 'room_name')
                    ->where(fn ($q) => $q->where('examination_schedule_id', $examinationRoom->examination_schedule_id))
                    ->ignore($examinationRoom->id),
            ],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:500'],
            'reschedule_capacity' => ['sometimes', 'required', 'integer', 'min:0', 'max:10000'],
            'proctor_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (array_key_exists('proctor_id', $validated) && $validated['proctor_id']) {
            $this->ensureProctorUser((int) $validated['proctor_id']);
        }

        $examinationRoom->forceFill([
            'room_name' => array_key_exists('room_name', $validated)
                ? trim($validated['room_name'])
                : $examinationRoom->room_name,
            'capacity' => $validated['capacity'] ?? $examinationRoom->capacity,
            'reschedule_capacity' => $validated['reschedule_capacity'] ?? $examinationRoom->reschedule_capacity,
            'proctor_id' => array_key_exists('proctor_id', $validated)
                ? $validated['proctor_id']
                : $examinationRoom->proctor_id,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Examination room updated.',
            'data' => $this->mapRoom($examinationRoom->fresh()->load('proctor:id,name,email')),
        ]);
    }

    public function destroy(Request $request, ExaminationRoom $examinationRoom): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $active = $examinationRoom->examSessions()
            ->whereIn('status', ['lobby_open', 'in_progress'])
            ->exists();

        if ($active) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a room with an active examination lobby. End the session first.',
            ], 422);
        }

        $examinationRoom->delete();

        return response()->json([
            'success' => true,
            'message' => 'Examination room deleted.',
        ]);
    }

    public function seedDefaults(Request $request, ExaminationSchedule $examinationSchedule): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $rooms = $this->sessions->ensureDefaultRooms($examinationSchedule);

        return response()->json([
            'success' => true,
            'message' => 'Default rooms are ready.',
            'data' => $rooms->load('proctor:id,name,email')->map(fn ($room) => $this->mapRoom($room))->values(),
        ]);
    }

    /**
     * Apply Room 01–03 to every schedule that does not yet have rooms.
     */
    public function seedDefaultsForAll(Request $request): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin access only.'], 403);
        }

        $schedules = ExaminationSchedule::query()->orderBy('id')->get();
        $updated = 0;
        $skipped = 0;

        foreach ($schedules as $schedule) {
            $before = $schedule->rooms()->count();
            $this->sessions->ensureDefaultRooms($schedule);
            $after = $schedule->rooms()->count();
            if ($before === 0 && $after > 0) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Default rooms applied to {$updated} schedule(s). {$skipped} already had rooms.",
            'data' => [
                'updated' => $updated,
                'skipped' => $skipped,
                'total' => $schedules->count(),
            ],
        ]);
    }

    private function ensureProctorUser(int $userId): void
    {
        $user = User::query()->with('role')->findOrFail($userId);
        if (! $user->isProctor() && ! $user->isAdmin()) {
            abort(422, 'Selected user must be a proctor or admin.');
        }
    }

    private function mapRoom(ExaminationRoom $room): array
    {
        return [
            'id' => $room->id,
            'examination_schedule_id' => $room->examination_schedule_id,
            'room_name' => $room->room_name,
            'capacity' => $room->capacity,
            'reschedule_capacity' => $room->reschedule_capacity,
            'proctor_id' => $room->proctor_id,
            'proctor' => $room->proctor ? [
                'id' => $room->proctor->id,
                'name' => $room->proctor->name,
                'email' => $room->proctor->email,
            ] : null,
        ];
    }
}
