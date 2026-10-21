<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PARTICIPATION_TOKEN_UNIQUE = 'examination_registrations_participation_token_unique';

    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_bank_id')->constrained('question_banks')->restrictOnDelete();
            $table->foreignId('proctor_id')->constrained('users')->cascadeOnDelete();
            $table->string('exam_code', 20)->unique();
            $table->string('status', 30)->default('lobby_open'); // lobby_open|in_progress|ended
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['examination_schedule_id', 'status']);
            $table->index('proctor_id');
        });

        Schema::table('examination_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('examination_registrations', 'exam_session_id')) {
                $table->foreignId('exam_session_id')->nullable()->after('examination_schedule_id')
                    ->constrained('exam_sessions')->nullOnDelete();
            }
            if (! Schema::hasColumn('examination_registrations', 'participation_token')) {
                $table->uuid('participation_token')->nullable()->after('exam_session_id');
            }
            if (! Schema::hasColumn('examination_registrations', 'lobby_status')) {
                $table->string('lobby_status', 30)->nullable()->after('participation_token');
            }
            if (! Schema::hasColumn('examination_registrations', 'joined_lobby_at')) {
                $table->timestamp('joined_lobby_at')->nullable()->after('lobby_status');
            }
            if (! Schema::hasColumn('examination_registrations', 'violation_count')) {
                $table->unsignedSmallInteger('violation_count')->default(0)->after('joined_lobby_at');
            }
        });

        $this->ensureParticipationTokenUnique();
    }

    public function down(): void
    {
        $this->dropParticipationTokenUnique();

        foreach (['violation_count', 'joined_lobby_at', 'lobby_status', 'participation_token'] as $col) {
            if (Schema::hasColumn('examination_registrations', $col)) {
                Schema::table('examination_registrations', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }

        if (Schema::hasColumn('examination_registrations', 'exam_session_id')) {
            Schema::table('examination_registrations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('exam_session_id');
            });
        }

        Schema::dropIfExists('exam_sessions');
    }

    private function ensureParticipationTokenUnique(): void
    {
        if (! Schema::hasColumn('examination_registrations', 'participation_token')) {
            return;
        }

        $hasIndex = collect(Schema::getIndexes('examination_registrations'))
            ->contains(fn ($idx) => ($idx['name'] ?? '') === self::PARTICIPATION_TOKEN_UNIQUE);

        if (! $hasIndex) {
            Schema::table('examination_registrations', function (Blueprint $table) {
                $table->unique('participation_token', self::PARTICIPATION_TOKEN_UNIQUE);
            });
        }
    }

    private function dropParticipationTokenUnique(): void
    {
        if (! Schema::hasTable('examination_registrations')) {
            return;
        }

        $hasIndex = collect(Schema::getIndexes('examination_registrations'))
            ->contains(fn ($idx) => ($idx['name'] ?? '') === self::PARTICIPATION_TOKEN_UNIQUE);

        if ($hasIndex) {
            Schema::table('examination_registrations', function (Blueprint $table) {
                $table->dropUnique(self::PARTICIPATION_TOKEN_UNIQUE);
            });
        }
    }
};
