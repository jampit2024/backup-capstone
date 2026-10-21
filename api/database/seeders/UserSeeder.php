<?php

namespace Database\Seeders;

use App\Enums\TestItemStatus;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\TestItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('slug', 'admin')->firstOrFail();
        $proctorRole = Role::where('slug', 'proctor')->firstOrFail();

        $admin = User::updateOrCreate(
            ['email' => 'rommeljampit21@gmail.com'],
            [
                'role_id' => $adminRole->id,
                'name' => 'Rommel Jampit',
                'password' => Hash::make('Password123!'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]
        );

        // Keep a secondary local admin for API smoke tests.
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'role_id' => $adminRole->id,
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]
        );

        $proctor = User::updateOrCreate(
            ['email' => 'proctor@example.com'],
            [
                'role_id' => $proctorRole->id,
                'name' => 'Proctor User',
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]
        );

        $proctorTwo = User::updateOrCreate(
            ['email' => 'proctor2@example.com'],
            [
                'role_id' => $proctorRole->id,
                'name' => 'Maria D. Santos',
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]
        );

        $items = [
            ['title' => 'Sample Draft Item', 'description' => 'A draft test item for API testing.', 'status' => TestItemStatus::Draft],
            ['title' => 'Active Test Record', 'description' => 'An active item visible in listings.', 'status' => TestItemStatus::Active],
            ['title' => 'Archived Entry', 'description' => 'An archived item for filter testing.', 'status' => TestItemStatus::Archived],
            ['title' => 'Admin Created Item', 'description' => 'Created by admin for scope testing.', 'status' => TestItemStatus::Active],
            ['title' => 'Proctor Personal Item', 'description' => 'Owned by proctor for scope testing.', 'status' => TestItemStatus::Active],
            ['title' => 'Quick API Test', 'description' => 'Use this for CRUD smoke tests.', 'status' => TestItemStatus::Draft],
            ['title' => 'Dashboard Summary Item', 'description' => 'Shows up in dashboard counts.', 'status' => TestItemStatus::Active],
        ];

        TestItem::whereIn('user_id', [$admin->id, $proctor->id, $proctorTwo->id])->delete();

        foreach (array_slice($items, 0, 3) as $item) {
            TestItem::create(array_merge($item, ['user_id' => $admin->id]));
        }

        TestItem::create(array_merge($items[3], ['user_id' => $admin->id]));
        TestItem::create(array_merge($items[4], ['user_id' => $proctor->id]));
        TestItem::create(array_merge($items[5], ['user_id' => $proctor->id]));
        TestItem::create(array_merge($items[6], ['user_id' => $proctorTwo->id]));
    }
}
