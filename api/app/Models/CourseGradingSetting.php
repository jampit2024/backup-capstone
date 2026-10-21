<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseGradingSetting extends Model
{
    protected $fillable = [
        'course_code',
        'course_name',
        'passing_percentage',
        'failing_grade_point',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'passing_percentage' => 'float',
            'failing_grade_point' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
