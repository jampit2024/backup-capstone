<?php

namespace App\Http\Controllers\Examination;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\RoomAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomCatalogController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Room::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        // Names remain reserved after soft deletion so history stays unambiguous.
        return response()->json(['data' => Room::create($data)], 201);
    }

    public function update(Request $request, Room $room)
    {
        $data = $this->validated($request, $room);
        return DB::transaction(function () use ($room, $data) {
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $uses = $room->examinationRooms()->with('schedule')->orderBy('examination_schedule_id')->get();
            foreach ($uses as $use) {
                $schedule = $use->schedule()->lockForUpdate()->first();
                if (in_array($schedule->status->value, ['completed', 'cancelled'])) {
                    continue;
                }
                app(RoomAssignmentService::class)->assertEditable($schedule);
                abort_if(! $data['is_active'], 422, 'Remove this room from upcoming schedules before deactivating it.');
                $use->update(['room_name' => $data['name'], 'capacity' => $data['capacity'], 'reschedule_capacity' => $data['reschedule_capacity']]);
                app(RoomAssignmentService::class)->distribute($schedule);
                if ($schedule->rooms_published_at && $data['name'] !== $room->name) {
                    $use->registrations()->pluck('id')->each(fn ($id) =>
                        \App\Jobs\SendRoomAssignmentNotification::dispatch($id)->afterCommit());
                }
            }
            $room->update($data);
            return response()->json(['data' => $room]);
        }, 3);
    }

    public function destroy(Room $room)
    {
        return DB::transaction(function () use ($room) {
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            abort_if($room->examinationRooms()->whereHas('schedule',
                fn ($q) => $q->whereIn('status', ['scheduled', 'ongoing']))->exists(),
                422, 'Remove this room from upcoming schedules and finish active examinations before deleting it.');
            $room->delete();
            return response()->json(['message' => 'Room deleted. Historical assignments have been preserved.']);
        });
    }

    private function validated(Request $request, ?Room $room = null): array
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $request->input('name')));
        $request->merge(['name' => $name, 'name_key' => mb_strtolower($name)]);
        return collect($request->validate([
            'name' => ['required', 'string', 'max:80'],
            'name_key' => ['required', Rule::unique('rooms', 'name_key')->ignore($room?->id)],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'reschedule_capacity' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['required', 'boolean'],
        ]))->except('name_key')->all();
    }
}
