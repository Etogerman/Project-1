<?php

namespace App\Console\Commands;

use App\Services\Messages\ReapStaleInboundMediaDownloadsAction;
use Illuminate\Console\Command;

class ReapStaleInboundMediaDownloadsCommand extends Command
{
    protected $signature = 'media:reap-stale-downloads {--limit=100 : Maximum stale downloads to inspect}';

    protected $description = 'Release stale inbound media download leases and retry eligible attachments.';

    public function __construct(
        private readonly ReapStaleInboundMediaDownloadsAction $reaper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $stats = $this->reaper->handle((int) $this->option('limit'));

        $this->table(
            ['Result', 'Count'],
            collect($stats)
                ->map(fn (int $count, string $result): array => [$result, (string) $count])
                ->values()
                ->all(),
        );

        return $stats['cleanup_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
