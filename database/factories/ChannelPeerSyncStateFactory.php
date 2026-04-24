<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelPeerSyncState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelPeerSyncState>
 */
class ChannelPeerSyncStateFactory extends Factory
{
    protected $model = ChannelPeerSyncState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory()->account(),
            'external_chat_id' => (string) fake()->numberBetween(10000, 99999),
            'peer_key' => fn (array $attributes): string => ChannelPeerSyncState::buildTelegramAccountPeerKey(
                $attributes['channel_id'],
                $attributes['external_chat_id'],
            ),
            'backfill_status' => ChannelPeerSyncState::BACKFILL_STATUS_NOT_STARTED,
            'oldest_imported_message_id' => null,
            'latest_observed_message_id' => null,
            'history_complete_at' => null,
            'last_sync_error' => null,
        ];
    }
}
