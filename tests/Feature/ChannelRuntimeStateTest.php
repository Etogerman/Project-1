<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelRuntimeState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChannelRuntimeStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_connection_type_is_supported_at_model_level(): void
    {
        $channel = Channel::factory()->account()->create();

        $this->assertSame(Channel::CONNECTION_TYPE_ACCOUNT, $channel->connection_type);
        $this->assertContains(Channel::CONNECTION_TYPE_ACCOUNT, Channel::supportedConnectionTypes());
        $this->assertArrayNotHasKey(Channel::CONNECTION_TYPE_ACCOUNT, Channel::connectionTypeOptions());
    }

    public function test_channel_runtime_state_persists_account_runtime_visibility_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('channel_runtime_states', [
            'channel_id',
            'auth_status',
            'authorization_state',
            'sync_status',
            'last_gateway_heartbeat_at',
            'last_sync_started_at',
            'last_sync_completed_at',
            'last_error_at',
            'last_error_message',
            'runtime_payload',
        ]));

        $channel = Channel::factory()->account()->create();
        $runtimeState = ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'last_gateway_heartbeat_at' => now()->subMinute(),
            'last_sync_started_at' => now()->subMinutes(5),
            'last_sync_completed_at' => now()->subMinute(),
            'runtime_payload' => [
                'session' => 'runtime-1',
            ],
        ]);

        $this->assertTrue($runtimeState->channel->is($channel));
        $this->assertSame(ChannelRuntimeState::AUTH_STATUS_AUTHORIZED, $runtimeState->auth_status);
        $this->assertSame(ChannelRuntimeState::AUTHORIZATION_STATE_READY, $runtimeState->authorization_state);
        $this->assertSame(ChannelRuntimeState::SYNC_STATUS_LIVE, $runtimeState->sync_status);
        $this->assertSame(['session' => 'runtime-1'], $runtimeState->runtime_payload);
        $this->assertTrue($channel->runtimeState->is($runtimeState));
    }
}
