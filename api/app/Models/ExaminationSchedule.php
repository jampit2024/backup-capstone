<?php

namespace App\Models;

use App\Support\AcademicYear;
use App\Enums\ExaminationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExaminationSchedule extends Model
{
    protected $table = 'exam_schedules';

    protected $fillable = [
        'title',
        'exam_date',
        'academic_year',
        'start_time',
        'end_time',
        'time_slot',
        'venue',
        'batch_code',
        'course',
        'proctor_id',
        'status',
        'expected_examinees',
        'email_instructions',
        'include_app_download',
        'app_download_name',
        'app_download_url',
        'app_download_instructions',
        'rooms_managed',
        'rooms_published_at',
        'rooms_revision',
    ];

    public function scopeInCurrentCalendarYear(Builder $query): Builder
    {
        return $query->whereYear('exam_date', AcademicYear::currentCalendarYear());
    }

    public function scopeInCurrentAcademicYear(Builder $query): Builder
    {
        return $query->where('academic_year', AcademicYear::current());
    }

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'status' => ExaminationStatus::class,
            'expected_examinees' => 'integer',
            'include_app_download' => 'boolean',
            'rooms_managed' => 'boolean',
            'rooms_published_at' => 'datetime',
        ];
    }

    /**
     * Default instructions if none configured for this schedule.
     *
     * @return list<string>
     */
    public static function defaultInstructions(): array
    {
        return [
            'Arrive at least 15 to 30 minutes before your scheduled examination time.',
            'Bring your examination passkey and valid photo identification.',
            'Follow all room assignments and directives given by the assigned proctors.',
            'Keep your examination passkey strictly confidential — it is valid only for your schedule.',
            'Install the official MasterExam mobile application if taking the exam on a mobile device.',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ExaminationSchedule $schedule): void {
            if ($schedule->exam_date && (! $schedule->academic_year || $schedule->isDirty('exam_date'))) {
                $schedule->academic_year = AcademicYear::forDate($schedule->exam_date);
            }
        });
    }

    public function proctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proctor_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ExaminationRoom::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ExaminationRegistration::class);
    }
}
