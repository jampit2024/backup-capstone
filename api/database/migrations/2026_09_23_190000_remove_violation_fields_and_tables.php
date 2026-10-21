<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drops examination_violations table and removes violation/tab-switch grace columns
     * because mandatory Android Screen Pinning eliminates cheating at the OS level.
     */
    public function up(): void
    {
        // 1. Drop examination_violations table if it exists
        if (Schema::hasTable('examination_violations')) {
            Schema::dropIfExists('examination_violations');
        }

        // 2. Drop violation_limit and tab_switch_grace_seconds from examination_settings
        Schema::table('examination_settings', function (Blueprint $table) {
            if (Schema::hasColumn('examination_settings', 'violation_limit')) {
                $table->dropColumn('violation_limit');
            }
            if (Schema::hasColumn('examination_settings', 'tab_switch_grace_seconds')) {
                $table->dropColumn('tab_switch_grace_seconds');
            }
        });

        // 3. Drop violation_count from examination_registrations
        Schema::table('examination_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('examination_registrations', 'violation_count')) {
                $table->dropColumn('violation_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_registrations', 'violation_count')) {
                $table->unsignedInteger('violation_count')->default(0)->after('lobby_status');
            }
        });

        Schema::table('examination_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_settings', 'violation_limit')) {
                $table->unsignedTinyInteger('violation_limit')->default(3)->after('duration_minutes');
            }
            if (! Schema::hasColumn('examination_settings', 'tab_switch_grace_seconds')) {
                $table->unsignedInteger('tab_switch_grace_seconds')->default(5)->after('disconnect_grace_seconds');
            }
        });

        if (! Schema::hasTable('examination_violations')) {
            Schema::create('examination_violations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('examination_registration_id')
                    ->constrained('examination_registrations')
                    ->cascadeOnDelete();
                $table->foreignId('exam_session_id')
                    ->nullable()
                    ->constrained('exam_sessions')
                    ->nullOnDelete();
                $table->string('type', 60);
                $table->string('message', 500)->nullable();
                $table->unsignedInteger('violation_count')->default(1);
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();

                $table->index(['exam_session_id', 'occurred_at']);
            });
        }
    }
};

