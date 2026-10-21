<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            $table->foreignId('previous_schedule_id')
                ->nullable()
                ->after('examination_schedule_id')
                ->constrained('examination_schedules')
                ->nullOnDelete();
            $table->text('reschedule_reason')->nullable()->after('score');
            $table->timestamp('rescheduled_at')->nullable()->after('reschedule_reason');
            $table->foreignId('rescheduled_by')
                ->nullable()
                ->after('rescheduled_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_schedule_id');
            $table->dropConstrainedForeignId('rescheduled_by');
            $table->dropColumn(['reschedule_reason', 'rescheduled_at']);
        });
    }
};
