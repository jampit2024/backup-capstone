<?php

namespace App\Models;

use App\Enums\ApplicantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Applicant extends Model
{
    protected $fillable = [
        'applicant_code',
        'reference_number',
        'name',
        'email',
        'gmail',
        'gmail_submitted_at',
        'course_applied',
        'preferred_exam_time',
        'application_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicantStatus::class,
            'application_date' => 'date',
            'gmail_submitted_at' => 'datetime',
        ];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ExaminationRegistration::class);
    }

    public function latestRegistration(): HasOne
    {
        return $this->hasOne(ExaminationRegistration::class)->latestOfMany();
    }

    public function notificationEmail(): ?string
    {
        return $this->gmail ?: $this->email;
    }
}
