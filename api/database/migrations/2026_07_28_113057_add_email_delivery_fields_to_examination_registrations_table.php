<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('rescheduled_by');
            $table->string('email_status', 20)->default('pending')->after('email_sent_at');
            $table->unsignedSmallInteger('email_attempts')->default(0)->after('email_status');
            $table->text('email_error_message')->nullable()->after('email_attempts');
            $table->foreignId('result_email_batch_id')->nullable()->after('email_error_message');

            $table->index('email_status');
        });
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('examination_registrations'));
        if ($indexes->contains(fn ($idx) => ($idx['name'] ?? '') === 'examination_registrations_email_status_index')) {
            Schema::table('examination_registrations', function (Blueprint $table) {
                $table->dropIndex('examination_registrations_email_status_index');
            });
        }

        foreach ([
            'result_email_batch_id',
            'email_error_message',
            'email_attempts',
            'email_status',
            'email_sent_at',
        ] as $column) {
            if (Schema::hasColumn('examination_registrations', $column)) {
                Schema::table('examination_registrations', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
