<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Passkey security columns (hash, status, timestamps) are handled by
        // 2026_10_02_000001_secure_examination_passkeys.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
