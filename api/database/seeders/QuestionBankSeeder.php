<?php

namespace Database\Seeders;

use App\Models\QuestionBank;
use Illuminate\Database\Seeder;

class QuestionBankSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['2025', '2026'] as $year) {
            QuestionBank::query()->updateOrCreate(
                ['school_year' => $year],
                [
                    'title' => QuestionBank::titleFromSchoolYear($year),
                    'is_active' => false,
                ]
            );
        }

        $latest = QuestionBank::query()->orderByDesc('school_year')->first();
        $latest?->activate();
    }
}
