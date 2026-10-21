<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_grading_settings', function (Blueprint $table) {
            $table->id();
            $table->string('course_code', 100)->unique();
            $table->string('course_name', 150);
            /** Minimum percentage (0–100) required to pass. */
            $table->decimal('passing_percentage', 5, 2)->default(75);
            /**
             * Highest failing grade point on the 5-point scale (1.0 best → 5.0 fail).
             * Scores that convert to this value or worse are failing when percentage
             * is also below passing_percentage.
             */
            $table->decimal('failing_grade_point', 3, 2)->default(5.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('examination_registrations', function (Blueprint $table) {
            $table->decimal('grade_point', 3, 2)->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            $table->dropColumn('grade_point');
        });

        Schema::dropIfExists('course_grading_settings');
    }
};
