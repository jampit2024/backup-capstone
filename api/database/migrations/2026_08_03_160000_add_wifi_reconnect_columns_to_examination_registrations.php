<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_registrations', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('joined_lobby_at');
            }
            if (! Schema::hasColumn('examination_registrations', 'reconnect_allowed')) {
                $table->boolean('reconnect_allowed')->default(false)->after('last_heartbeat_at');
            }
            if (! Schema::hasColumn('examination_registrations', 'reconnect_code')) {
                $table->string('reconnect_code', 12)->nullable()->after('reconnect_allowed');
            }
            if (! Schema::hasColumn('examination_registrations', 'reconnect_code_expires_at')) {
                $table->timestamp('reconnect_code_expires_at')->nullable()->after('reconnect_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            foreach (['reconnect_code_expires_at', 'reconnect_code', 'reconnect_allowed', 'last_heartbeat_at'] as $col) {
                if (Schema::hasColumn('examination_registrations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
