<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_public_registration_is_disabled(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_login(): void
    {
        $role = Role::where('slug', 'admin')->first();
        User::create([
            'role_id' => $role->id,
            'name' => 'Test Admin',
            'email' => 'login@example.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['success', 'data' => ['user', 'token']]);
    }

    public function test_proctor_cannot_login_to_web_app(): void
    {
        $role = Role::where('slug', 'proctor')->first();
        User::create([
            'role_id' => $role->id,
            'name' => 'Test Proctor',
            'email' => 'proctor@example.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'proctor@example.com',
            'password' => 'password',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Admin access only. Proctor accounts use the mobile examination application.');
    }

    public function test_forgot_password_does_not_send_for_proctor_email(): void
    {
        $role = Role::where('slug', 'proctor')->first();
        User::create([
            'role_id' => $role->id,
            'name' => 'Test Proctor',
            'email' => 'proctor.reset@example.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'proctor.reset@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = $this->createUser('admin');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    private function createUser(string $roleSlug = 'admin'): User
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
