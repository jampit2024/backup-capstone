<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_settings', 'shuffle_questions')) {
                $table->boolean('shuffle_questions')->default(true)->after('allow_late_entry');
            }
            if (! Schema::hasColumn('examination_settings', 'shuffle_categories')) {
                $table->boolean('shuffle_categories')->default(false)->after('shuffle_questions');
            }
            if (! Schema::hasColumn('examination_settings', 'shuffle_both')) {
                $table->boolean('shuffle_both')->default(false)->after('shuffle_categories');
            }
        });
    }

    public function down(): void
    {
        Schema::table('examination_settings', function (Blueprint $table) {
            foreach (['shuffle_questions', 'shuffle_categories', 'shuffle_both'] as $col) {
                if (Schema::hasColumn('examination_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
