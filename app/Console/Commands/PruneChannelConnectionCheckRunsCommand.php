<?php

namespace App\Console\Commands;

use App\Models\ChannelConnectionCheckRun;
use Illuminate\Console\Command;

class PruneChannelConnectionCheckRunsCommand extends Command
{
    protected $signature = 'channels:prune-connection-check-runs
        {--days=30 : Сколько дней хранить heartbeat-запуски проверки каналов}';

    protected $description = 'Prune old channel connection check heartbeat runs.';

    public function handle(): int
    {
        $retentionDays = min(max((int) $this->option('days'), 1), 365);
        $threshold = now()->subDays($retentionDays);

        $deletedCount = ChannelConnectionCheckRun::query()
            ->where('created_at', '<', $threshold)
            ->delete();

        $this->info("Удалено heartbeat-запусков проверки каналов: {$deletedCount}.");

        return self::SUCCESS;
    }
}
