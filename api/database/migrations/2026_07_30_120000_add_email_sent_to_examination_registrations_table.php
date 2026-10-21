<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('examination_registrations', 'email_sent')) {
            Schema::table('examination_registrations', function (Blueprint $table) {
                $table->boolean('email_sent')->default(false)->after('email_sent_at');
            });
        }

        // Backfill from existing delivery state.
        if (Schema::hasColumn('examination_registrations', 'email_status')) {
            DB::table('examination_registrations')
                ->where('email_status', 'sent')
                ->update(['email_sent' => true]);
        } elseif (Schema::hasColumn('examination_registrations', 'email_sent_at')) {
            DB::table('examination_registrations')
                ->whereNotNull('email_sent_at')
                ->update(['email_sent' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('examination_registrations', 'email_sent')) {
            Schema::table('examination_registrations', function (Blueprint $table) {
                $table->dropColumn('email_sent');
            });
        }
    }
};
