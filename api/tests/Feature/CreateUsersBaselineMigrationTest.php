<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateUsersBaselineMigrationTest extends TestCase
{
    public function test_it_preserves_existing_users_and_creates_missing_foundation_tables(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
        DB::table('users')->insert(['name' => 'Existing account']);

        $migration = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $migration->up();

        $this->assertSame('Existing account', DB::table('users')->value('name'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('sessions'));

        $migration->down();

        $this->assertSame('Existing account', DB::table('users')->value('name'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('sessions'));
    }
}
