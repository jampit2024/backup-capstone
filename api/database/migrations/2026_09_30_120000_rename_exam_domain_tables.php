<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE_RENAMES = [
        'examination_schedules' => 'exam_schedules',
        'examination_rooms' => 'exam_rooms',
        'examination_registrations' => 'exam_registrations',
        'examination_answers' => 'exam_answers',
        'examination_settings' => 'exam_settings',
        'question_banks' => 'exam_packs',
        'exam_session_joins' => 'exam_session_join_records',
        'examination_passkey_email_logs' => 'exam_passkey_delivery_logs',
    ];

    public function up(): void
    {
        foreach (self::TABLE_RENAMES as $oldName => $newName) {
            Schema::rename($oldName, $newName);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLE_RENAMES, true) as $oldName => $newName) {
            Schema::rename($newName, $oldName);
        }
    }
};