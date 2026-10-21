<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExaminationResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $applicant = $this->applicant;
        $schedule = $this->schedule;
        $room = $this->examSession?->room;

        return [
            'id' => $this->id,
            'applicant_id' => $this->applicant_id,
            'applicant_code' => $applicant?->applicant_code,
            'name' => $applicant?->name,
            'desired_program' => $applicant?->course_applied,
            'gmail' => $applicant?->gmail,
            'examination_date' => optional($schedule?->exam_date)?->toDateString(),
            'examination_time' => $schedule?->time_slot,
            'batch_code' => $schedule?->batch_code,
            'room_id' => $room?->id,
            'room_name' => $room?->room_name,
            'exam_session_id' => $this->exam_session_id,
            'score' => $this->score,
            'examination_method' => $this->examination_method ?: 'online',
            'manual_score' => $this->manual_score !== null ? (float) $this->manual_score : null,
            'manual_max_score' => $this->manual_max_score !== null ? (float) $this->manual_max_score : null,
            'manual_remarks' => $this->manual_remarks,
            'manual_recorded_at' => optional($this->manual_recorded_at)?->toISOString(),
            'items_correct' => $this->items_correct !== null ? (int) $this->items_correct : null,
            'items_total' => $this->items_total !== null ? (int) $this->items_total : null,
            'grade_point' => $this->grade_point !== null
                ? (float) $this->grade_point
                : null,
            'display_score' => app(\App\Services\GradingService::class)->formatRegistrationScore($this->resource),
            'status' => $this->result_status?->value ?? $this->result_status,
            'attendance_status' => $this->attendance_status?->value ?? $this->attendance_status,
            'email_status' => $this->email_status?->value ?? $this->email_status,
            'email_sent' => (bool) ($this->email_sent
                || ($this->email_status?->value ?? $this->email_status) === 'sent'
                || $this->email_sent_at),
            'email_sent_at' => optional($this->email_sent_at)?->toISOString(),
            'email_attempts' => (int) $this->email_attempts,
            'email_error_message' => $this->email_error_message,
        ];
    }
}
