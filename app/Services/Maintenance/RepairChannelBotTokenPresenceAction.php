<?php

namespace App\Services\Maintenance;

use App\Data\Maintenance\ChannelBotTokenPresenceRepairResult;
use App\Models\Channel;

class RepairChannelBotTokenPresenceAction
{
    public function handle(bool $force = false): ChannelBotTokenPresenceRepairResult
    {
        $totalChannels = 0;
        $matchingChannels = 0;
        $mismatchedChannels = 0;
        $updatedChannels = 0;

        Channel::query()
            ->orderBy('id')
            ->chunkById(100, function ($channels) use (
                $force,
                &$totalChannels,
                &$matchingChannels,
                &$mismatchedChannels,
                &$updatedChannels,
            ): void {
                foreach ($channels as $channel) {
                    $totalChannels++;

                    $expected = filled($channel->getToken());
                    $actual = $channel->hasBotTokenConfigured();

                    if ($expected === $actual) {
                        $matchingChannels++;

                        continue;
                    }

                    $mismatchedChannels++;

                    if (! $force) {
                        continue;
                    }

                    $channel
                        ->forceFill(['bot_token_present' => $expected])
                        ->saveQuietly();

                    $updatedChannels++;
                }
            });

        return new ChannelBotTokenPresenceRepairResult(
            dryRun: ! $force,
            totalChannels: $totalChannels,
            matchingChannels: $matchingChannels,
            mismatchedChannels: $mismatchedChannels,
            updatedChannels: $updatedChannels,
        );
    }
}
