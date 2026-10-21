<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateRolesMigrationTest extends TestCase
{
    public function test_it_preserves_an_existing_roles_table_and_its_data(): void
    {
        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        DB::table('roles')->insert([
            'name' => 'Proctor',
            'slug' => 'proctor',
        ]);

        $migration = require database_path('migrations/2026_06_17_000001_create_roles_table.php');
        $migration->up();

        $this->assertSame('Proctor', DB::table('roles')->where('slug', 'proctor')->value('name'));

        $migration->down();

        $this->assertSame('Proctor', DB::table('roles')->where('slug', 'proctor')->value('name'));
    }

    public function test_it_creates_the_roles_table_when_missing(): void
    {
        $migration = require database_path('migrations/2026_06_17_000001_create_roles_table.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumns('roles', ['id', 'name', 'slug', 'created_at', 'updated_at']));
    }
}
