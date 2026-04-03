<?php

namespace App\Console\Commands;

use App\Services\Maintenance\RepairChannelBotTokenPresenceAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepairChannelBotTokenPresenceCommand extends Command
{
    protected $signature = 'channels:repair-token-presence
        {--force : Persist repaired bot_token_present values instead of running in dry-run mode}';

    protected $description = 'Repair denormalized channels.bot_token_present values from encrypted credentials.';

    public function __construct(
        private readonly RepairChannelBotTokenPresenceAction $repairChannelBotTokenPresenceAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $result = $this->repairChannelBotTokenPresenceAction->handle($force);

        $this->line($result->dryRun
            ? 'Channel bot token presence repair dry-run completed.'
            : 'Channel bot token presence repair completed.');

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['total_channels', (string) $result->totalChannels],
                ['matching_channels', (string) $result->matchingChannels],
                ['mismatched_channels', (string) $result->mismatchedChannels],
                ['updated_channels', (string) $result->updatedChannels],
            ],
        );

        Log::info(
            $result->dryRun
                ? 'channels.bot_token_presence_repair_dry_run'
                : 'channels.bot_token_presence_repaired',
            array_merge(
                $result->toLogContext(),
                [
                    'environment' => app()->environment(),
                    'force' => $force,
                    'driver' => DB::getDriverName(),
                    'repaired_at' => now()->toIso8601String(),
                ],
            ),
        );

        return self::SUCCESS;
    }
}
