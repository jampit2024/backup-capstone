<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('reference_number', 60)->nullable()->unique()->after('applicant_code');
        });

        // Backfill reference numbers for existing applicants.
        DB::table('applicants')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (! empty($row->reference_number)) {
                        continue;
                    }
                    DB::table('applicants')->where('id', $row->id)->update([
                        'reference_number' => 'REF-'.preg_replace('/\D+/', '', (string) $row->applicant_code).'-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropColumn('reference_number');
        });
    }
};
