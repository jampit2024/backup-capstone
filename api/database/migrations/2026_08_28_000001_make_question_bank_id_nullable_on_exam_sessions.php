<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-002 fix: make question_bank_id nullable on exam_sessions.
 *
 * The original migration defined question_bank_id as NOT NULL (foreignId without ->nullable()).
 * When a proctor opens a lobby without selecting a question bank the service passes null,
 * causing a DB constraint violation / HTTP 500.  This migration relaxes the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            // Change to nullable — allows opening a lobby before a question bank is chosen.
            $table->foreignId('question_bank_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        // NOTE: reverting this will fail if any rows have question_bank_id = NULL.
        // Only run down() on a fresh database.
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->foreignId('question_bank_id')
                ->nullable(false)
                ->change();
        });
    }
};
