<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('reschedule_capacity')->default(10)->after('capacity');
        });
        Schema::table('exam_rooms', function (Blueprint $table) {
            $table->unsignedInteger('reschedule_capacity')->default(10)->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('exam_rooms', fn (Blueprint $table) => $table->dropColumn('reschedule_capacity'));
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn('reschedule_capacity'));
    }
};
