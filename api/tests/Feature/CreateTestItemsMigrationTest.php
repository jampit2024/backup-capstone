<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateTestItemsMigrationTest extends TestCase
{
    public function test_it_preserves_an_existing_test_items_table_and_its_data(): void
    {
        Schema::create('test_items', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });
        DB::table('test_items')->insert([
            'user_id' => 1,
            'title' => 'Existing test',
            'status' => 'draft',
        ]);

        $migration = require database_path('migrations/2026_06_17_000003_create_test_items_table.php');
        $migration->up();

        $this->assertSame('Existing test', DB::table('test_items')->value('title'));

        $migration->down();

        $this->assertTrue(Schema::hasTable('test_items'));
        $this->assertSame('Existing test', DB::table('test_items')->value('title'));
    }

    public function test_it_creates_test_items_when_missing(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
        });

        $migration = require database_path('migrations/2026_06_17_000003_create_test_items_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('test_items', [
            'id',
            'user_id',
            'title',
            'description',
            'status',
            'created_at',
            'updated_at',
        ]));
    }
}
