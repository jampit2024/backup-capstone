<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_registrations', function (Blueprint $table): void {
            $table->string('examination_method', 20)->default('online');
            $table->decimal('manual_score', 7, 2)->nullable();
            $table->decimal('manual_max_score', 7, 2)->nullable();
            $table->text('manual_remarks')->nullable();
            $table->foreignId('manual_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manual_recorded_at')->nullable();
            $table->foreignId('manual_last_modified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('examination_method');
        });

        Schema::create('manual_score_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('examination_registration_id')->constrained('exam_registrations')->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation', 20);
            $table->decimal('previous_score', 7, 2)->nullable();
            $table->decimal('new_score', 7, 2);
            $table->decimal('maximum_score', 7, 2)->default(100);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['examination_registration_id', 'created_at'], 'manual_score_history_registration_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_score_histories');

        Schema::table('exam_registrations', function (Blueprint $table): void {
            $table->dropForeign(['manual_recorded_by']);
            $table->dropForeign(['manual_last_modified_by']);
            $table->dropIndex(['examination_method']);
            $table->dropColumn([
                'examination_method',
                'manual_score',
                'manual_max_score',
                'manual_remarks',
                'manual_recorded_by',
                'manual_recorded_at',
                'manual_last_modified_by',
            ]);
        });
    }
};
