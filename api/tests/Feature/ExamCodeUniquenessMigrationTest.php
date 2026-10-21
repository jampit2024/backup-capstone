<?php

namespace Tests\Feature;

use App\Enums\ExamSessionStatus;
use App\Models\ExamSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExamCodeUniquenessMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_migration_up_is_idempotent_for_an_existing_database(): void
    {
        DB::statement('DROP INDEX IF EXISTS exam_sessions_active_exam_code_unique');
        DB::statement('DROP INDEX IF EXISTS exam_sessions_exam_code_unique');
        DB::statement('CREATE UNIQUE INDEX exam_sessions_exam_code_unique ON exam_sessions (exam_code)');

        $migration = require database_path('migrations/2026_10_03_160000_enforce_active_exam_code_uniqueness.php');
        $migration->up();
        $migration->up();

        $indexes = collect(Schema::getIndexes('exam_sessions'))->keyBy('name');
        $this->assertTrue($indexes->has('exam_sessions_active_exam_code_unique'));
        $this->assertFalse($indexes->has('exam_sessions_exam_code_unique'));
    }

    public function test_migration_down_restores_the_original_global_exam_code_unique_index(): void
    {
        $migration = require database_path('migrations/2026_10_03_160000_enforce_active_exam_code_uniqueness.php');
        $migration->down();

        $indexes = collect(Schema::getIndexes('exam_sessions'))->keyBy('name');
        $this->assertFalse($indexes->has('exam_sessions_active_exam_code_unique'));
        $this->assertTrue($indexes->has('exam_sessions_exam_code_unique'));
    }

    public function test_migration_reports_only_duplicate_active_code_group_count(): void
    {
        DB::statement('DROP INDEX IF EXISTS exam_sessions_active_exam_code_unique');
        DB::statement('DROP INDEX IF EXISTS exam_sessions_exam_code_unique');

        $proctor = $this->createProctor();
        foreach (['DUPCODE1', 'DUPCODE2'] as $sessionCode) {
            ExamSession::query()->create([
                'proctor_id' => $proctor->id,
                'exam_code' => 'DUPCODE1',
                'session_code' => $sessionCode,
                'qr_token' => (string) \Illuminate\Support\Str::uuid(),
                'status' => ExamSessionStatus::LobbyOpen,
            ]);
        }

        $migration = require database_path('migrations/2026_10_03_160000_enforce_active_exam_code_uniqueness.php');

        try {
            $migration->up();
            $this->fail('Expected the migration to reject duplicate active exam codes.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Cannot enforce active exam_code uniqueness: 1 duplicate active code group(s) found.',
                $e->getMessage()
            );
            $this->assertStringNotContainsString('DUPCODE1', $e->getMessage());
        }
    }

    public function test_active_exam_code_migration_enforces_active_only_uniqueness(): void
    {
        DB::statement('DROP INDEX IF EXISTS exam_sessions_active_exam_code_unique');
        DB::statement('DROP INDEX IF EXISTS exam_sessions_exam_code_unique');
        DB::statement('CREATE UNIQUE INDEX exam_sessions_exam_code_unique ON exam_sessions (exam_code)');

        $migration = require database_path('migrations/2026_10_03_160000_enforce_active_exam_code_uniqueness.php');
        $migration->up();

        $proctor = $this->createProctor();
        ExamSession::query()->create([
            'proctor_id' => $proctor->id,
            'exam_code' => 'ACTIVE01',
            'session_code' => 'CLOSED1',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ExamSessionStatus::Closed,
        ]);
        ExamSession::query()->create([
            'proctor_id' => $proctor->id,
            'exam_code' => 'ACTIVE01',
            'session_code' => 'OPEN001',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ExamSessionStatus::LobbyOpen,
        ]);

        try {
            ExamSession::query()->create([
                'proctor_id' => $proctor->id,
                'exam_code' => 'ACTIVE01',
                'session_code' => 'OPEN002',
                'qr_token' => (string) \Illuminate\Support\Str::uuid(),
                'status' => ExamSessionStatus::Open,
            ]);
            $this->fail('Expected the active exam-code index to reject a duplicate.');
        } catch (QueryException) {
            $this->assertDatabaseCount('exam_sessions', 2);
        }
    }

    private function createProctor(): User
    {
        $role = Role::where('slug', 'proctor')->first();

        return User::query()->create([
            'role_id' => $role?->id ?? 2,
            'name' => 'Proctor '.fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
