<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExaminationScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'exam_date' => optional($this->exam_date)?->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'time_slot' => $this->time_slot,
            'venue' => $this->venue,
            'batch_code' => $this->batch_code,
            'course' => $this->course,
            'status' => $this->status?->value ?? $this->status,
            'expected_examinees' => (int) $this->expected_examinees,
            'registrations_count' => $this->whenCounted('registrations'),
            'rooms_count' => (int) ($this->rooms_count ?? 0),
            'completed_rooms_count' => (int) ($this->completed_rooms_count ?? 0),
            'rooms' => $this->whenLoaded('rooms', fn () => $this->rooms->map(fn ($room) => [
                'id' => $room->id,
                'room_name' => $room->room_name,
                'capacity' => $room->capacity,
                'proctor_id' => $room->proctor_id,
                'proctor_name' => $room->proctor?->name,
            ])),
        ];
    }
}
