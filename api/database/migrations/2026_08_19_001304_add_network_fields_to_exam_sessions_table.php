<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_sessions', 'wifi_ssid')) {
                $table->string('wifi_ssid')->nullable()->after('status');
            }
            if (!Schema::hasColumn('exam_sessions', 'local_server_ip')) {
                $table->string('local_server_ip')->nullable()->after('wifi_ssid');
            }
            if (!Schema::hasColumn('exam_sessions', 'sync_mode')) {
                $table->string('sync_mode', 20)->default('online')->after('local_server_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropColumn(['wifi_ssid', 'local_server_ip', 'sync_mode']);
        });
    }
};
