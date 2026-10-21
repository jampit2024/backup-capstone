<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Admin', 'slug' => 'admin'],
            ['name' => 'Proctor', 'slug' => 'proctor'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }

        $allowedSlugs = collect($roles)->pluck('slug');
        $fallbackRole = Role::where('slug', 'proctor')->first();

        Role::whereNotIn('slug', $allowedSlugs)->each(function (Role $role) use ($fallbackRole): void {
            if ($fallbackRole) {
                User::where('role_id', $role->id)->update(['role_id' => $fallbackRole->id]);
            }

            $role->delete();
        });
    }
}
