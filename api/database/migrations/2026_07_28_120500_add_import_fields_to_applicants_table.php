<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            if (! Schema::hasColumn('applicants', 'preferred_exam_time')) {
                $table->string('preferred_exam_time', 40)->nullable()->after('course_applied');
            }
            if (! Schema::hasColumn('applicants', 'application_date')) {
                $table->date('application_date')->nullable()->after('preferred_exam_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $cols = collect(['preferred_exam_time', 'application_date'])
                ->filter(fn ($c) => Schema::hasColumn('applicants', $c))
                ->all();
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
