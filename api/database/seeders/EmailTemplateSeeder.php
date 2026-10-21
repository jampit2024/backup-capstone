<?php

namespace Database\Seeders;

use App\Models\EmailTemplateVersion;
use App\Models\User;
use App\Services\EmailTemplateService;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(EmailTemplateService::class);
        $admin = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first();

        foreach (EmailTemplateService::TYPES as $type) {
            $existing = EmailTemplateVersion::query()->where('type', $type)->where('status', 'published')->first();
            if (! $existing) {
                EmailTemplateVersion::query()->create([
                    'type' => $type,
                    'status' => 'published',
                    'content' => $service->defaults($type),
                    'created_by' => $admin?->id,
                    'published_at' => now(),
                ]);
            }
        }
    }
}

