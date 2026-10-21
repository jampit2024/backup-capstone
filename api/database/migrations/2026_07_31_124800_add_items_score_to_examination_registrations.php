<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_registrations', 'items_correct')) {
                $table->unsignedInteger('items_correct')->nullable()->after('score');
            }
            if (! Schema::hasColumn('examination_registrations', 'items_total')) {
                $table->unsignedInteger('items_total')->nullable()->after('items_correct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            foreach (['items_correct', 'items_total'] as $col) {
                if (Schema::hasColumn('examination_registrations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
