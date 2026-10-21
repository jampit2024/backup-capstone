<?php

namespace Database\Factories;

use App\Enums\TestItemStatus;
use App\Models\TestItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TestItem> */
class TestItemFactory extends Factory
{
    protected $model = TestItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(TestItemStatus::cases()),
        ];
    }
}
