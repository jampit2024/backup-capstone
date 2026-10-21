<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_configurations', function (Blueprint $table): void {
            $table->string('connection_status', 40)->default('saved_not_tested')->after('is_connected');
            $table->string('last_error_code', 64)->nullable()->after('connection_status');
            $table->text('last_error_message')->nullable()->after('last_error_code');
            $table->timestamp('last_tested_at')->nullable()->after('verified_at');
        });

        Schema::table('email_notifications', function (Blueprint $table): void {
            $table->string('error_code', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('email_notifications', function (Blueprint $table): void {
            $table->dropColumn('error_code');
        });

        Schema::table('email_configurations', function (Blueprint $table): void {
            $table->dropColumn([
                'connection_status',
                'last_error_code',
                'last_error_message',
                'last_tested_at',
            ]);
        });
    }
};
