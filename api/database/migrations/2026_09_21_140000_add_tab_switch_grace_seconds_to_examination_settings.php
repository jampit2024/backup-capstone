<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('examination_settings')) {
            Schema::table('examination_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('examination_settings', 'tab_switch_grace_seconds')) {
                    $table->unsignedSmallInteger('tab_switch_grace_seconds')
                        ->default(5)
                        ->after('disconnect_grace_seconds');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('examination_settings') && Schema::hasColumn('examination_settings', 'tab_switch_grace_seconds')) {
            Schema::table('examination_settings', function (Blueprint $table) {
                $table->dropColumn('tab_switch_grace_seconds');
            });
        }
    }
};

