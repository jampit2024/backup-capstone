<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExaminationRoom extends Model
{
    protected $table = 'exam_rooms';

    protected $fillable = [
        'examination_schedule_id',
        'room_name',
        'capacity',
        'reschedule_capacity',
        'proctor_id',
        'room_id',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ExaminationSchedule::class, 'examination_schedule_id');
    }

    public function catalogRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id')->withTrashed();
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ExaminationRegistration::class, 'assigned_room_id');
    }

    public function proctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proctor_id');
    }

    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'examination_room_id');
    }
}
