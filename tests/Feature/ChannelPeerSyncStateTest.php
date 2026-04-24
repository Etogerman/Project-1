<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelPeerSyncState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChannelPeerSyncStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_peer_sync_state_schema_matches_slice_three_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('channel_peer_sync_states', [
            'channel_id',
            'peer_key',
            'external_chat_id',
            'backfill_status',
            'oldest_imported_message_id',
            'latest_observed_message_id',
            'history_complete_at',
            'last_sync_error',
        ]));
    }

    public function test_channel_peer_sync_state_persists_per_peer_backfill_state(): void
    {
        $channel = Channel::factory()->account()->create();
        $peerKey = ChannelPeerSyncState::buildTelegramAccountPeerKey($channel->id, '700001');

        $peerState = ChannelPeerSyncState::query()->create([
            'channel_id' => $channel->id,
            'peer_key' => $peerKey,
            'external_chat_id' => '700001',
            'backfill_status' => ChannelPeerSyncState::BACKFILL_STATUS_IN_PROGRESS,
            'oldest_imported_message_id' => '100',
            'latest_observed_message_id' => '900',
            'history_complete_at' => now()->subMinute(),
            'last_sync_error' => null,
        ]);

        $this->assertTrue($peerState->channel->is($channel));
        $this->assertSame($peerKey, $peerState->peer_key);
        $this->assertSame(ChannelPeerSyncState::BACKFILL_STATUS_IN_PROGRESS, $peerState->backfill_status);
        $this->assertSame('100', $peerState->oldest_imported_message_id);
        $this->assertSame('900', $peerState->latest_observed_message_id);
        $this->assertCount(1, $channel->peerSyncStates);
        $this->assertTrue($channel->peerSyncStates->first()->is($peerState));
    }

    public function test_factory_generates_canonical_peer_key_for_channel_and_chat_id(): void
    {
        $peerState = ChannelPeerSyncState::factory()->create();

        $this->assertSame(
            ChannelPeerSyncState::buildTelegramAccountPeerKey($peerState->channel_id, $peerState->external_chat_id),
            $peerState->peer_key,
        );
    }

    public function test_channel_peer_sync_state_is_unique_per_channel_and_peer_key(): void
    {
        $channel = Channel::factory()->account()->create();
        $peerKey = ChannelPeerSyncState::buildTelegramAccountPeerKey($channel->id, '700001');

        ChannelPeerSyncState::factory()->create([
            'channel_id' => $channel->id,
            'peer_key' => $peerKey,
            'external_chat_id' => '700001',
        ]);

        $this->expectException(QueryException::class);

        ChannelPeerSyncState::factory()->create([
            'channel_id' => $channel->id,
            'peer_key' => $peerKey,
            'external_chat_id' => '700002',
        ]);
    }

    public function test_channel_peer_sync_state_is_unique_per_channel_and_external_chat_id(): void
    {
        $channel = Channel::factory()->account()->create();

        ChannelPeerSyncState::factory()->create([
            'channel_id' => $channel->id,
            'peer_key' => ChannelPeerSyncState::buildTelegramAccountPeerKey($channel->id, '700001'),
            'external_chat_id' => '700001',
        ]);

        $this->expectException(QueryException::class);

        ChannelPeerSyncState::factory()->create([
            'channel_id' => $channel->id,
            'peer_key' => ChannelPeerSyncState::buildTelegramAccountPeerKey($channel->id, '700099'),
            'external_chat_id' => '700001',
        ]);
    }
}
