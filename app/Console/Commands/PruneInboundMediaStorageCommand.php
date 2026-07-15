<?php

namespace App\Console\Commands;

use App\Services\Messages\PruneInboundMediaStorageAction;
use Illuminate\Console\Command;

class PruneInboundMediaStorageCommand extends Command
{
    protected $signature = 'media:prune-storage {--limit=100 : Maximum retained files to inspect}';

    protected $description = 'Delete inbound media files beyond retention and release used storage quota.';

    public function __construct(
        private readonly PruneInboundMediaStorageAction $pruner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $stats = $this->pruner->handle((int) $this->option('limit'));

        $this->table(
            ['Result', 'Count'],
            collect($stats)
                ->map(fn (int $count, string $result): array => [$result, (string) $count])
                ->values()
                ->all(),
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
