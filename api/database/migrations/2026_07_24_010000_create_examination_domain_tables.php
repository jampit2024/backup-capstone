<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->string('applicant_code')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('gmail')->nullable();
            $table->timestamp('gmail_submitted_at')->nullable();
            $table->string('course_applied', 100)->nullable();
            $table->string('status', 30)->default('registered');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('examination_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('venue', 120)->nullable();
            $table->string('batch_code', 50)->nullable();
            $table->string('course', 100)->nullable();
            $table->foreignId('proctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('scheduled');
            $table->timestamps();

            $table->index(['exam_date', 'status']);
        });

        Schema::create('examination_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('attendance_status', 20)->default('pending');
            $table->string('result_status', 20)->default('pending');
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['examination_schedule_id', 'applicant_id'], 'exam_reg_unique');
            $table->index('attendance_status');
            $table->index('result_status');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('description');
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('examination_registrations');
        Schema::dropIfExists('examination_schedules');
        Schema::dropIfExists('applicants');
    }
};
