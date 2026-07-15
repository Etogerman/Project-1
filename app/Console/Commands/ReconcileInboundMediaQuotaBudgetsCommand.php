<?php

namespace App\Console\Commands;

use App\Services\Messages\ReconcileInboundMediaQuotaBudgetsAction;
use Illuminate\Console\Command;

class ReconcileInboundMediaQuotaBudgetsCommand extends Command
{
    protected $signature = 'media:reconcile-quota {--repair : Rewrite aggregate budgets from quota ledgers}';

    protected $description = 'Compare inbound media quota budgets with storage and traffic ledgers.';

    public function __construct(
        private readonly ReconcileInboundMediaQuotaBudgetsAction $reconciler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $repair = (bool) $this->option('repair');
        $stats = $this->reconciler->handle($repair);

        $this->line($repair ? 'Media quota reconciliation repair.' : 'Media quota reconciliation dry-run.');
        $this->table(
            ['Result', 'Count'],
            collect($stats)
                ->map(fn (int $count, string $result): array => [$result, (string) $count])
                ->values()
                ->all(),
        );

        return $stats['remaining_drift_rows'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
