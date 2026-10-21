<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_registrations', 'sync_status')) {
                $table->string('sync_status', 20)->default('pending')->after('items_total');
            }
            if (! Schema::hasColumn('examination_registrations', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('sync_status');
            }
            if (! Schema::hasColumn('examination_registrations', 'sync_error')) {
                $table->text('sync_error')->nullable()->after('synced_at');
            }
        });

        Schema::table('exam_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_sessions', 'sync_status')) {
                $table->string('sync_status', 20)->default('pending')->after('ended_at');
            }
            if (! Schema::hasColumn('exam_sessions', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('sync_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            foreach (['sync_status', 'synced_at', 'sync_error'] as $col) {
                if (Schema::hasColumn('examination_registrations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('exam_sessions', function (Blueprint $table) {
            foreach (['sync_status', 'synced_at'] as $col) {
                if (Schema::hasColumn('exam_sessions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
