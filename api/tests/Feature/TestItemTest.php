<?php

namespace Tests\Feature;

use App\Enums\TestItemStatus;
use App\Models\Role;
use App\Models\TestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TestItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_user_can_create_test_item(): void
    {
        $user = $this->createUser('proctor');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/test-items', [
            'title' => 'Test Item',
            'description' => 'Description',
            'status' => TestItemStatus::Draft->value,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Test Item');
    }

    public function test_user_only_sees_own_items(): void
    {
        $user = $this->createUser('proctor');
        $other = $this->createUser('proctor');
        TestItem::create([
            'user_id' => $other->id,
            'title' => 'Other Item',
            'status' => TestItemStatus::Active,
        ]);
        TestItem::create([
            'user_id' => $user->id,
            'title' => 'My Item',
            'status' => TestItemStatus::Active,
        ]);

        $token = $user->createToken('test')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/test-items');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    private function createUser(string $roleSlug): User
    {
        $role = Role::where('slug', $roleSlug)->first();

        return User::create([
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
