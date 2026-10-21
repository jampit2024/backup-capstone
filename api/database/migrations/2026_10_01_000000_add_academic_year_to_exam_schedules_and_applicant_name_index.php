<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->string('academic_year', 9)->nullable()->after('exam_date');
            $table->index('academic_year', 'ix_exam_schedules_academic_year');
            $table->index(
                ['academic_year', 'exam_date', 'batch_code'],
                'ix_exam_schedules_year_date_batch'
            );
        });

        DB::table('exam_schedules')
            ->select(['id', 'exam_date'])
            ->whereNull('academic_year')
            ->orderBy('id')
            ->chunkById(500, function ($schedules): void {
                foreach ($schedules as $schedule) {
                    $date = Carbon::parse($schedule->exam_date);
                    $startYear = $date->month >= 8 ? $date->year : $date->year - 1;

                    DB::table('exam_schedules')
                        ->where('id', $schedule->id)
                        ->update(['academic_year' => $startYear.'-'.($startYear + 1)]);
                }
            });

        Schema::table('applicants', function (Blueprint $table) {
            $table->index('name', 'ix_applicants_name');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropIndex('ix_applicants_name');
        });

        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->dropIndex('ix_exam_schedules_year_date_batch');
            $table->dropIndex('ix_exam_schedules_academic_year');
            $table->dropColumn('academic_year');
        });
    }
};