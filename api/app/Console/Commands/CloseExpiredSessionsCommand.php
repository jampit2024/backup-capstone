<?php

namespace App\Console\Commands;

use App\Enums\ExamSessionStatus;
use App\Models\ExamSession;
use Illuminate\Console\Command;

class CloseExpiredSessionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sessions:close-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close open examination sessions that have passed their expiration timestamp';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = ExamSession::query()
            ->whereIn('status', [ExamSessionStatus::Open, ExamSessionStatus::LobbyOpen])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => ExamSessionStatus::Closed,
                'ended_at' => now(),
            ]);

        $this->info("Closed {$count} expired examination session(s).");

        return self::SUCCESS;
    }
}
