<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->text('email_instructions')->nullable()->after('expected_examinees');
            $table->boolean('include_app_download')->default(false)->after('email_instructions');
            $table->string('app_download_name', 150)->nullable()->after('include_app_download');
            $table->string('app_download_url', 500)->nullable()->after('app_download_name');
            $table->text('app_download_instructions')->nullable()->after('app_download_url');
        });
    }

    public function down(): void
    {
        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'email_instructions',
                'include_app_download',
                'app_download_name',
                'app_download_url',
                'app_download_instructions',
            ]);
        });
    }
};

