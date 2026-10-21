<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreatePersonalAccessTokensMigrationTest extends TestCase
{
    public function test_it_preserves_an_existing_token_table_and_its_data(): void
    {
        Schema::create('personal_access_tokens', function ($table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => 1,
            'name' => 'Existing token',
            'token' => str_repeat('a', 64),
        ]);

        $migration = require database_path('migrations/2026_06_17_024514_create_personal_access_tokens_table.php');
        $migration->up();

        $this->assertSame('Existing token', DB::table('personal_access_tokens')->value('name'));

        $migration->down();

        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertSame('Existing token', DB::table('personal_access_tokens')->value('name'));
    }

    public function test_it_creates_the_token_table_when_missing(): void
    {
        $migration = require database_path('migrations/2026_06_17_024514_create_personal_access_tokens_table.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumns('personal_access_tokens', [
            'id',
            'tokenable_type',
            'tokenable_id',
            'name',
            'token',
            'abilities',
            'last_used_at',
            'expires_at',
            'created_at',
            'updated_at',
        ]));
    }
}
