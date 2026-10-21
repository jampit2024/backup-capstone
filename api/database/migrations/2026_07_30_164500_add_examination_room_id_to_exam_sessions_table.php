<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->foreignId('examination_room_id')
                ->nullable()
                ->after('examination_schedule_id')
                ->constrained('examination_rooms')
                ->nullOnDelete();

            $table->index(['examination_schedule_id', 'examination_room_id', 'status'], 'exam_sessions_schedule_room_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropIndex('exam_sessions_schedule_room_status_index');
            $table->dropConstrainedForeignId('examination_room_id');
        });
    }
};
