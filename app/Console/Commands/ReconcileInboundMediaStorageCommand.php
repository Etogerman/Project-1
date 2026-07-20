<?php

namespace App\Console\Commands;

use App\Services\Messages\ReconcileInboundMediaStorageAction;
use Illuminate\Console\Command;

class ReconcileInboundMediaStorageCommand extends Command
{
    protected $signature = 'media:reconcile-storage
        {--repair : Repair safe storage and ledger drift}
        {--limit=5000 : Maximum downloaded attachments to inspect}
        {--orphan-limit=5000 : Maximum stored objects to inspect}';

    protected $description = 'Compare inbound media attachments, stable objects, and storage quota ledgers.';

    public function __construct(
        private readonly ReconcileInboundMediaStorageAction $reconciler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $repair = (bool) $this->option('repair');
        $stats = $this->reconciler->handle(
            repair: $repair,
            attachmentLimit: (int) $this->option('limit'),
            orphanLimit: (int) $this->option('orphan-limit'),
        );

        $this->line($repair
            ? 'Inbound media storage reconciliation repair.'
            : 'Inbound media storage reconciliation dry-run.');
        $this->table(
            ['Result', 'Count'],
            collect($stats)
                ->map(fn (int $count, string $result): array => [$result, (string) $count])
                ->values()
                ->all(),
        );

        return $stats['failures'] > 0 || $stats['remaining_drift_rows'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
