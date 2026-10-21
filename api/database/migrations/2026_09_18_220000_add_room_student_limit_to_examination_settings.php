<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_settings', 'room_student_limit')) {
                $table->unsignedSmallInteger('room_student_limit')->default(60)->after('violation_limit');
            }
        });

        // Align default exam length to 60 minutes for new installs / empty rows.
        if (Schema::hasTable('examination_settings')) {
            DB::table('examination_settings')
                ->where('duration_minutes', 90)
                ->update(['duration_minutes' => 60]);
        }
    }

    public function down(): void
    {
        Schema::table('examination_settings', function (Blueprint $table) {
            if (Schema::hasColumn('examination_settings', 'room_student_limit')) {
                $table->dropColumn('room_student_limit');
            }
        });
    }
};
