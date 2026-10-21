<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            if (! Schema::hasColumn($tableName, 'exam_passkey_encrypted')) {
                // Reversible (AES via APP_KEY) copy of the plaintext code so admins can
                // view/copy it in the dashboard. Look-ups still use exam_passkey_hash.
                $table->text('exam_passkey_encrypted')->nullable()->after('exam_passkey_hash');
            }
        });
    }

    public function down(): void
    {
        $tableName = 'exam_registrations';
        if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'exam_passkey_encrypted')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('exam_passkey_encrypted'));
        }
    }
};
