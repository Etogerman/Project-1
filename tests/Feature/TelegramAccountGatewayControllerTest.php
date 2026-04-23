<?php

namespace Tests\Feature;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Jobs\SyncContactIdentityAvatarJob;
use App\Models\Channel;
use App\Models\ChannelPeerSyncState;
use App\Models\ChannelRuntimeState;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramAccountGatewayControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_requires_internal_bearer_secret(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        $this->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(channel: $channel),
        )->assertForbidden();
    }

    public function test_gateway_stores_private_live_event_and_updates_read_model_without_bot_dispatch(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel([
            'name' => 'Telegram Account Alpha',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700001',
                externalUserId: 'tg-account-user-1',
                externalMessageId: '900001',
                text: 'Привет из account gateway',
                historySource: 'live',
            ),
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored', true)
            ->assertJsonPath('skipped', false);

        $message = Message::query()->firstOrFail();
        $dialog = Dialog::query()->firstOrFail();
        $peerSyncState = ChannelPeerSyncState::query()->firstOrFail();
        $runtimeState = ChannelRuntimeState::query()->firstOrFail();

        $this->assertSame(Message::DIRECTION_INBOUND, $message->direction);
        $this->assertSame(Message::KIND_INBOUND_USER, $message->message_kind);
        $this->assertSame('telegram_account:'.$channel->id.':700001:900001', $message->provider_event_key);
        $this->assertSame('700001', $message->external_chat_id);
        $this->assertSame('900001', $message->external_message_id);
        $this->assertSame('Привет из account gateway', $message->text);
        $this->assertSame($dialog->id, $message->dialog_id);
        $this->assertSame($channel->id, $peerSyncState->channel_id);
        $this->assertSame('telegram_account:'.$channel->id.':700001', $peerSyncState->peer_key);
        $this->assertSame('700001', $peerSyncState->external_chat_id);
        $this->assertSame(ChannelPeerSyncState::BACKFILL_STATUS_NOT_STARTED, $peerSyncState->backfill_status);
        $this->assertNull($peerSyncState->oldest_imported_message_id);
        $this->assertSame('900001', $peerSyncState->latest_observed_message_id);
        $this->assertSame(ChannelRuntimeState::AUTH_STATUS_AUTHORIZED, $runtimeState->auth_status);
        $this->assertSame(ChannelRuntimeState::AUTHORIZATION_STATE_READY, $runtimeState->authorization_state);
        $this->assertSame(ChannelRuntimeState::SYNC_STATUS_LIVE, $runtimeState->sync_status);
        $this->assertNotNull($runtimeState->last_gateway_heartbeat_at);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionQuestionJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(SyncContactIdentityAvatarJob::class);
    }

    public function test_gateway_skips_non_private_peer_without_materializing_message_or_peer_sync_state(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                peerType: 'group',
                externalChatId: '700002',
                externalUserId: 'tg-group-user-2',
                externalMessageId: '900002',
                text: 'Сообщение из group',
            ),
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored', false)
            ->assertJsonPath('skipped', true);

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('dialogs', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('channel_peer_sync_states', 0);
        $this->assertDatabaseHas('channel_runtime_states', [
            'channel_id' => $channel->id,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'telegram_account_gateway.non_private_peer_skipped',
            'level' => 'info',
        ]);
    }

    public function test_gateway_deduplicates_by_message_key_and_updates_existing_peer_sync_state(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();
        $payload = $this->payload(
            channel: $channel,
            externalChatId: '700003',
            externalUserId: 'tg-account-user-3',
            externalMessageId: '900003',
            text: 'Дедупликация события',
        );

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(route('internal.telegram-account.messages.handle', ['channel' => $channel]), $payload)
            ->assertOk();

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(route('internal.telegram-account.messages.handle', ['channel' => $channel]), $payload)
            ->assertOk();

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('dialogs', 1);
        $this->assertDatabaseCount('channel_peer_sync_states', 1);
    }

    public function test_gateway_tracks_backfill_cursor_and_switches_runtime_state_to_live_after_live_event(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700004',
                externalUserId: 'tg-account-user-4',
                externalMessageId: '900001',
                text: 'Старое backfill сообщение',
                historySource: 'backfill',
            ),
        )->assertOk();

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700004',
                externalUserId: 'tg-account-user-4',
                externalMessageId: '900010',
                text: 'Новое live сообщение',
                historySource: 'live',
            ),
        )->assertOk();

        $peerSyncState = ChannelPeerSyncState::query()->firstOrFail();
        $runtimeState = ChannelRuntimeState::query()->firstOrFail();

        $this->assertSame(ChannelPeerSyncState::BACKFILL_STATUS_IN_PROGRESS, $peerSyncState->backfill_status);
        $this->assertSame('900001', $peerSyncState->oldest_imported_message_id);
        $this->assertSame('900010', $peerSyncState->latest_observed_message_id);
        $this->assertSame(ChannelRuntimeState::SYNC_STATUS_LIVE, $runtimeState->sync_status);
        $this->assertNotNull($runtimeState->last_sync_started_at);
        $this->assertNotNull($runtimeState->last_sync_completed_at);
    }

    private function createTelegramAccountChannel(array $attributes = []): Channel
    {
        return Channel::factory()->account()->create(array_merge([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Channel $channel,
        string $peerType = 'private',
        string $externalChatId = '700000',
        string $externalUserId = 'tg-account-user',
        string $externalMessageId = '900000',
        ?string $text = 'Привет',
        array $media = [],
        string $historySource = 'live',
    ): array {
        return [
            'schema_version' => 'v1',
            'gateway_event_id' => 'gateway-'.$externalMessageId,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'peer_type' => $peerType,
            'peer_key' => 'telegram_account:'.$channel->id.':'.$externalChatId,
            'message_key' => 'telegram_account:'.$channel->id.':'.$externalChatId.':'.$externalMessageId,
            'external_chat_id' => $externalChatId,
            'external_user_id' => $externalUserId,
            'external_message_id' => $externalMessageId,
            'external_username' => 'telegram_account_user',
            'contact_name' => 'Telegram Account Клиент',
            'message_kind' => $media === [] ? 'text' : 'media',
            'text' => $text,
            'media' => $media,
            'raw_payload' => [
                'provider' => 'telegram_account_gateway',
            ],
            'occurred_at' => '2026-04-23T12:00:00+03:00',
            'history_source' => $historySource,
        ];
    }
}
