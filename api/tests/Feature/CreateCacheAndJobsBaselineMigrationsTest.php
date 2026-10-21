<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateCacheAndJobsBaselineMigrationsTest extends TestCase
{
    public function test_it_preserves_existing_cache_and_jobs_tables_and_creates_missing_tables(): void
    {
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        DB::table('cache')->insert([
            'key' => 'existing-entry',
            'value' => 'keep me',
            'expiration' => 1,
        ]);

        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue');
        });
        DB::table('jobs')->insert(['queue' => 'existing-queue']);

        $cacheMigration = require database_path('migrations/0001_01_01_000001_create_cache_table.php');
        $jobsMigration = require database_path('migrations/0001_01_01_000002_create_jobs_table.php');
        $cacheMigration->up();
        $jobsMigration->up();

        $this->assertSame('keep me', DB::table('cache')->where('key', 'existing-entry')->value('value'));
        $this->assertSame('existing-queue', DB::table('jobs')->value('queue'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));

        $cacheMigration->down();
        $jobsMigration->down();

        $this->assertSame('keep me', DB::table('cache')->where('key', 'existing-entry')->value('value'));
        $this->assertSame('existing-queue', DB::table('jobs')->value('queue'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }
}
