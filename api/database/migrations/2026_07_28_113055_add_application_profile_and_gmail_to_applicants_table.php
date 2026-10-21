<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Profile fields from third-party list: name, program, date, time, gmail, status/score.
        // Do not re-add address / contact / birth / gender here.
        Schema::table('applicants', function (Blueprint $table) {
            if (! Schema::hasColumn('applicants', 'gmail')) {
                $table->string('gmail')->nullable()->after('email');
            }
            if (! Schema::hasColumn('applicants', 'gmail_submitted_at')) {
                $table->timestamp('gmail_submitted_at')->nullable()->after('gmail');
            }
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE applicants MODIFY email VARCHAR(255) NULL');
        }

        DB::table('applicants')
            ->whereNull('gmail')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('applicants')->where('id', $row->id)->update(['gmail' => $row->email]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $cols = collect(['gmail', 'gmail_submitted_at'])
                ->filter(fn ($c) => Schema::hasColumn('applicants', $c))
                ->all();
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
