<?php

namespace App\Console\Commands;

use App\Services\Maintenance\PurgeRuntimeDataAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeRuntimeDataCommand extends Command
{
    protected $signature = 'maintenance:purge-runtime-data
        {--force : Delete runtime data instead of running in dry-run mode}
        {--confirm-production-purge : Confirm destructive purge when APP_ENV is production}
        {--include-sessions : Also purge session rows}';

    protected $description = 'Purge non-config runtime data while preserving users, channels, and auto reply rules.';

    public function __construct(
        private readonly PurgeRuntimeDataAction $purgeRuntimeDataAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $includeSessions = (bool) $this->option('include-sessions');
        $force = (bool) $this->option('force');
        $confirmProductionPurge = (bool) $this->option('confirm-production-purge');

        if ($force && app()->environment('production') && ! $confirmProductionPurge) {
            $this->error('Destructive purge in production requires both --force and --confirm-production-purge.');

            return self::FAILURE;
        }

        $result = $this->purgeRuntimeDataAction->handle($force, $includeSessions);

        $this->line($result->dryRun
            ? 'Runtime data purge dry-run completed.'
            : 'Runtime data purge completed.');

        $this->newLine();
        $this->info('Runtime tables to purge: '.implode(', ', $result->purgedTables));
        $this->info('Preserved tables: '.implode(', ', $result->preservedTables));

        $this->newLine();
        $this->table(
            ['Table', 'Before', 'After'],
            collect($result->beforeCounts)
                ->map(fn (int $before, string $table): array => [
                    $table,
                    (string) $before,
                    (string) ($result->afterCounts[$table] ?? 0),
                ])
                ->values()
                ->all(),
        );

        Log::info(
            $result->dryRun
                ? 'maintenance.runtime_data_purge_dry_run'
                : 'maintenance.runtime_data_purged',
            array_merge(
                $result->toLogContext(),
                [
                    'environment' => app()->environment(),
                    'force' => $force,
                    'production_guard_confirmed' => $force && app()->environment('production') && $confirmProductionPurge,
                    'driver' => DB::getDriverName(),
                    'purged_at' => now()->toIso8601String(),
                ],
            ),
        );

        return self::SUCCESS;
    }
}
