<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $drop = collect(['address', 'contact_number', 'birth_date', 'gender'])
            ->filter(fn (string $col) => Schema::hasColumn('applicants', $col))
            ->values()
            ->all();

        if ($drop === []) {
            return;
        }

        // SQLite: drop one column at a time.
        foreach ($drop as $column) {
            Schema::table('applicants', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            if (! Schema::hasColumn('applicants', 'address')) {
                $table->string('address')->nullable()->after('name');
            }
            if (! Schema::hasColumn('applicants', 'contact_number')) {
                $table->string('contact_number', 40)->nullable()->after('address');
            }
            if (! Schema::hasColumn('applicants', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('contact_number');
            }
            if (! Schema::hasColumn('applicants', 'gender')) {
                $table->string('gender', 20)->nullable()->after('birth_date');
            }
        });
    }
};
