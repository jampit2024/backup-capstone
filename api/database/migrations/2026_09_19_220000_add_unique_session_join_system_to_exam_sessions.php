<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_sessions', 'session_code')) {
                $table->string('session_code', 10)->nullable()->after('proctor_id');
            }
            if (! Schema::hasColumn('exam_sessions', 'qr_token')) {
                $table->uuid('qr_token')->nullable()->after('session_code');
            }
            if (! Schema::hasColumn('exam_sessions', 'proctor_local_ip')) {
                $table->string('proctor_local_ip', 45)->nullable()->after('qr_token');
            }
            if (! Schema::hasColumn('exam_sessions', 'proctor_local_port')) {
                $table->unsignedInteger('proctor_local_port')->default(9777)->after('proctor_local_ip');
            }
            if (! Schema::hasColumn('exam_sessions', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('ended_at');
            }
        });

        // Make examination_schedule_id nullable so ad-hoc proctor sessions can open freely
        try {
            Schema::table('exam_sessions', function (Blueprint $table) {
                $table->unsignedBigInteger('examination_schedule_id')->nullable()->change();
            });
        } catch (\Throwable $e) {
            // In case driver does not support modifying column without recreating
        }

        // Student joins tracking table to prevent multiple device joins with same student credentials
        if (! Schema::hasTable('exam_session_joins')) {
            Schema::create('exam_session_joins', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
                $table->string('student_id', 100);
                $table->string('device_id', 255)->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['exam_session_id', 'student_id']);
                $table->index(['exam_session_id', 'joined_at']);
            });
        }

        // Add scoped unique indexes for status = 'open'
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS exam_sessions_open_code_unique ON exam_sessions (session_code) WHERE status = 'open'");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS exam_sessions_open_token_unique ON exam_sessions (qr_token) WHERE status = 'open'");
        } elseif ($driver === 'mysql') {
            try {
                DB::statement("ALTER TABLE exam_sessions ADD COLUMN open_session_code VARCHAR(20) GENERATED ALWAYS AS (IF(status = 'open', session_code, NULL)) VIRTUAL");
                DB::statement("ALTER TABLE exam_sessions ADD UNIQUE KEY exam_sessions_open_code_unique (open_session_code)");
            } catch (\Throwable) {
                // Column or index may already exist
            }

            try {
                DB::statement("ALTER TABLE exam_sessions ADD COLUMN open_qr_token VARCHAR(36) GENERATED ALWAYS AS (IF(status = 'open', qr_token, NULL)) VIRTUAL");
                DB::statement("ALTER TABLE exam_sessions ADD UNIQUE KEY exam_sessions_open_token_unique (open_qr_token)");
            } catch (\Throwable) {
                // Column or index may already exist
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS exam_sessions_open_code_unique");
            DB::statement("DROP INDEX IF EXISTS exam_sessions_open_token_unique");
        } elseif ($driver === 'mysql') {
            try {
                DB::statement("ALTER TABLE exam_sessions DROP INDEX exam_sessions_open_code_unique");
                DB::statement("ALTER TABLE exam_sessions DROP COLUMN open_session_code");
            } catch (\Throwable) {}

            try {
                DB::statement("ALTER TABLE exam_sessions DROP INDEX exam_sessions_open_token_unique");
                DB::statement("ALTER TABLE exam_sessions DROP COLUMN open_qr_token");
            } catch (\Throwable) {}
        }

        Schema::dropIfExists('exam_session_joins');

        Schema::table('exam_sessions', function (Blueprint $table) {
            $cols = ['session_code', 'qr_token', 'proctor_local_ip', 'proctor_local_port', 'expires_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('exam_sessions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
