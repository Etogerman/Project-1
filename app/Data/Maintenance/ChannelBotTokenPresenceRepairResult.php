<?php

namespace App\Data\Maintenance;

final readonly class ChannelBotTokenPresenceRepairResult
{
    public function __construct(
        public bool $dryRun,
        public int $totalChannels,
        public int $matchingChannels,
        public int $mismatchedChannels,
        public int $updatedChannels,
    ) {}

    /**
     * @return array<string, int|bool>
     */
    public function toLogContext(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'total_channels' => $this->totalChannels,
            'matching_channels' => $this->matchingChannels,
            'mismatched_channels' => $this->mismatchedChannels,
            'updated_channels' => $this->updatedChannels,
        ];
    }
}
