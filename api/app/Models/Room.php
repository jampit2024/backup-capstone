<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'capacity', 'reschedule_capacity', 'is_active'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'reschedule_capacity' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Room $room) {
            $room->name = preg_replace('/\s+/u', ' ', trim($room->name));
            $room->name_key = mb_strtolower($room->name);
        });
    }

    public function examinationRooms()
    {
        return $this->hasMany(ExaminationRoom::class);
    }
}
