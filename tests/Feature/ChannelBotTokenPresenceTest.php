<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelRuntimeState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChannelBotTokenPresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_marks_bot_token_present_when_created_with_token(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $this->assertTrue($channel->fresh()->bot_token_present);
        $this->assertTrue($channel->fresh()->hasBotTokenConfigured());
    }

    public function test_channel_marks_bot_token_present_false_when_token_is_removed(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $channel->update([
            'credentials' => [],
        ]);

        $this->assertFalse($channel->fresh()->bot_token_present);
        $this->assertFalse($channel->fresh()->hasBotTokenConfigured());
    }

    public function test_channel_put_credential_syncs_bot_token_presence(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [],
        ]);

        $channel->putCredential(Channel::CREDENTIAL_TOKEN, 'fresh-token')->save();

        $this->assertTrue($channel->fresh()->bot_token_present);
        $this->assertTrue($channel->fresh()->hasBotTokenConfigured());
    }

    public function test_successful_webhook_receipt_clears_stale_operational_error(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'webhook-secret',
            ],
            'last_webhook_received_at' => null,
            'last_reply_sent_at' => null,
            'last_error_at' => now(),
            'last_error_message' => 'Старая ошибка',
        ]);

        $channel->markWebhookReceived();

        $channel = $channel->fresh();

        $this->assertNull($channel->last_error_at);
        $this->assertNull($channel->last_error_message);
        $this->assertSame('Webhook', $channel->getHealthStatusLabel());
        $this->assertTrue($channel->isReadyForConstructorAutoReplies());
    }

    public function test_ready_account_channel_is_ready_for_constructor_auto_replies_when_gateway_supports_outgoing_replies(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'last_gateway_heartbeat_at' => now(),
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => true,
                ],
            ],
        ]);

        $channel = $channel->fresh('runtimeState');

        $this->assertSame('Работает', $channel->getHealthStatusLabel());
        $this->assertSame('success', $channel->getHealthStatusColor());
        $this->assertTrue($channel->isReadyForConstructorAutoReplies());
    }

    public function test_stale_account_gateway_heartbeat_is_not_ready_for_constructor_auto_replies(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'last_gateway_heartbeat_at' => now()->subMinutes(Channel::GATEWAY_HEARTBEAT_FRESH_FOR_MINUTES + 1),
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => true,
                ],
            ],
        ]);

        $channel = $channel->fresh('runtimeState');

        $this->assertSame(Channel::CONNECTION_ERROR_GATEWAY_STALE, $channel->getHealthStatusLabel());
        $this->assertSame('danger', $channel->getHealthStatusColor());
        $this->assertFalse($channel->isReadyForConstructorAutoReplies());
    }

    public function test_bot_channel_with_old_reply_but_failed_connection_check_is_not_ready_for_constructor_auto_replies(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'webhook-secret',
            ],
            'connection_status' => Channel::CONNECTION_STATUS_NOT_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_NOT_INSTALLED,
            'connection_checked_at' => now(),
            'connection_error_message' => 'Webhook установлен не на эту админку',
            'last_reply_sent_at' => now()->subMinute(),
        ]);

        $channel = $channel->fresh();

        $this->assertSame('Не подключен', $channel->getHealthStatusLabel());
        $this->assertSame('danger', $channel->getHealthStatusColor());
        $this->assertFalse($channel->isReadyForConstructorAutoReplies());
    }

    public function test_bot_channel_with_webhook_from_previous_app_url_is_not_ready_for_constructor_auto_replies(): void
    {
        config()->set('app.url', 'https://current-admin.example');

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'webhook-secret',
            ],
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now(),
            'expected_webhook_url' => 'https://previous-admin.example/webhooks/telegram/999',
            'provider_webhook_url' => 'https://previous-admin.example/webhooks/telegram/999',
            'last_reply_sent_at' => now()->subMinute(),
        ]);

        $channel = $channel->fresh();

        $this->assertSame('Не подключен', $channel->getHealthStatusLabel());
        $this->assertFalse($channel->isReadyForConstructorAutoReplies());
    }

    public function test_account_channel_without_gateway_outgoing_replies_is_not_ready_for_constructor_auto_replies(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => false,
                ],
            ],
        ]);

        $this->assertFalse($channel->fresh('runtimeState')->isReadyForConstructorAutoReplies());
    }

    public function test_bot_channel_without_token_is_not_ready_for_constructor_auto_replies(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [
                'webhook_secret' => 'webhook-secret',
            ],
            'last_webhook_received_at' => now(),
            'last_error_at' => null,
        ]);

        $channel = $channel->fresh();

        $this->assertFalse($channel->hasBotTokenConfigured());
        $this->assertFalse($channel->isReadyForConstructorAutoReplies());
    }

    public function test_operational_state_can_be_saved_when_credentials_are_unreadable(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        DB::table('channels')
            ->where('id', $channel->id)
            ->update(['credentials' => 'broken-encrypted-value']);

        $channel->fresh()->markError('Ошибка проверки');

        $channel = $channel->fresh();

        $this->assertSame('Ошибка проверки', $channel->last_error_message);

        $channel->clearOperationalError();

        $channel = $channel->fresh();

        $this->assertNull($channel->last_error_at);
        $this->assertNull($channel->last_error_message);
        $this->assertSame('Ошибка настроек', $channel->getHealthStatusLabel());
    }
}
