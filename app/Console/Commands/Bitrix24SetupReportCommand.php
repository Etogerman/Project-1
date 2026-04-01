<?php

namespace App\Console\Commands;

use App\Services\Bitrix24\BuildBitrix24SetupReportAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Bitrix24SetupReportCommand extends Command
{
    protected $signature = 'bitrix24:setup-report';

    protected $description = 'Validate the frozen Bitrix24 setup contract before implementation starts.';

    public function __construct(
        private readonly BuildBitrix24SetupReportAction $buildBitrix24SetupReportAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->buildBitrix24SetupReportAction->handle();

        $this->line('Bitrix24 setup readiness check completed.');
        $this->newLine();

        $this->table(
            ['Item', 'Required', 'Status', 'Value', 'Notes'],
            $report->checkTableRows(),
        );

        $this->newLine();
        $this->table(
            ['Group', 'Item', 'Value'],
            $report->frozenValueRows(),
        );

        if ($report->hasBlockingIssues()) {
            $this->newLine();
            $this->error('Bitrix24 setup is not ready for implementation. Resolve all missing required items first.');

            Log::warning('bitrix24.setup_report_generated', [
                'ready' => false,
                'blocking_checks' => count($report->blockingChecks()),
                'warning_checks' => count($report->warningChecks()),
                'generated_at' => now()->toIso8601String(),
            ]);

            return self::FAILURE;
        }

        $this->newLine();

        if ($report->warningChecks() !== []) {
            $this->warn('Bitrix24 setup is usable, but there are warnings to review before the next stage.');
        } else {
            $this->info('Bitrix24 setup is ready for the integration foundation stage.');
        }

        Log::info('bitrix24.setup_report_generated', [
            'ready' => true,
            'blocking_checks' => 0,
            'warning_checks' => count($report->warningChecks()),
            'generated_at' => now()->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
