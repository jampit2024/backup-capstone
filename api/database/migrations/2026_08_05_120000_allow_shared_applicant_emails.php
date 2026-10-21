<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Households may share one Gmail across siblings — do not enforce uniqueness.
        $indexes = collect(Schema::getIndexes('applicants'));
        $emailUnique = $indexes->first(function ($idx) {
            $cols = $idx['columns'] ?? [];
            return ($idx['unique'] ?? false)
                && count($cols) === 1
                && ($cols[0] ?? null) === 'email';
        });

        if ($emailUnique) {
            Schema::table('applicants', function (Blueprint $table) use ($emailUnique) {
                $table->dropUnique($emailUnique['name']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
