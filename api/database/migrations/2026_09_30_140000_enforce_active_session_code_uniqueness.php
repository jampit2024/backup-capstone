<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $this->assertActiveValuesAreUnique('session_code');
        $this->assertActiveValuesAreUnique('qr_token');

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS exam_sessions_open_code_unique');
            DB::statement('DROP INDEX IF EXISTS exam_sessions_open_token_unique');
            DB::statement('DROP INDEX IF EXISTS exam_sessions_active_code_unique');
            DB::statement('DROP INDEX IF EXISTS exam_sessions_active_qr_unique');
            DB::statement("CREATE UNIQUE INDEX exam_sessions_active_code_unique ON exam_sessions (session_code) WHERE status IN ('open', 'lobby_open')");
            DB::statement("CREATE UNIQUE INDEX exam_sessions_active_qr_unique ON exam_sessions (qr_token) WHERE status IN ('open', 'lobby_open')");

            return;
        }

        if ($driver !== 'mysql') {
            return;
        }

        $indexes = collect(Schema::getIndexes('exam_sessions'))->pluck('name');
        foreach (['exam_sessions_open_code_unique', 'exam_sessions_open_token_unique', 'exam_sessions_active_code_unique', 'exam_sessions_active_qr_unique'] as $index) {
            if ($indexes->contains($index)) {
                DB::statement("ALTER TABLE exam_sessions DROP INDEX {$index}");
            }
        }

        foreach (['open_session_code', 'open_qr_token', 'active_lobby_marker'] as $column) {
            if (Schema::hasColumn('exam_sessions', $column)) {
                DB::statement("ALTER TABLE exam_sessions DROP COLUMN {$column}");
            }
        }

        DB::statement("ALTER TABLE exam_sessions ADD COLUMN active_lobby_marker TINYINT GENERATED ALWAYS AS (IF(status IN ('open', 'lobby_open'), 1, NULL)) VIRTUAL");
        DB::statement('ALTER TABLE exam_sessions ADD UNIQUE KEY exam_sessions_active_code_unique (active_lobby_marker, session_code)');
        DB::statement('ALTER TABLE exam_sessions ADD UNIQUE KEY exam_sessions_active_qr_unique (active_lobby_marker, qr_token)');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS exam_sessions_active_code_unique');
            DB::statement('DROP INDEX IF EXISTS exam_sessions_active_qr_unique');
            DB::statement("CREATE UNIQUE INDEX exam_sessions_open_code_unique ON exam_sessions (session_code) WHERE status = 'open'");
            DB::statement("CREATE UNIQUE INDEX exam_sessions_open_token_unique ON exam_sessions (qr_token) WHERE status = 'open'");

            return;
        }

        if ($driver !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE exam_sessions DROP INDEX exam_sessions_active_code_unique');
        DB::statement('ALTER TABLE exam_sessions DROP INDEX exam_sessions_active_qr_unique');
        DB::statement('ALTER TABLE exam_sessions DROP COLUMN active_lobby_marker');
        DB::statement("ALTER TABLE exam_sessions ADD COLUMN open_session_code VARCHAR(20) GENERATED ALWAYS AS (IF(status = 'open', session_code, NULL)) VIRTUAL");
        DB::statement('ALTER TABLE exam_sessions ADD UNIQUE KEY exam_sessions_open_code_unique (open_session_code)');
        DB::statement("ALTER TABLE exam_sessions ADD COLUMN open_qr_token VARCHAR(36) GENERATED ALWAYS AS (IF(status = 'open', qr_token, NULL)) VIRTUAL");
        DB::statement('ALTER TABLE exam_sessions ADD UNIQUE KEY exam_sessions_open_token_unique (open_qr_token)');
    }

    private function assertActiveValuesAreUnique(string $column): void
    {
        $duplicate = DB::table('exam_sessions')
            ->select($column)
            ->whereIn('status', ['open', 'lobby_open'])
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->value($column);

        if ($duplicate !== null) {
            throw new RuntimeException("Cannot enforce active exam session uniqueness: duplicate {$column} found.");
        }
    }
};