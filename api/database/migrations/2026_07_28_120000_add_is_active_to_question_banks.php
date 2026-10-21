<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('title');
        });

        // Activate the most recent school-year bank by default.
        $latestId = DB::table('question_banks')->orderByDesc('school_year')->value('id');
        if ($latestId) {
            DB::table('question_banks')->where('id', $latestId)->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
