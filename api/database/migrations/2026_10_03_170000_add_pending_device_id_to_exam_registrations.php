<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_registrations') && ! Schema::hasColumn('exam_registrations', 'pending_device_id')) {
            Schema::table('exam_registrations', function (Blueprint $table) {
                $table->string('pending_device_id', 255)->nullable()->after('participation_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('exam_registrations') && Schema::hasColumn('exam_registrations', 'pending_device_id')) {
            Schema::table('exam_registrations', function (Blueprint $table) {
                $table->dropColumn('pending_device_id');
            });
        }
    }
};
