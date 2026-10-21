<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_schedules', function (Blueprint $table) {
            $table->unsignedInteger('expected_examinees')->default(0)->after('status');
            $table->string('time_slot', 30)->nullable()->after('end_time');
        });

        Schema::create('examination_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_schedule_id')->constrained()->cascadeOnDelete();
            $table->string('room_name', 80);
            $table->unsignedInteger('capacity')->default(100);
            $table->foreignId('proctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('examination_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_rooms');

        Schema::table('examination_schedules', function (Blueprint $table) {
            $table->dropColumn(['expected_examinees', 'time_slot']);
        });
    }
};
