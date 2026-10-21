<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddRoleIdToUsersMigrationTest extends TestCase
{
    public function test_it_preserves_an_existing_role_id_column_and_its_data(): void
    {
        Schema::create('roles', function ($table) {
            $table->id();
        });
        $roleId = DB::table('roles')->insertGetId([]);

        Schema::create('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
        });
        DB::table('users')->insert(['role_id' => $roleId]);

        $migration = require database_path('migrations/2026_06_17_000002_add_role_id_to_users_table.php');
        $migration->up();

        $this->assertSame($roleId, DB::table('users')->value('role_id'));

        $migration->down();

        $this->assertTrue(Schema::hasColumn('users', 'role_id'));
        $this->assertSame($roleId, DB::table('users')->value('role_id'));
    }

    public function test_it_adds_role_id_when_missing(): void
    {
        Schema::create('roles', function ($table) {
            $table->id();
        });
        Schema::create('users', function ($table) {
            $table->id();
        });

        $migration = require database_path('migrations/2026_06_17_000002_add_role_id_to_users_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'role_id'));
    }
}
