<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('examination_violations')) {
            return;
        }

        Schema::create('examination_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_registration_id')
                ->constrained('examination_registrations')
                ->cascadeOnDelete();
            $table->foreignId('exam_session_id')
                ->nullable()
                ->constrained('exam_sessions')
                ->nullOnDelete();
            $table->string('type', 60);
            $table->string('message', 500)->nullable();
            $table->unsignedInteger('violation_count')->default(1);
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['exam_session_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_violations');
    }
};
