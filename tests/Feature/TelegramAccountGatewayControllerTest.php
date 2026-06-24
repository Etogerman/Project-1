<?php

namespace Tests\Feature;

use App\Data\TelegramAccount\TelegramAccountGatewayDiagnosticsData;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Jobs\SyncContactIdentityAvatarJob;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\ChannelPeerSyncState;
use App\Models\ChannelRuntimeState;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioV3OutboundMessage;
use App\Models\ScenarioVersion;
use App\Models\TelegramAccountOutgoingMessage;
use App\Models\User;
use App\Services\Bots\SendManualDialogReplyAction;
use App\Services\Scenarios\ScenarioRegistry;
use App\Services\TelegramAccount\ResolveTelegramAccountGatewayDiagnosticsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_gateway_internal_endpoints_are_rate_limited(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');
        config()->set('bots.telegram_account.gateway_rate_limit_per_minute', 1);

        $channel = $this->createTelegramAccountChannel();
        $headers = ['Authorization' => 'Bearer gateway-secret'];
        $payload = $this->runtimeStatePayload(channel: $channel);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.240'])
            ->withHeaders($headers)
            ->postJson(route('internal.telegram-account.runtime-state.handle', ['channel' => $channel]), $payload)
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.240'])
            ->withHeaders($headers)
            ->postJson(route('internal.telegram-account.runtime-state.handle', ['channel' => $channel]), $payload)
            ->assertStatus(429);
    }

    public function test_gateway_stores_private_live_event_updates_read_model_and_queues_auto_reply(): void
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

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($message): bool {
            return $job->inboundMessageId === $message->id
                && $job->queue === ProcessAutoReplyJob::queueName();
        });
        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
        Queue::assertNotPushed(ProcessDataCollectionQuestionJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(SyncContactIdentityAvatarJob::class);
    }

    public function test_gateway_stores_private_backfill_event_without_auto_reply_dispatch(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel([
            'name' => 'Telegram Account Backfill',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700012',
                externalUserId: 'tg-account-user-12',
                externalMessageId: '900012',
                text: 'Старое сообщение из account gateway',
                historySource: 'backfill',
            ),
        )->assertOk()
            ->assertJsonPath('stored', true);

        $this->assertDatabaseCount('messages', 1);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
    }

    public function test_gateway_live_event_skips_legacy_auto_reply_when_cutover_is_enabled(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');
        config()->set('bots.legacy_auto_reply_rules_enabled', false);

        $channel = $this->createTelegramAccountChannel([
            'name' => 'Telegram Account Cutover',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700015',
                externalUserId: 'tg-account-user-15',
                externalMessageId: '900015',
                text: 'Привет после cutover',
                historySource: 'live',
            ),
        )->assertOk()
            ->assertJsonPath('stored', true);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_skipped_legacy_cutover',
            'level' => 'info',
        ]);
    }

    public function test_gateway_live_event_starts_matching_published_v3_scenario_before_auto_reply(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel([
            'name' => 'Telegram Account Scenario',
        ]);
        $scenario = $this->createPublishedV3StartScenario($channel, 'Маркетолог');

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700013',
                externalUserId: 'tg-account-user-13',
                externalMessageId: '900013',
                text: 'Маркетолог',
                historySource: 'live',
            ),
        )->assertOk()
            ->assertJsonPath('stored', true);

        $message = Message::query()->firstOrFail();

        Queue::assertPushed(ProcessScenarioStartJob::class, function (ProcessScenarioStartJob $job) use ($message, $scenario): bool {
            return $job->inboundMessageId === $message->id
                && $job->dialogId === $message->dialog_id
                && $job->scenarioCode === $scenario->code
                && $job->queue === ProcessScenarioStartJob::queueName();
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_queued',
        ]);
    }

    public function test_gateway_v3_scenario_queues_outbound_text_through_telegram_account_gateway(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel([
            'name' => 'Telegram Account Scenario Delivery',
        ]);
        $scenario = $this->createPublishedV3StartScenario($channel, 'Маркетолог');

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700014',
                externalUserId: 'tg-account-user-14',
                externalMessageId: '900014',
                text: 'Маркетолог',
                historySource: 'live',
            ),
        )->assertOk()
            ->assertJsonPath('stored', true);

        $message = Message::query()
            ->where('direction', Message::DIRECTION_INBOUND)
            ->firstOrFail();

        ChannelRuntimeState::query()->updateOrCreate(
            ['channel_id' => $channel->id],
            [
                'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
                'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
                'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
                'last_gateway_heartbeat_at' => now(),
                'runtime_payload' => [
                    'gateway_capabilities' => [
                        'outgoing_replies' => true,
                    ],
                ],
            ],
        );

        app()->call([
            new ProcessScenarioStartJob((int) $message->id, (int) $message->dialog_id, (string) $scenario->code),
            'handle',
        ]);

        $outbound = ScenarioV3OutboundMessage::query()->firstOrFail();
        $runtime = app(ScenarioRegistry::class)->makeRuntimeForVersion(
            (string) $scenario->code,
            (int) $scenario->publishedVersion->id,
        );

        $this->assertNotNull($runtime);

        $runtime->handleV3OutboundMessage((int) $outbound->id);

        $outbound->refresh();
        $sentMessage = Message::query()->findOrFail($outbound->outbound_message_id);
        $outgoing = TelegramAccountOutgoingMessage::query()->firstOrFail();

        $this->assertSame(ScenarioV3OutboundMessage::STATUS_SENT, $outbound->status);
        $this->assertSame(Message::DIRECTION_OUTBOUND, $sentMessage->direction);
        $this->assertSame(Message::KIND_OUTBOUND_SCENARIO_MESSAGE, $sentMessage->message_kind);
        $this->assertSame(Message::SENT_BY_TYPE_SYSTEM, $sentMessage->sent_by_type);
        $this->assertSame('scenario_'.$scenario->code, $sentMessage->sent_by_system_code);
        $this->assertSame('Здравствуйте! Спасибо за отклик.', $sentMessage->text);
        $this->assertSame(Message::TEXT_FORMAT_PLAIN_TEXT, $sentMessage->text_format);
        $this->assertSame($message->id, $sentMessage->reply_to_message_id);
        $this->assertSame('telegram_account_gateway', data_get($sentMessage->raw_payload, 'provider'));
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, data_get($sentMessage->raw_payload, 'delivery_status'));

        $this->assertSame($channel->id, $outgoing->channel_id);
        $this->assertSame($message->dialog_id, $outgoing->dialog_id);
        $this->assertSame($sentMessage->id, $outgoing->message_id);
        $this->assertSame('700014', $outgoing->external_chat_id);
        $this->assertSame('Здравствуйте! Спасибо за отклик.', $outgoing->text);
        $this->assertSame(Message::TEXT_FORMAT_PLAIN_TEXT, $outgoing->text_format);
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, $outgoing->status);
    }

    public function test_gateway_persists_pending_media_download_placeholder_for_account_message(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel([
            'name' => 'Telegram Account Media Placeholder',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700011',
                externalUserId: 'tg-account-media-user-11',
                externalMessageId: '900011',
                text: null,
                media: [
                    ['type' => 'photo'],
                    ['type' => 'document', 'file_name' => 'offer.pdf'],
                ],
                historySource: 'live',
            ),
        )->assertOk()
            ->assertJsonPath('stored', true);

        $message = Message::query()->firstOrFail();
        $media = data_get($message->raw_payload, 'media');

        $this->assertIsArray($media);
        $this->assertCount(2, $media);
        $this->assertSame(Message::MEDIA_DOWNLOAD_STATUS_PENDING, data_get($media, '0.download_status'));
        $this->assertSame(Message::MEDIA_DOWNLOAD_STATUS_PENDING, data_get($media, '1.download_status'));
    }

    public function test_gateway_claims_queued_manual_account_reply(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();
        $dialog = $this->createLiveTelegramAccountDialog($channel, '700021', '900021');
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Ответ из админки через account',
        );

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(route('internal.telegram-account.outgoing-messages.claim', ['channel' => $channel]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('has_message', true)
            ->assertJsonPath('outgoing_message.channel_id', $channel->id)
            ->assertJsonPath('outgoing_message.dialog_id', $dialog->id)
            ->assertJsonPath('outgoing_message.external_chat_id', '700021')
            ->assertJsonPath('outgoing_message.text', 'Ответ из админки через account')
            ->assertJsonPath('outgoing_message.text_format', Message::TEXT_FORMAT_PLAIN_TEXT)
            ->assertJsonPath('outgoing_message.attempts', 1);

        $outgoing = TelegramAccountOutgoingMessage::query()->firstOrFail();

        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PROCESSING, $outgoing->status);
        $this->assertNotNull($outgoing->claimed_at);
    }

    public function test_gateway_marks_stale_processing_account_reply_failed_before_next_claim(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();
        $dialog = $this->createLiveTelegramAccountDialog($channel, '700024', '900024');
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $firstMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Первый ответ завис после claim',
        );

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(route('internal.telegram-account.outgoing-messages.claim', ['channel' => $channel]))
            ->assertOk()
            ->assertJsonPath('has_message', true);

        $staleOutgoing = TelegramAccountOutgoingMessage::query()
            ->where('message_id', $firstMessage->id)
            ->firstOrFail();

        $staleOutgoing->forceFill([
            'claimed_at' => now()->subMinutes(11),
        ])->save();

        $secondMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Второй ответ можно забрать',
        );

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(route('internal.telegram-account.outgoing-messages.claim', ['channel' => $channel]))
            ->assertOk()
            ->assertJsonPath('has_message', true)
            ->assertJsonPath('outgoing_message.message_id', $secondMessage->id);

        $staleOutgoing->refresh();
        $firstMessage->refresh();

        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_FAILED, $staleOutgoing->status);
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_FAILED, data_get($firstMessage->raw_payload, 'delivery_status'));
        $this->assertSame(
            'Gateway did not report delivery result before processing timeout.',
            data_get($firstMessage->raw_payload, 'error_message'),
        );

        $this->assertDatabaseHas('telegram_account_outgoing_messages', [
            'message_id' => $secondMessage->id,
            'status' => TelegramAccountOutgoingMessage::STATUS_PROCESSING,
        ]);
    }

    public function test_gateway_stores_successful_outgoing_account_reply_result(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();
        $dialog = $this->createLiveTelegramAccountDialog($channel, '700022', '900022');
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Подтверждённый ответ',
        );

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(route('internal.telegram-account.outgoing-messages.claim', ['channel' => $channel]))
            ->assertOk()
            ->assertJsonPath('has_message', true);

        $outgoing = TelegramAccountOutgoingMessage::query()->firstOrFail();

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(route('internal.telegram-account.outgoing-messages.result', [
            'channel' => $channel,
            'outgoingMessage' => $outgoing,
        ]), [
            'status' => TelegramAccountOutgoingMessage::STATUS_SENT,
            'external_message_id' => 'tdlib-outgoing-100',
            'raw_payload' => [
                'tdlib_message_id' => 'tdlib-outgoing-100',
            ],
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored', true)
            ->assertJsonPath('status', TelegramAccountOutgoingMessage::STATUS_SENT)
            ->assertJsonPath('message_id', $outboundMessage->id);

        $outgoing->refresh();
        $outboundMessage->refresh();
        $channel->refresh();

        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_SENT, $outgoing->status);
        $this->assertSame('tdlib-outgoing-100', $outgoing->sent_external_message_id);
        $this->assertSame('tdlib-outgoing-100', $outboundMessage->external_message_id);
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_SENT, data_get($outboundMessage->raw_payload, 'delivery_status'));
        $this->assertSame('tdlib-outgoing-100', data_get($outboundMessage->raw_payload, 'external_message_id'));
        $this->assertNotNull($channel->last_reply_sent_at);
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
        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
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

    public function test_gateway_skips_archived_private_backfill_peer_but_keeps_runtime_and_peer_sync_state(): void
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
                externalChatId: '700094',
                externalUserId: 'tg-account-archived-user-94',
                externalMessageId: '900094',
                text: 'Архивный private backfill',
                historySource: 'backfill',
                isArchived: true,
            ),
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored', false)
            ->assertJsonPath('skipped', true);

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('dialogs', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseHas('channel_peer_sync_states', [
            'channel_id' => $channel->id,
            'peer_key' => 'telegram_account:'.$channel->id.':700094',
            'external_chat_id' => '700094',
            'backfill_status' => ChannelPeerSyncState::BACKFILL_STATUS_IN_PROGRESS,
            'oldest_imported_message_id' => '900094',
            'latest_observed_message_id' => '900094',
        ]);
        $this->assertDatabaseHas('channel_runtime_states', [
            'channel_id' => $channel->id,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_BACKFILL_IN_PROGRESS,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'telegram_account_gateway.archived_private_peer_skipped',
            'level' => 'info',
        ]);
    }

    public function test_gateway_runtime_state_endpoint_materializes_auth_flow_before_first_message(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel([
            'name' => 'Telegram Account Runtime Only',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.runtime-state.handle', ['channel' => $channel]),
            $this->runtimeStatePayload(
                channel: $channel,
                authStatus: ChannelRuntimeState::AUTH_STATUS_PENDING,
                authorizationState: ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_QR,
                syncStatus: ChannelRuntimeState::SYNC_STATUS_IDLE,
                runtimePayload: [
                    'gateway_session' => 'session-qr-1',
                ],
                includeHeartbeat: false,
                includeLastSyncStartedAt: false,
                includeLastSyncCompletedAt: false,
            ),
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored', true);

        $runtimeState = ChannelRuntimeState::query()->firstOrFail();

        $this->assertSame(ChannelRuntimeState::AUTH_STATUS_PENDING, $runtimeState->auth_status);
        $this->assertSame(ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_QR, $runtimeState->authorization_state);
        $this->assertSame(ChannelRuntimeState::SYNC_STATUS_IDLE, $runtimeState->sync_status);
        $this->assertNull($runtimeState->last_gateway_heartbeat_at);
        $this->assertSame([
            'gateway_session' => 'session-qr-1',
        ], $runtimeState->runtime_payload);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('dialogs', 0);
    }

    public function test_gateway_runtime_state_endpoint_keeps_utc_heartbeat_fresh_in_app_timezone(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $now = Carbon::parse('2026-06-19 15:05:00', config('app.timezone'));
        $this->travelTo($now);

        $channel = $this->createTelegramAccountChannel([
            'name' => 'Telegram Account UTC Heartbeat',
        ]);
        $heartbeatAt = $now->copy()->subSeconds(30)->utc()->toIso8601String();

        $payload = $this->runtimeStatePayload(
            channel: $channel,
            runtimePayload: [
                'gateway_session' => 'channel-'.$channel->id,
                'gateway_version' => '0.1.0',
            ],
            includeHeartbeat: false,
        );
        $payload['last_gateway_heartbeat_at'] = $heartbeatAt;

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.runtime-state.handle', ['channel' => $channel]),
            $payload,
        )->assertOk();

        $runtimeState = ChannelRuntimeState::query()->firstOrFail();

        $this->assertSame('2026-06-19T15:04:30+03:00', $runtimeState->last_gateway_heartbeat_at?->toIso8601String());
        $this->assertTrue($channel->fresh('runtimeState')->hasFreshTelegramAccountGatewayHeartbeat());

        $diagnostics = app(ResolveTelegramAccountGatewayDiagnosticsAction::class)->handle($channel->fresh('runtimeState'));

        $this->assertSame(TelegramAccountGatewayDiagnosticsData::CODE_OUTGOING_REPLIES_UNCONFIRMED, $diagnostics->code);
    }

    public function test_gateway_runtime_state_endpoint_preserves_existing_sync_timestamps_on_partial_update(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();
        $startedAt = now()->subMinutes(15)->startOfSecond();
        $completedAt = now()->subMinutes(5)->startOfSecond();
        $heartbeatAt = now()->subMinute()->startOfSecond();

        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'last_gateway_heartbeat_at' => $heartbeatAt,
            'last_sync_started_at' => $startedAt,
            'last_sync_completed_at' => $completedAt,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.runtime-state.handle', ['channel' => $channel]),
            $this->runtimeStatePayload(
                channel: $channel,
                authStatus: ChannelRuntimeState::AUTH_STATUS_FAILED,
                authorizationState: ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_CODE,
                syncStatus: ChannelRuntimeState::SYNC_STATUS_FAILED,
                lastErrorAt: '2026-04-23T12:34:56+03:00',
                lastErrorMessage: 'Код подтверждения отклонён',
                includeHeartbeat: false,
                includeLastSyncStartedAt: false,
                includeLastSyncCompletedAt: false,
                includeRuntimePayload: false,
            ),
        )->assertOk();

        $runtimeState = ChannelRuntimeState::query()->firstOrFail();

        $this->assertSame(ChannelRuntimeState::AUTH_STATUS_FAILED, $runtimeState->auth_status);
        $this->assertSame(ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_CODE, $runtimeState->authorization_state);
        $this->assertSame(ChannelRuntimeState::SYNC_STATUS_FAILED, $runtimeState->sync_status);
        $this->assertTrue($runtimeState->last_gateway_heartbeat_at?->equalTo($heartbeatAt));
        $this->assertTrue($runtimeState->last_sync_started_at?->equalTo($startedAt));
        $this->assertTrue($runtimeState->last_sync_completed_at?->equalTo($completedAt));
        $this->assertSame('Код подтверждения отклонён', $runtimeState->last_error_message);
        $this->assertSame('2026-04-23T12:34:56+03:00', $runtimeState->last_error_at?->toIso8601String());
    }

    public function test_gateway_peer_sync_state_endpoint_materializes_terminal_backfill_status_for_peer(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.peer-sync-state.handle', ['channel' => $channel]),
            $this->peerSyncStatePayload(
                channel: $channel,
                externalChatId: '700777',
                backfillStatus: ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE,
                oldestImportedMessageId: '900001',
                latestObservedMessageId: '900010',
                historyCompleteAt: '2026-04-23T12:45:00+03:00',
            ),
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored', true);

        $peerSyncState = ChannelPeerSyncState::query()->firstOrFail();

        $this->assertSame($channel->id, $peerSyncState->channel_id);
        $this->assertSame('telegram_account:'.$channel->id.':700777', $peerSyncState->peer_key);
        $this->assertSame('700777', $peerSyncState->external_chat_id);
        $this->assertSame(ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE, $peerSyncState->backfill_status);
        $this->assertSame('900001', $peerSyncState->oldest_imported_message_id);
        $this->assertSame('900010', $peerSyncState->latest_observed_message_id);
        $this->assertSame('2026-04-23T12:45:00+03:00', $peerSyncState->history_complete_at?->toIso8601String());
        $this->assertNull($peerSyncState->last_sync_error);
    }

    public function test_gateway_peer_sync_state_endpoint_preserves_existing_fields_on_partial_update(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        ChannelPeerSyncState::query()->create([
            'channel_id' => $channel->id,
            'peer_key' => 'telegram_account:'.$channel->id.':700778',
            'external_chat_id' => '700778',
            'backfill_status' => ChannelPeerSyncState::BACKFILL_STATUS_IN_PROGRESS,
            'oldest_imported_message_id' => '900001',
            'latest_observed_message_id' => '900005',
            'history_complete_at' => now()->subMinute()->startOfSecond(),
            'last_sync_error' => 'Старый sync error',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.peer-sync-state.handle', ['channel' => $channel]),
            $this->peerSyncStatePayload(
                channel: $channel,
                externalChatId: '700778',
                backfillStatus: ChannelPeerSyncState::BACKFILL_STATUS_FAILED,
                includeOldestImportedMessageId: false,
                includeLatestObservedMessageId: false,
                includeHistoryCompleteAt: false,
                lastSyncError: 'Backfill остановился на peer',
            ),
        )->assertOk();

        $peerSyncState = ChannelPeerSyncState::query()->firstOrFail();

        $this->assertSame(ChannelPeerSyncState::BACKFILL_STATUS_FAILED, $peerSyncState->backfill_status);
        $this->assertSame('900001', $peerSyncState->oldest_imported_message_id);
        $this->assertSame('900005', $peerSyncState->latest_observed_message_id);
        $this->assertNull($peerSyncState->history_complete_at);
        $this->assertSame('Backfill остановился на peer', $peerSyncState->last_sync_error);
    }

    public function test_gateway_peer_sync_state_endpoint_clears_stale_error_on_successful_completion(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        ChannelPeerSyncState::query()->create([
            'channel_id' => $channel->id,
            'peer_key' => 'telegram_account:'.$channel->id.':700778a',
            'external_chat_id' => '700778a',
            'backfill_status' => ChannelPeerSyncState::BACKFILL_STATUS_FAILED,
            'oldest_imported_message_id' => '900001',
            'latest_observed_message_id' => '900020',
            'last_sync_error' => 'Старый sync error',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.peer-sync-state.handle', ['channel' => $channel]),
            $this->peerSyncStatePayload(
                channel: $channel,
                externalChatId: '700778a',
                backfillStatus: ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE,
                includeOldestImportedMessageId: false,
                latestObservedMessageId: '900030',
                historyCompleteAt: '2026-04-23T13:10:00+03:00',
                includeLastSyncError: false,
            ),
        )->assertOk();

        $peerSyncState = ChannelPeerSyncState::query()->firstOrFail();

        $this->assertSame(ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE, $peerSyncState->backfill_status);
        $this->assertSame('900001', $peerSyncState->oldest_imported_message_id);
        $this->assertSame('900030', $peerSyncState->latest_observed_message_id);
        $this->assertSame('2026-04-23T13:10:00+03:00', $peerSyncState->history_complete_at?->toIso8601String());
        $this->assertNull($peerSyncState->last_sync_error);
    }

    public function test_gateway_peer_sync_state_endpoint_rejects_non_canonical_peer_key(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.peer-sync-state.handle', ['channel' => $channel]),
            array_merge(
                $this->peerSyncStatePayload(channel: $channel, externalChatId: '700779'),
                ['peer_key' => 'telegram_account:'.$channel->id.':WRONG'],
            ),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['peer_key']);
    }

    public function test_gateway_peer_sync_state_endpoint_rejects_complete_status_without_history_complete_at(): void
    {
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.peer-sync-state.handle', ['channel' => $channel]),
            $this->peerSyncStatePayload(
                channel: $channel,
                externalChatId: '700780',
                backfillStatus: ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE,
                historyCompleteAt: null,
            ),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['history_complete_at']);
    }

    public function test_late_backfill_inbound_does_not_reopen_completed_peer_sync_state(): void
    {
        Queue::fake();
        config()->set('bots.telegram_account.gateway_shared_secret', 'gateway-secret');

        $channel = $this->createTelegramAccountChannel();

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.peer-sync-state.handle', ['channel' => $channel]),
            $this->peerSyncStatePayload(
                channel: $channel,
                externalChatId: '700781',
                backfillStatus: ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE,
                oldestImportedMessageId: '900010',
                latestObservedMessageId: '900050',
                historyCompleteAt: '2026-04-23T13:00:00+03:00',
            ),
        )->assertOk();

        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: '700781',
                externalUserId: 'tg-account-user-complete-overlap',
                externalMessageId: '900001',
                text: 'Поздний overlap backfill',
                historySource: 'backfill',
            ),
        )->assertOk();

        $peerSyncState = ChannelPeerSyncState::query()->where('external_chat_id', '700781')->firstOrFail();

        $this->assertSame(ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE, $peerSyncState->backfill_status);
        $this->assertSame('900001', $peerSyncState->oldest_imported_message_id);
        $this->assertSame('900050', $peerSyncState->latest_observed_message_id);
        $this->assertSame('2026-04-23T13:00:00+03:00', $peerSyncState->history_complete_at?->toIso8601String());
    }

    private function createTelegramAccountChannel(array $attributes = []): Channel
    {
        return Channel::factory()->account()->create(array_merge([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ], $attributes));
    }

    private function createPublishedV3StartScenario(Channel $channel, string $keyword): Scenario
    {
        $scenario = Scenario::query()->create([
            'code' => 'telegram_account_v3_start',
            'name' => 'Telegram Account V3 Start',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => [
                'version' => 3,
                'builder_v3_runtime' => [
                    'schema_version' => 3,
                    'source_revision' => 'v3:test',
                    'compiled_at' => now()->toISOString(),
                    'entrypoints' => [
                        [
                            'block_id' => 'start',
                            'channel_ids' => [$channel->id],
                            'match' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                            'values' => [$keyword],
                            'contact_phone_condition' => '',
                            'dialog_phone_condition' => '',
                            'expression' => '',
                            'tag_condition' => [
                                'enabled' => false,
                                'mode' => 'has_all',
                                'tag_ids' => [],
                            ],
                            'priority' => 10,
                        ],
                    ],
                    'blocks' => [
                        'start' => [
                            'id' => 'start',
                            'db_id' => 1,
                            'kind' => 'state',
                            'title' => 'Старт',
                            'message' => [
                                'text' => 'Здравствуйте! Спасибо за отклик.',
                                'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                            ],
                            'buttons' => [
                                'placement' => 'auto',
                                'rows' => [],
                            ],
                            'actions' => [],
                            'wait_reply_edges' => [],
                            'automatic_edges' => [],
                            'action_result_edges' => [],
                        ],
                    ],
                    'edges' => [],
                ],
            ],
        ]);

        return $scenario->fresh('publishedVersion');
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
        bool $isArchived = false,
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
            'is_archived' => $isArchived,
            'raw_payload' => [
                'provider' => 'telegram_account_gateway',
            ],
            'occurred_at' => '2026-04-23T12:00:00+03:00',
            'history_source' => $historySource,
        ];
    }

    private function createLiveTelegramAccountDialog(
        Channel $channel,
        string $externalChatId,
        string $externalMessageId,
    ): Dialog {
        $this->withHeaders([
            'Authorization' => 'Bearer gateway-secret',
        ])->postJson(
            route('internal.telegram-account.messages.handle', ['channel' => $channel]),
            $this->payload(
                channel: $channel,
                externalChatId: $externalChatId,
                externalUserId: 'tg-account-user-'.$externalChatId,
                externalMessageId: $externalMessageId,
                text: 'Входящее для проверки ответа',
                historySource: 'live',
            ),
        )->assertOk()
            ->assertJsonPath('stored', true);

        ChannelRuntimeState::query()->updateOrCreate(
            ['channel_id' => $channel->id],
            [
                'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
                'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
                'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
                'last_gateway_heartbeat_at' => now(),
                'runtime_payload' => [
                    'gateway_capabilities' => [
                        'outgoing_replies' => true,
                    ],
                ],
            ],
        );

        return Dialog::query()
            ->where('channel_id', $channel->id)
            ->where('external_chat_id', $externalChatId)
            ->firstOrFail()
            ->fresh(['contact.assignedUser', 'channel.runtimeState', 'currentContactIdentity']);
    }

    /**
     * @param  array<string, mixed>  $runtimePayload
     * @return array<string, mixed>
     */
    private function runtimeStatePayload(
        Channel $channel,
        string $authStatus = ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
        string $authorizationState = ChannelRuntimeState::AUTHORIZATION_STATE_READY,
        string $syncStatus = ChannelRuntimeState::SYNC_STATUS_LIVE,
        ?string $lastErrorAt = null,
        ?string $lastErrorMessage = null,
        array $runtimePayload = [],
        bool $includeHeartbeat = true,
        bool $includeLastSyncStartedAt = true,
        bool $includeLastSyncCompletedAt = true,
        bool $includeRuntimePayload = true,
    ): array {
        $payload = [
            'schema_version' => 'v1',
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'auth_status' => $authStatus,
            'authorization_state' => $authorizationState,
            'sync_status' => $syncStatus,
            'last_error_at' => $lastErrorAt,
            'last_error_message' => $lastErrorMessage,
        ];

        if ($includeHeartbeat) {
            $payload['last_gateway_heartbeat_at'] = '2026-04-23T12:00:00+03:00';
        }

        if ($includeLastSyncStartedAt) {
            $payload['last_sync_started_at'] = '2026-04-23T11:00:00+03:00';
        }

        if ($includeLastSyncCompletedAt) {
            $payload['last_sync_completed_at'] = '2026-04-23T11:30:00+03:00';
        }

        if ($includeRuntimePayload) {
            $payload['runtime_payload'] = $runtimePayload;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function peerSyncStatePayload(
        Channel $channel,
        string $externalChatId = '700770',
        string $backfillStatus = ChannelPeerSyncState::BACKFILL_STATUS_IN_PROGRESS,
        ?string $oldestImportedMessageId = '900001',
        ?string $latestObservedMessageId = '900005',
        ?string $historyCompleteAt = null,
        ?string $lastSyncError = null,
        bool $includeOldestImportedMessageId = true,
        bool $includeLatestObservedMessageId = true,
        bool $includeHistoryCompleteAt = true,
        bool $includeLastSyncError = true,
    ): array {
        $payload = [
            'schema_version' => 'v1',
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'peer_key' => 'telegram_account:'.$channel->id.':'.$externalChatId,
            'external_chat_id' => $externalChatId,
            'backfill_status' => $backfillStatus,
        ];

        if ($includeOldestImportedMessageId) {
            $payload['oldest_imported_message_id'] = $oldestImportedMessageId;
        }

        if ($includeLatestObservedMessageId) {
            $payload['latest_observed_message_id'] = $latestObservedMessageId;
        }

        if ($includeHistoryCompleteAt) {
            $payload['history_complete_at'] = $historyCompleteAt;
        }

        if ($includeLastSyncError) {
            $payload['last_sync_error'] = $lastSyncError;
        }

        return $payload;
    }
}
