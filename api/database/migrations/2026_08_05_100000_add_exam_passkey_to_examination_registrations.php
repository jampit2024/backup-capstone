<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_registrations', 'exam_passkey')) {
                $table->string('exam_passkey', 12)->nullable()->after('applicant_id');
            }
            if (! Schema::hasColumn('examination_registrations', 'passkey_email_sent')) {
                $table->boolean('passkey_email_sent')->default(false)->after('exam_passkey');
            }
            if (! Schema::hasColumn('examination_registrations', 'passkey_email_sent_at')) {
                $table->timestamp('passkey_email_sent_at')->nullable()->after('passkey_email_sent');
            }
            if (! Schema::hasColumn('examination_registrations', 'passkey_email_status')) {
                $table->string('passkey_email_status', 20)->nullable()->after('passkey_email_sent_at');
            }
            if (! Schema::hasColumn('examination_registrations', 'passkey_email_attempts')) {
                $table->unsignedSmallInteger('passkey_email_attempts')->default(0)->after('passkey_email_status');
            }
            if (! Schema::hasColumn('examination_registrations', 'passkey_email_error')) {
                $table->string('passkey_email_error', 500)->nullable()->after('passkey_email_attempts');
            }
        });

        // Separate unique index so SQLite can drop it cleanly on rollback.
        $indexName = 'examination_registrations_exam_passkey_unique';
        $indexes = Schema::getIndexes('examination_registrations');
        $hasIndex = collect($indexes)->contains(fn ($idx) => ($idx['name'] ?? '') === $indexName);
        if (! $hasIndex && Schema::hasColumn('examination_registrations', 'exam_passkey')) {
            Schema::table('examination_registrations', function (Blueprint $table) use ($indexName) {
                $table->unique('exam_passkey', $indexName);
            });
        }

        if (! Schema::hasTable('examination_passkey_email_logs')) {
            Schema::create('examination_passkey_email_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('examination_registration_id');
                $table->foreign('examination_registration_id', 'fk_exam_passkey_delivery_logs_registration')
                    ->references('id')
                    ->on('examination_registrations')
                    ->cascadeOnDelete();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('email', 255)->nullable();
                $table->string('status', 20);
                $table->string('error_message', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_passkey_email_logs');

        $indexName = 'examination_registrations_exam_passkey_unique';
        $indexes = Schema::getIndexes('examination_registrations');
        $hasIndex = collect($indexes)->contains(fn ($idx) => ($idx['name'] ?? '') === $indexName);

        if ($hasIndex) {
            Schema::table('examination_registrations', function (Blueprint $table) use ($indexName) {
                $table->dropUnique($indexName);
            });
        }

        // Drop columns one-at-a-time for SQLite compatibility.
        foreach ([
            'passkey_email_error',
            'passkey_email_attempts',
            'passkey_email_status',
            'passkey_email_sent_at',
            'passkey_email_sent',
            'exam_passkey',
        ] as $column) {
            if (Schema::hasColumn('examination_registrations', $column)) {
                Schema::table('examination_registrations', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
