<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add agreed_at column to record when an examinee accepted the Terms & Agreement
     * before entering the waiting lobby. Nullable — null means not yet agreed.
     */
    public function up(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            //
            $table->timestamp('agreed_at')->nullable()->after('last_heartbeat_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('examination_registrations', function (Blueprint $table) {
            //
            $table->dropColumn('agreed_at');
        });
    }
};
