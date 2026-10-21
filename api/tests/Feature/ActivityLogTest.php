<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_and_paginate_real_activity_logs(): void
    {
        $admin = $this->admin();
        ActivityLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'updated',
            'description' => 'Updated examination schedule',
        ]);
        ActivityLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'created',
            'description' => 'Created a question bank',
        ]);

        $this->actingAs($admin)->getJson('/api/activity-logs?search=schedule&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'updated')
            ->assertJsonPath('data.0.description', 'Updated examination schedule');

        $export = $this->actingAs($admin)->get('/api/activity-logs/export?search=schedule');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Updated examination schedule', $export->streamedContent());
    }

    public function test_non_admin_cannot_read_or_export_activity_logs(): void
    {
        $proctorRole = Role::query()->firstOrCreate(
            ['slug' => Role::SLUG_PROCTOR],
            ['name' => 'Examination Proctor'],
        );
        $proctor = User::factory()->create(['role_id' => $proctorRole->id]);

        $this->actingAs($proctor)->getJson('/api/activity-logs')->assertForbidden();
        $this->actingAs($proctor)->get('/api/activity-logs/export')->assertForbidden();
    }

    private function admin(): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => Role::SLUG_ADMIN],
            ['name' => 'Administrator'],
        );

        return User::factory()->create(['role_id' => $role->id]);
    }
}
