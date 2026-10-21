<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_registrations')
            || ! Schema::hasColumn('exam_registrations', 'passkey_expires_at')
            || ! Schema::hasTable('exam_schedules')
        ) {
            return;
        }

        DB::table('exam_registrations as registrations')
            ->join('exam_schedules as schedules', 'schedules.id', '=', 'registrations.examination_schedule_id')
            ->whereNotNull('registrations.passkey_expires_at')
            ->whereNotNull('schedules.exam_date')
            ->select([
                'registrations.id as registration_id',
                'schedules.exam_date as exam_date',
                'schedules.end_time as end_time',
            ])
            ->orderBy('registrations.id')
            ->chunkById(250, function ($registrations): void {
                foreach ($registrations as $registration) {
                    $expiry = Carbon::parse(
                        $registration->exam_date.' '.($registration->end_time ?: '23:59:59'),
                        'Asia/Manila',
                    )->toDateTimeString();

                    DB::table('exam_registrations')
                        ->where('id', $registration->registration_id)
                        ->update(['passkey_expires_at' => $expiry]);
                }
            }, 'registrations.id', 'registration_id');
    }

    public function down(): void
    {
        throw new RuntimeException('Passkey expiry timestamps are normalized from their schedules and cannot be safely reverted.');
    }
};