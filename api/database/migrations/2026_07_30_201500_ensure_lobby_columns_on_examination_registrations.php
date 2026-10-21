<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_registrations', 'exam_session_id')) {
                $table->foreignId('exam_session_id')->nullable()->after('examination_schedule_id')
                    ->constrained('exam_sessions')->nullOnDelete();
            }
            if (! Schema::hasColumn('examination_registrations', 'participation_token')) {
                $table->uuid('participation_token')->nullable()->after('exam_session_id');
            }
            if (! Schema::hasColumn('examination_registrations', 'lobby_status')) {
                $table->string('lobby_status', 30)->nullable()->after('participation_token');
            }
            if (! Schema::hasColumn('examination_registrations', 'joined_lobby_at')) {
                $table->timestamp('joined_lobby_at')->nullable()->after('lobby_status');
            }
            if (! Schema::hasColumn('examination_registrations', 'violation_count')) {
                $table->unsignedSmallInteger('violation_count')->default(0)->after('joined_lobby_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            foreach (['violation_count', 'joined_lobby_at', 'lobby_status'] as $col) {
                if (Schema::hasColumn('examination_registrations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
