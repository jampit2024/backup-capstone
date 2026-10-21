<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\ExaminationStatus;
use App\Enums\ResultStatus;
use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;

class DashboardService
{
    public function getOverview(): array
    {
        $today = Carbon::today()->toDateString();

        return [
            'stats' => $this->stats($today),
            'upcoming_schedules' => $this->upcomingSchedules($today),
            'recent_activities' => $this->recentActivities(),
            'performance' => $this->performanceSeries(),
            'account_summary' => $this->accountSummary(),
        ];
    }

    private function stats(string $today): array
    {
        $todaySchedules = ExaminationSchedule::withCount('rooms')
            ->whereDate('exam_date', $today)
            ->get();

        $examineesToday = (int) $todaySchedules->sum('expected_examinees');
        $roomsToday = (int) $todaySchedules->sum('rooms_count');
        $proctorsToday = ExaminationSchedule::whereDate('exam_date', $today)
            ->with('rooms')
            ->get()
            ->flatMap(fn (ExaminationSchedule $s) => $s->rooms->pluck('proctor_id'))
            ->filter()
            ->unique()
            ->count();

        return [
            'total_examinees' => Applicant::count(),
            'total_applicants' => Applicant::count(),
            'examinees_today' => $examineesToday,
            'scheduled_examinees' => ExaminationRegistration::whereHas('schedule', function ($q) use ($today) {
                $q->whereDate('exam_date', '>=', $today)
                    ->whereIn('status', [ExaminationStatus::Scheduled, ExaminationStatus::Ongoing]);
            })->count(),
            'active_sessions' => ExaminationSchedule::whereIn('status', [
                ExaminationStatus::Scheduled,
                ExaminationStatus::Ongoing,
            ])->whereDate('exam_date', '>=', $today)->count(),
            'rooms_today' => $roomsToday,
            'proctors_on_duty' => $proctorsToday,
            'completed_exams' => ExaminationSchedule::where('status', ExaminationStatus::Completed)->count(),
            'completed_examinations' => ExaminationRegistration::where('attendance_status', AttendanceStatus::Present)->count(),
            'pending_registrations' => Applicant::where('status', 'registered')->count(),
            'total_passed' => ExaminationRegistration::where('result_status', ResultStatus::Passed)->count(),
            'passed_applicants' => ExaminationRegistration::where('result_status', ResultStatus::Passed)->count(),
            'total_failed' => ExaminationRegistration::where('result_status', ResultStatus::Failed)->count(),
            'failed_applicants' => ExaminationRegistration::where('result_status', ResultStatus::Failed)->count(),
            'total_present' => ExaminationRegistration::where('attendance_status', AttendanceStatus::Present)->count(),
            'total_absent' => ExaminationRegistration::where('attendance_status', AttendanceStatus::Absent)->count(),
            'results_sent' => ExaminationRegistration::where('email_status', 'sent')->count(),
            'pending_emails' => ExaminationRegistration::whereIn('email_status', ['pending', 'queued'])->count(),
            'failed_emails' => ExaminationRegistration::where('email_status', 'failed')->count(),
        ];
    }

    private function upcomingSchedules(string $today): array
    {
        return ExaminationSchedule::with(['rooms.proctor:id,name'])
            ->withCount(['rooms', 'registrations'])
            ->whereDate('exam_date', '>=', $today)
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->limit(20)
            ->get()
            ->map(fn (ExaminationSchedule $schedule) => $this->mapSchedule($schedule))
            ->all();
    }

    private function recentActivities(): array
    {
        return ActivityLog::with('user:id,name')
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'title' => $this->activityTitle($log->action),
                'description' => $log->description,
                'user_name' => $log->user?->name,
                'created_at' => $log->created_at?->toISOString(),
                'date_label' => $log->created_at?->timezone(config('app.timezone'))->format('M j, Y'),
                'time_label' => $log->created_at?->timezone(config('app.timezone'))->format('h:i A'),
            ])
            ->all();
    }

    private function performanceSeries(): array
    {
        $points = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $points[] = [
                'label' => $date->format('M j'),
                'value' => (int) ExaminationSchedule::whereDate('exam_date', $date->toDateString())
                    ->sum('expected_examinees'),
            ];
        }

        $values = array_column($points, 'value');
        $average = count($values) ? (int) round(array_sum($values) / count($values)) : 0;

        return [
            'points' => $points,
            'average' => $average,
            'max' => max(array_merge($values, [500])),
        ];
    }

    private function accountSummary(): array
    {
        return [
            'admins' => User::whereHas('role', fn ($q) => $q->where('slug', Role::SLUG_ADMIN))->count(),
            'proctors' => User::whereHas('role', fn ($q) => $q->where('slug', Role::SLUG_PROCTOR))->count(),
            'active_users' => User::where('status', 'active')->count(),
        ];
    }

    private function mapSchedule(ExaminationSchedule $schedule): array
    {
        $rooms = $schedule->rooms ?? collect();
        $proctorNames = $rooms->map(fn ($room) => $room->proctor?->name)
            ->filter()
            ->unique()
            ->values();

        $dateLabel = optional($schedule->exam_date)->format('M j, Y');
        $timeSlot = $schedule->time_slot
            ?: trim(($this->formatTime($schedule->start_time) ?? '').'–'.($this->formatTime($schedule->end_time) ?? ''), '–');

        return [
            'id' => $schedule->id,
            'title' => $schedule->title ?: 'Entrance Examination',
            'exam_date' => optional($schedule->exam_date)->toDateString(),
            'date_label' => $dateLabel,
            'batch_code' => $schedule->batch_code,
            'batch_label' => trim(($dateLabel ?: '').', '.($schedule->batch_code ?: '')),
            'start_time' => $this->formatTime($schedule->start_time),
            'end_time' => $this->formatTime($schedule->end_time),
            'time_slot' => $timeSlot,
            'course' => 'General Entrance Examination',
            'status' => $schedule->status?->value ?? $schedule->status,
            'expected_examinees' => (int) $schedule->expected_examinees,
            'registered_count' => (int) ($schedule->registrations_count ?? 0),
            'room_count' => (int) ($schedule->rooms_count ?? $rooms->count()),
            'rooms_label' => $rooms->pluck('room_name')->filter()->implode(', '),
            'proctor_count' => $proctorNames->count(),
            'proctor_names' => $proctorNames->all(),
            'proctor_name' => $proctorNames->first(),
        ];
    }

    private function activityTitle(string $action): string
    {
        return match ($action) {
            'batch_result_sent' => 'Batch Result Sent',
            'applicant_registered' => 'Applicants registered',
            'schedule_updated' => 'Schedule updated',
            'schedule_created' => 'Schedule created',
            'exam_started' => 'Examination started',
            'result_recorded' => 'Results recorded',
            'proctor_assigned' => 'Proctors assigned',
            'user_login' => 'Administrator login',
            default => str_replace('_', ' ', ucfirst($action)),
        };
    }

    private function formatTime(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        try {
            return Carbon::parse($time)->format('H:i');
        } catch (\Throwable) {
            return (string) $time;
        }
    }
}
