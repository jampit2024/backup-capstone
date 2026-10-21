<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_settings', 'violation_limit')) {
                $table->unsignedTinyInteger('violation_limit')->default(3)->after('duration_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('examination_settings', function (Blueprint $table) {
            if (Schema::hasColumn('examination_settings', 'violation_limit')) {
                $table->dropColumn('violation_limit');
            }
        });
    }
};
