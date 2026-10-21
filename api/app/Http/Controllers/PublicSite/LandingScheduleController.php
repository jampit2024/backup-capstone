<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;

use App\Models\ExaminationRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LandingScheduleController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) (
            $request->query('q')
            ?? $request->query('name')
            ?? $request->query('applicant_id')
            ?? $request->query('reference_number')
            ?? ''
        ));

        $type = strtolower(trim((string) $request->query('type', 'auto')));

        if (mb_strlen($q) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Enter at least 2 characters (name, applicant ID, or reference number).',
                'data' => [],
            ], 422);
        }

        $registrations = ExaminationRegistration::query()
            ->with([
                'applicant:id,applicant_code,reference_number,name,email,course_applied',
                'schedule.rooms',
            ])
            ->whereHas('applicant', function ($query) use ($q, $type) {
                match ($type) {
                    'name' => $query->where('name', 'like', "%{$q}%"),
                    'applicant_id' => $query->where('applicant_code', 'like', "%{$q}%"),
                    'reference' => $query->where('reference_number', 'like', "%{$q}%"),
                    default => $query->where(function ($inner) use ($q) {
                        $inner->where('name', 'like', "%{$q}%")
                            ->orWhere('applicant_code', 'like', "%{$q}%")
                            ->orWhere('reference_number', 'like', "%{$q}%");
                    }),
                };
            })
            ->limit(30)
            ->get()
            ->map(function (ExaminationRegistration $registration) {
                $schedule = $registration->schedule;
                $applicant = $registration->applicant;
                $rooms = $schedule?->rooms?->pluck('room_name')->filter()->values()->all() ?? [];

                return [
                    'applicant_code' => $applicant?->applicant_code,
                    'reference_number' => $applicant?->reference_number,
                    'applicant_name' => $applicant?->name,
                    'course_preference' => $applicant?->course_applied,
                    'exam_date' => optional($schedule?->exam_date)->toDateString(),
                    'date_label' => optional($schedule?->exam_date)->format('M j, Y'),
                    'time_slot' => $schedule?->time_slot,
                    'batch_code' => $schedule?->batch_code,
                    'rooms' => $rooms,
                    'rooms_label' => implode(', ', $rooms) ?: 'Any open classroom',
                    'status' => $schedule?->status?->value ?? $schedule?->status,
                    'attendance_status' => $registration->attendance_status?->value ?? $registration->attendance_status,
                ];
            })
            ->sortBy(fn ($row) => sprintf(
                '%s|%s|%s',
                $row['exam_date'] ?? '',
                $row['time_slot'] ?? '',
                mb_strtolower($row['applicant_name'] ?? '')
            ))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $registrations,
            'meta' => [
                'query' => $q,
                'type' => $type,
                'count' => $registrations->count(),
                'searched_at' => Carbon::now()->toIso8601String(),
            ],
        ]);
    }
}
