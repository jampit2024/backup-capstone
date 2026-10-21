<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_email_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('queued')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 20)->default('pending');
            $table->json('filters')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('examination_registrations', function (Blueprint $table) {
            $table->foreign('result_email_batch_id')
                ->references('id')
                ->on('result_email_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            $table->dropForeign(['result_email_batch_id']);
        });

        Schema::dropIfExists('result_email_batches');
    }
};
