<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $questions = DB::table('exam_questions')
            ->select(['id', 'options', 'correct_answer'])
            ->orderBy('id')
            ->get();

        $choiceRows = [];
        $choiceKeysByQuestion = [];
        foreach ($questions as $question) {
            $options = is_array($question->options)
                ? $question->options
                : json_decode((string) $question->options, true);

            if (! is_array($options)) {
                throw new UnexpectedValueException("Question {$question->id} has invalid options JSON.");
            }

            $questionChoices = $this->normalizeOptions($options, (int) $question->id);
            $correctAnswer = strtoupper(trim((string) $question->correct_answer));
            $choiceKeys = array_column($questionChoices, 'choice_key');
            $choiceKeysByQuestion[(int) $question->id] = $choiceKeys;

            if ($correctAnswer !== '' && ! in_array($correctAnswer, $choiceKeys, true)) {
                throw new UnexpectedValueException("Question {$question->id} has a correct answer without a matching choice.");
            }

            foreach ($questionChoices as $choice) {
                $choiceRows[] = [
                    'exam_question_id' => (int) $question->id,
                    ...$choice,
                    'is_correct' => $choice['choice_key'] === $correctAnswer,
                ];
            }
        }

        $answers = DB::table('exam_answers')
            ->select(['id', 'exam_question_id', 'selected_answer'])
            ->whereNotNull('selected_answer')
            ->orderBy('id')
            ->get();

        foreach ($answers as $answer) {
            $questionId = (int) $answer->exam_question_id;
            $selectedAnswer = strtoupper(trim($answer->selected_answer));
            if (! in_array($selectedAnswer, $choiceKeysByQuestion[$questionId] ?? [], true)) {
                throw new UnexpectedValueException("Answer {$answer->id} does not match a choice for its question.");
            }
        }

        Schema::create('exam_question_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->string('choice_key', 10);
            $table->text('choice_text');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_correct')->default(false);
            $table->unique(['exam_question_id', 'choice_key'], 'uq_exam_question_choices_question_key');
            $table->index(['exam_question_id', 'sort_order'], 'ix_exam_question_choices_question_order');
            $table->index(['exam_question_id', 'is_correct'], 'ix_exam_question_choices_question_correct');
        });

        foreach (array_chunk($choiceRows, 500) as $chunk) {
            DB::table('exam_question_choices')->insert($chunk);
        }

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropColumn(['options', 'correct_answer']);
        });
    }

    public function down(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->json('options')->nullable();
            $table->string('correct_answer')->nullable();
        });

        $choices = DB::table('exam_question_choices')
            ->orderBy('exam_question_id')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('exam_question_id');

        foreach ($choices as $questionId => $rows) {
            $options = $rows->map(fn ($choice) => [
                'key' => $choice->choice_key,
                'text' => $choice->choice_text,
            ])->values()->all();
            $correctAnswer = $rows->firstWhere('is_correct', true)?->choice_key;

            DB::table('exam_questions')->where('id', $questionId)->update([
                'options' => json_encode($options, JSON_THROW_ON_ERROR),
                'correct_answer' => $correctAnswer,
            ]);
        }

        Schema::dropIfExists('exam_question_choices');
    }

    /**
     * @return list<array{choice_key: string, choice_text: string, sort_order: int}>
     */
    private function normalizeOptions(array $options, int $questionId): array
    {
        $normalized = [];
        foreach (array_values($options) as $index => $option) {
            $originalKey = array_keys($options)[$index] ?? $index;
            if (is_array($option)) {
                $key = strtoupper(trim((string) ($option['key'] ?? (is_string($originalKey) ? $originalKey : ''))));
                $text = $option['text'] ?? $option['label'] ?? $option['value'] ?? null;
            } else {
                $key = is_string($originalKey) && preg_match('/^[A-Za-z]{1,10}$/', $originalKey)
                    ? strtoupper($originalKey)
                    : $this->letterForIndex($index);
                $text = $option;
            }

            if ($key === '') {
                $key = $this->letterForIndex($index);
            }
            if (! is_scalar($text) || isset($normalized[$key])) {
                throw new UnexpectedValueException("Question {$questionId} has an invalid or duplicate choice.");
            }

            $normalized[$key] = [
                'choice_key' => $key,
                'choice_text' => (string) $text,
                'sort_order' => $index,
            ];
        }

        return array_values($normalized);
    }

    private function letterForIndex(int $index): string
    {
        if ($index > 25) {
            throw new UnexpectedValueException('Questions cannot have more than 26 choices.');
        }

        return chr(65 + $index);
    }
};