<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('smtp');
            $table->string('sender_email');
            $table->string('sender_name');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 12)->default('tls');
            $table->string('username');
            $table->text('password');
            $table->boolean('is_connected')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('exam_registrations')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('exam_schedules')->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('type', 32);
            $table->string('status', 24)->default('pending');
            $table->text('error_details')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status', 'created_at']);
            $table->index('registration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_notifications');
        Schema::dropIfExists('email_configurations');
    }
};
