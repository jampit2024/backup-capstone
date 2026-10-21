<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 24);
            $table->string('status', 16);
            $table->json('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_versions');
    }
};
