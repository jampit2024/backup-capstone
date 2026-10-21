<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_EXAM_CODE_INDEX = 'exam_sessions_active_exam_code_unique';

    private const GLOBAL_EXAM_CODE_INDEX = 'exam_sessions_exam_code_unique';

    private const ACTIVE_MARKER_COLUMN = 'active_lobby_marker';

    public function up(): void
    {
        if (! Schema::hasTable('exam_sessions') || ! Schema::hasColumn('exam_sessions', 'exam_code')) {
            return;
        }

        $this->assertActiveExamCodesAreUnique();

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            if ($this->hasIndex(self::GLOBAL_EXAM_CODE_INDEX)) {
                DB::statement('DROP INDEX '.self::GLOBAL_EXAM_CODE_INDEX);
            }

            if (! $this->hasIndex(self::ACTIVE_EXAM_CODE_INDEX)) {
                DB::statement("CREATE UNIQUE INDEX ".self::ACTIVE_EXAM_CODE_INDEX." ON exam_sessions (exam_code) WHERE status IN ('open', 'lobby_open')");
            }

            return;
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException("Active exam-code uniqueness is unsupported for database driver: {$driver}.");
        }

        if ($this->hasIndex(self::GLOBAL_EXAM_CODE_INDEX)) {
            DB::statement('ALTER TABLE exam_sessions DROP INDEX '.self::GLOBAL_EXAM_CODE_INDEX);
        }

        if (! Schema::hasColumn('exam_sessions', self::ACTIVE_MARKER_COLUMN)) {
            DB::statement("ALTER TABLE exam_sessions ADD COLUMN ".self::ACTIVE_MARKER_COLUMN." TINYINT GENERATED ALWAYS AS (IF(status IN ('open', 'lobby_open'), 1, NULL)) VIRTUAL");
        }

        if (! $this->hasIndex(self::ACTIVE_EXAM_CODE_INDEX)) {
            DB::statement('ALTER TABLE exam_sessions ADD UNIQUE KEY '.self::ACTIVE_EXAM_CODE_INDEX.' ('.self::ACTIVE_MARKER_COLUMN.', exam_code)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('exam_sessions') || ! Schema::hasColumn('exam_sessions', 'exam_code')) {
            return;
        }

        $this->assertAllExamCodesAreUnique();

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            if ($this->hasIndex(self::ACTIVE_EXAM_CODE_INDEX)) {
                DB::statement('DROP INDEX '.self::ACTIVE_EXAM_CODE_INDEX);
            }

            if (! $this->hasIndex(self::GLOBAL_EXAM_CODE_INDEX)) {
                DB::statement('CREATE UNIQUE INDEX '.self::GLOBAL_EXAM_CODE_INDEX.' ON exam_sessions (exam_code)');
            }

            return;
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException("Active exam-code uniqueness is unsupported for database driver: {$driver}.");
        }

        if ($this->hasIndex(self::ACTIVE_EXAM_CODE_INDEX)) {
            DB::statement('ALTER TABLE exam_sessions DROP INDEX '.self::ACTIVE_EXAM_CODE_INDEX);
        }

        if (! $this->hasIndex(self::GLOBAL_EXAM_CODE_INDEX)) {
            DB::statement('ALTER TABLE exam_sessions ADD UNIQUE KEY '.self::GLOBAL_EXAM_CODE_INDEX.' (exam_code)');
        }
    }

    private function assertActiveExamCodesAreUnique(): void
    {
        $duplicates = DB::table('exam_sessions')
            ->selectRaw('UPPER(exam_code) AS normalized_exam_code')
            ->whereIn('status', ['open', 'lobby_open'])
            ->whereNotNull('exam_code')
            ->groupByRaw('UPPER(exam_code)')
            ->havingRaw('COUNT(*) > 1');

        $duplicateGroupCount = DB::query()
            ->fromSub($duplicates, 'duplicate_exam_codes')
            ->count();

        if ($duplicateGroupCount > 0) {
            throw new RuntimeException(
                "Cannot enforce active exam_code uniqueness: {$duplicateGroupCount} duplicate active code group(s) found."
            );
        }
    }

    private function assertAllExamCodesAreUnique(): void
    {
        $duplicates = DB::table('exam_sessions')
            ->selectRaw('UPPER(exam_code) AS normalized_exam_code')
            ->whereNotNull('exam_code')
            ->groupByRaw('UPPER(exam_code)')
            ->havingRaw('COUNT(*) > 1');

        $duplicateGroupCount = DB::query()
            ->fromSub($duplicates, 'duplicate_exam_codes')
            ->count();

        if ($duplicateGroupCount > 0) {
            throw new RuntimeException(
                "Cannot restore global exam_code uniqueness: {$duplicateGroupCount} duplicate code group(s) found."
            );
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('exam_sessions'))
            ->contains(fn (array $index) => ($index['name'] ?? '') === $name);
    }
};
