<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => new RoleResource($this->whenLoaded('role')),
            'status' => $this->status?->value ?? $this->status,
            'theme' => $this->theme ?? 'system',
            'locale' => $this->locale ?? 'en',
            'profile_photo_url' => $this->resolveProfilePhotoUrl(),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'assigned_schedules' => $this->whenLoaded('examinationSchedules', fn () => $this->examinationSchedules->map(fn ($schedule) => [
                'id' => $schedule->id,
                'batch_code' => $schedule->batch_code,
                'date_label' => optional($schedule->exam_date)->format('M j, Y'),
                'time_slot' => $schedule->time_slot,
                'status' => $schedule->status?->value ?? $schedule->status,
            ])->values()),
            'assigned_rooms' => $this->whenLoaded('examinationRooms', fn () => $this->examinationRooms->map(fn ($room) => [
                'id' => $room->id,
                'room_name' => $room->room_name,
                'schedule_id' => $room->examination_schedule_id,
                'schedule' => $room->relationLoaded('schedule') && $room->schedule ? [
                    'batch_code' => $room->schedule->batch_code,
                    'date_label' => optional($room->schedule->exam_date)->format('M j, Y'),
                    'time_slot' => $room->schedule->time_slot,
                ] : null,
            ])->values()),
        ];
    }

    private function resolveProfilePhotoUrl(): ?string
    {
        if (! $this->profile_photo_path) {
            return null;
        }

        $url = Storage::disk('public')->url($this->profile_photo_path);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
