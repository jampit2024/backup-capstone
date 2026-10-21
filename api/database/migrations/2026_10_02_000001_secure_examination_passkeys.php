<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'exam_registrations';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (! Schema::hasColumn($tableName, 'exam_passkey_hash')) {
                $table->char('exam_passkey_hash', 64)->nullable()->after('applicant_id');
            }
            if (! Schema::hasColumn($tableName, 'passkey_status')) {
                $table->string('passkey_status', 20)->default('not_sent');
            }
            if (! Schema::hasColumn($tableName, 'passkey_sent_to_email')) {
                $table->string('passkey_sent_to_email', 255)->nullable();
            }
            if (! Schema::hasColumn($tableName, 'passkey_used_at')) {
                $table->timestamp('passkey_used_at')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'passkey_expires_at')) {
                $table->timestamp('passkey_expires_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'exam_passkey')) {
            DB::table('exam_registrations as registrations')
                ->leftJoin('exam_schedules as schedules', 'schedules.id', '=', 'registrations.examination_schedule_id')
                ->leftJoin('applicants', 'applicants.id', '=', 'registrations.applicant_id')
                ->select([
                    'registrations.id as id',
                    'registrations.exam_passkey as exam_passkey',
                    'registrations.passkey_email_sent as passkey_email_sent',
                    'registrations.passkey_email_status as passkey_email_status',
                    'registrations.exam_session_id as exam_session_id',
                    'registrations.lobby_status as lobby_status',
                    'registrations.joined_lobby_at as joined_lobby_at',
                    'schedules.exam_date as exam_date',
                    'schedules.end_time as end_time',
                    'applicants.gmail as gmail',
                    'applicants.email as email',
                ])
                ->orderBy('registrations.id')
                ->chunkById(250, function ($registrations): void {
                    foreach ($registrations as $registration) {
                        $plainKey = strtoupper(trim((string) ($registration->exam_passkey ?? '')));
                        $expiry = null;
                        if ($registration->exam_date) {
                            try {
                                $expiry = Carbon::parse($registration->exam_date.' '.($registration->end_time ?: '23:59:59'))
                                    ->toDateTimeString();
                            } catch (\Throwable) {
                                $expiry = Carbon::parse($registration->exam_date)->endOfDay()->toDateTimeString();
                            }
                        }
                        $wasSent = (bool) $registration->passkey_email_sent
                            || strtolower((string) $registration->passkey_email_status) === 'sent';
                        $wasUsed = $registration->exam_session_id !== null
                            || in_array(strtolower((string) $registration->lobby_status), ['waiting', 'taking_exam', 'warning', 'finished', 'disconnected'], true);

                        DB::table('exam_registrations')->where('id', $registration->id)->update([
                            'exam_passkey_hash' => $plainKey !== '' ? hash('sha256', $plainKey) : null,
                            'passkey_status' => $plainKey === '' ? 'not_sent' : ($wasUsed ? 'used' : ($wasSent ? 'sent' : 'not_sent')),
                            'passkey_sent_to_email' => $wasSent
                                ? ($registration->gmail ?: $registration->email)
                                : null,
                            'passkey_used_at' => $wasUsed ? ($registration->joined_lobby_at ?: now()) : null,
                            'passkey_expires_at' => $expiry,
                        ]);
                    }
                }, 'registrations.id', 'id');

            foreach (Schema::getIndexes($tableName) as $index) {
                if (($index['unique'] ?? false) && in_array('exam_passkey', $index['columns'] ?? [], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique($index['name']));
                }
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('exam_passkey'));
        }

        $hashIndexExists = collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index) => ($index['name'] ?? '') === 'exam_registrations_passkey_hash_unique');
        if (! $hashIndexExists) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique('exam_passkey_hash', 'exam_registrations_passkey_hash_unique'));
        }
        $statusIndexExists = collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index) => ($index['name'] ?? '') === 'exam_registrations_passkey_status_expiry_idx');
        if (! $statusIndexExists) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index(['passkey_status', 'passkey_expires_at'], 'exam_registrations_passkey_status_expiry_idx');
            });
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Hashed examination passkeys cannot be converted back to plaintext. Restore the pre-migration database backup to roll back.');
    }
};
