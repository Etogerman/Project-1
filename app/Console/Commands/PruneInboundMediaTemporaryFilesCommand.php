<?php

namespace App\Console\Commands;

use App\Services\Messages\PruneInboundMediaTemporaryFilesAction;
use Illuminate\Console\Command;

class PruneInboundMediaTemporaryFilesCommand extends Command
{
    protected $signature = 'media:prune-temporary-files {--limit=100 : Maximum temporary files to inspect}';

    protected $description = 'Delete abandoned inbound media temporary files without touching active downloads.';

    public function __construct(
        private readonly PruneInboundMediaTemporaryFilesAction $pruner,
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
