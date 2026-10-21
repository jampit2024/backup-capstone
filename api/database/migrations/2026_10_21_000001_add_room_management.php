<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('name_key', 80)->unique();
            $table->unsignedInteger('capacity');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->boolean('rooms_managed')->default(false);
            $table->timestamp('rooms_published_at')->nullable();
            $table->unsignedInteger('rooms_revision')->default(0);
        });
        Schema::table('exam_rooms', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->unique(['examination_schedule_id', 'room_id']);
        });
        Schema::table('exam_registrations', function (Blueprint $table) {
            $table->foreignId('assigned_room_id')->nullable()->constrained('exam_rooms')->restrictOnDelete();
            $table->boolean('room_assignment_manual')->default(false);
        });
        // A nullable unique key works on both MySQL and SQLite. Only live sessions
        // receive a key; it is cleared on close/end and never on network timeout.
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('active_room_id')->nullable()->unique();
            $table->unsignedBigInteger('active_proctor_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropUnique(['active_room_id']);
            $table->dropUnique(['active_proctor_id']);
            $table->dropColumn(['active_room_id', 'active_proctor_id']);
        });
        Schema::table('exam_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_room_id');
            $table->dropColumn('room_assignment_manual');
        });
        Schema::table('exam_rooms', function (Blueprint $table) {
            $table->dropUnique(['examination_schedule_id', 'room_id']);
            $table->dropConstrainedForeignId('room_id');
        });
        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->dropColumn(['rooms_managed', 'rooms_published_at', 'rooms_revision']);
        });
        Schema::dropIfExists('rooms');
    }
};
