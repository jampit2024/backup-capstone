<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examination_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('duration_minutes')->default(90);
            $table->boolean('allow_late_entry')->default(false);
            $table->dateTime('default_start_at')->nullable();
            $table->dateTime('default_end_at')->nullable();
            $table->timestamps();
        });

        DB::table('examination_settings')->insert([
            'duration_minutes' => 90,
            'allow_late_entry' => false,
            'default_start_at' => null,
            'default_end_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_settings');
    }
};
