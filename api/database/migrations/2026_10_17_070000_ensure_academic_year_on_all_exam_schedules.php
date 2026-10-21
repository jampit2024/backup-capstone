<?php

use App\Support\AcademicYear;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_schedules') || ! Schema::hasColumn('exam_schedules', 'academic_year')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("UPDATE exam_schedules SET academic_year = CASE WHEN exam_date IS NOT NULL AND CAST(strftime('%m', exam_date) AS INTEGER) >= 8 THEN strftime('%Y', exam_date) || '-' || (CAST(strftime('%Y', exam_date) AS INTEGER) + 1) WHEN exam_date IS NOT NULL THEN (CAST(strftime('%Y', exam_date) AS INTEGER) - 1) || '-' || strftime('%Y', exam_date) ELSE '" . AcademicYear::current() . "' END WHERE academic_year IS NULL OR academic_year = ''");
        } else {
            DB::statement("UPDATE exam_schedules SET academic_year = CASE WHEN exam_date IS NOT NULL AND MONTH(exam_date) >= 8 THEN CONCAT(YEAR(exam_date), '-', YEAR(exam_date) + 1) WHEN exam_date IS NOT NULL THEN CONCAT(YEAR(exam_date) - 1, '-', YEAR(exam_date)) ELSE '" . AcademicYear::current() . "' END WHERE academic_year IS NULL OR academic_year = ''");
        }
    }

    public function down(): void
    {
        // No-op: backfilled academic years do not need to be cleared.
    }
};

