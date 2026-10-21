<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examination_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->string('selected_answer', 10)->nullable();
            $table->boolean('is_correct')->default(false);
            $table->timestamps();

            $table->unique(['examination_registration_id', 'exam_question_id'], 'exam_answer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_answers');
    }
};
