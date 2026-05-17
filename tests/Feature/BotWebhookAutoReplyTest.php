<?php

namespace Tests\Feature;

use App\Data\Bots\StoredInboundMessageResult;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Services\Scenarios\DispatchStoredInboundScenarioAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BotWebhookAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_webhook_endpoint_accepts_valid_event_and_queues_auto_reply(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('app.url', 'https://connector.example');

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
            'connection_status' => Channel::CONNECTION_STATUS_NOT_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_NOT_INSTALLED,
            'connection_checked_at' => now()->subMinutes(5),
            'connection_error_message' => Channel::CONNECTION_ERROR_STALE,
            'provider_webhook_url' => 'https://old-admin.example/webhooks/telegram/1',
            'expected_webhook_url' => 'https://old-admin.example/webhooks/telegram/1',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload());

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Http::assertNothingSent();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($inboundMessage): bool {
            return $job->inboundMessageId === $inboundMessage->id
                && $job->queue === ProcessAutoReplyJob::queueName();
        });

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $channel->webhook_status);
        $this->assertNull($channel->connection_error_message);
        $this->assertNotNull($channel->connection_checked_at);
        $this->assertSame("https://connector.example/webhooks/telegram/{$channel->id}", $channel->expected_webhook_url);
        $this->assertSame("https://connector.example/webhooks/telegram/{$channel->id}", $channel->provider_webhook_url);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.received',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_queued',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.max_unhandled_payload',
        ]);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'text' => 'hello',
            'message_parameter' => null,
        ]);
        $this->assertSame('10', $inboundMessage->provider_event_key);
        $this->assertNull($inboundMessage->auto_reply_sent_at);
    }

    public function test_telegram_start_payload_webhook_saves_message_parameter_and_queues_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            messageId: 11,
            text: '/start TEXT_1',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($inboundMessage): bool {
            return $job->inboundMessageId === $inboundMessage->id;
        });

        $this->assertDatabaseHas('messages', [
            'id' => $inboundMessage->id,
            'text' => '/start TEXT_1',
            'message_parameter' => 'TEXT_1',
        ]);
    }

    public function test_telegram_my_chat_member_webhook_stores_system_event_and_queues_live_export_for_ready_dialog(): void
    {
        config()->set('bitrix24.features.openlines_enabled', true);

        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $contact = Contact::factory()->create([
            'bitrix24_contact_id' => 'B24-CONTACT-TG-200',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '200',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramMyChatMemberPayload(
            userId: 200,
            chatId: 200,
            oldStatus: 'member',
            newStatus: 'kicked',
            updateId: 2010,
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job): bool {
            return $job->retryAfterSync === false;
        });
        Http::assertNothingSent();

        $storedMessage = Message::query()->firstOrFail();

        $this->assertSame(Message::KIND_INBOUND_SYSTEM_EVENT, $storedMessage->message_kind);
        $this->assertSame(Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER, $storedMessage->system_event_code);
        $this->assertSame(Message::SENT_BY_TYPE_SYSTEM, $storedMessage->sent_by_type);
        $this->assertSame(Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION, $storedMessage->sent_by_system_code);
        $this->assertSame('2010', $storedMessage->provider_event_key);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_queued',
        ]);

        $dialog->refresh();

        $this->assertSame(Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER, $dialog->bot_subscription_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $storedMessage->id,
            'contact_id' => $contact->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_max_webhook_endpoint_accepts_valid_event_and_queues_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload())->assertOk();

        Http::assertNothingSent();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($inboundMessage): bool {
            return $job->inboundMessageId === $inboundMessage->id;
        });

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '700',
            'external_message_id' => 'max-10',
            'text' => 'hello',
        ]);
        $this->assertSame('max-10', $inboundMessage->provider_event_key);
        $this->assertNull($inboundMessage->auto_reply_sent_at);
    }

    public function test_max_bot_started_webhook_queues_only_auto_reply_runtime_job_when_parameter_is_present(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'promo_123'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_USER, $storedMessage->message_kind);
        $this->assertNull($storedMessage->text);
        $this->assertNull($storedMessage->external_message_id);
        $this->assertSame('promo_123', $storedMessage->message_parameter);
        $this->assertSame('bot_started', data_get($storedMessage->raw_payload, 'update_type'));
        $this->assertStringStartsWith('max-bot-started:', $storedMessage->provider_event_key ?? '');
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
    }

    public function test_max_bot_started_webhook_without_parameter_remains_store_only(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: '   '));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertNull($storedMessage->message_parameter);
        $this->assertSame('bot_started', data_get($storedMessage->raw_payload, 'update_type'));
    }

    public function test_max_bot_started_with_parameter_skips_auto_reply_when_scenario_dispatcher_consumes_message(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $dispatcher = Mockery::mock(DispatchStoredInboundScenarioAction::class);
        $dispatcher->shouldReceive('shouldBlockVipIbizaParameterStartBecauseBusyState')->once()->andReturn(false);
        $dispatcher->shouldReceive('handle')->once()->andReturn(true);
        $this->app->instance(DispatchStoredInboundScenarioAction::class, $dispatcher);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'promo_123'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_max_text_message_checks_priority_scenario_before_active_run(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $dispatcher = Mockery::mock(DispatchStoredInboundScenarioAction::class);
        $dispatcher->shouldReceive('shouldBlockVipIbizaParameterStartBecauseBusyState')
            ->once()
            ->ordered()
            ->andReturn(false);
        $dispatcher->shouldReceive('startPriorityScenario')
            ->once()
            ->ordered()
            ->andReturn(true);
        $dispatcher->shouldNotReceive('continueActiveRun');
        $dispatcher->shouldNotReceive('startMatchingScenario');
        $this->app->instance(DispatchStoredInboundScenarioAction::class, $dispatcher);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-priority-start-1',
            text: 'старт',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_max_bot_started_webhook_queues_only_auto_reply_for_contact_in_active_data_collection(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'promo_123'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->latest('id')->firstOrFail();

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);

        $this->assertSame($contact->id, $storedMessage->contact_id);
        $this->assertSame('promo_123', $storedMessage->message_parameter);
        $this->assertSame('bot_started', data_get($storedMessage->raw_payload, 'update_type'));
    }

    public function test_repeated_max_bot_started_webhook_with_parameter_still_queues_auto_reply_for_contact_in_active_data_collection(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];
        $payload = $this->maxBotStartedPayload(payload: 'promo_123');

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        $storedMessage = $this->inboundMessages()->latest('id')->firstOrFail();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_max_vip_ibiza_parameter_start_with_active_run_sends_blocking_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'body' => [
                        'mid' => 'max-out-501',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $otherScenario = $this->createPublishedScenario('other_flow', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'other_flow',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $otherScenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_topic',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'vip_ibiza_apply'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();
        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame($otherScenario->code, $run->scenario_code);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'У тебя уже есть активная анкета. Сначала заверши её.');

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже есть активная анкета. Сначала заверши её.',
        ]);
    }

    public function test_repeated_max_vip_ibiza_parameter_start_with_busy_state_sends_blocking_reply_only_once(): void
    {
        Queue::fake();
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'body' => [
                        'mid' => 'max-out-502',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];
        $payload = $this->maxBotStartedPayload(payload: 'vip_ibiza_inst1');

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        $message = $this->inboundMessages()->firstOrFail();

        $this->assertNotNull($message->auto_reply_sent_at);
        Http::assertSentCount(1);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_max_vip_ibiza_parameter_start_with_active_collector_and_no_active_run_sends_blocking_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'body' => [
                        'mid' => 'max-out-503',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'vip_ibiza_tg1'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'У тебя уже есть активная анкета. Сначала заверши её.');

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже есть активная анкета. Сначала заверши её.',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
    }

    public function test_max_vip_ibiza_parameter_start_without_busy_state_starts_scenario_as_before(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'vip_ibiza_inst1'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessScenarioStartJob::class, function (ProcessScenarioStartJob $job) use ($storedMessage, $dialog, $scenario): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->dialogId === $dialog->id
                && $job->scenarioCode === $scenario->code;
        });
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertNull($storedMessage->fresh()->auto_reply_sent_at);
    }

    public function test_max_webhook_uses_real_payload_fields_for_contact_name_and_message_id(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $timestamp = Carbon::create(2026, 3, 20, 12, 34, 56, 'UTC')->getTimestampMs() + 123;

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'timestamp' => $timestamp,
            'message' => [
                'timestamp' => $timestamp,
                'sender' => [
                    'user_id' => 228532008,
                    'first_name' => 'German',
                    'last_name' => 'Abrikosov',
                    'username' => null,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-mid-42',
                    'text' => 'Привет из MAX',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseHas('contacts', [
            'first_name' => 'German Abrikosov',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'external_user_id' => '228532008',
            'external_username' => null,
            'display_name' => 'German Abrikosov',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_message_id' => 'max-mid-42',
            'text' => 'Привет из MAX',
        ]);

        $message = $this->inboundMessages()->firstOrFail();

        $this->assertSame(intdiv($timestamp, 1000), $message->received_at->getTimestamp());
        $this->assertSame('2026-03-20 12:34:56', $message->received_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_telegram_contact_share_webhook_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramPayload(messageId: 90, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_telegram_contact_share_with_profile_name_still_asks_for_first_name(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9921,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9922,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramPayload(messageId: 92, text: null);
        $payload['message']['from']['first_name'] = 'German';
        $payload['message']['from']['last_name'] = 'Abrikosov';
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'Спасибо, номер получили.'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'Как вас зовут?');

        $storedMessage = $this->inboundMessages()->firstOrFail();
        $contact = $storedMessage->contact()->firstOrFail()->fresh();
        $identity = $storedMessage->contactIdentity()->firstOrFail()->fresh();

        $this->assertSame('German Abrikosov', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $contact->data_collection_current_field);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $contact->data_collection_last_prompted_field);
        $this->assertSame('German Abrikosov', $identity->display_name);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'Спасибо, номер получили.',
        ]);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'Как вас зовут?',
        ]);
    }

    public function test_telegram_contact_share_skips_follow_up_when_scenario_dispatcher_consumes_message(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $dispatcher = Mockery::mock(DispatchStoredInboundScenarioAction::class);
        $dispatcher->shouldReceive('continueActiveRun')->once()->andReturn(true);
        $this->app->instance(DispatchStoredInboundScenarioAction::class, $dispatcher);

        $payload = $this->telegramPayload(messageId: 91, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertSame(1, $this->inboundMessages()->count());
        $this->assertDatabaseMissing('messages', [
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        ]);
    }

    public function test_telegram_contact_share_suppresses_global_phone_confirmation_during_active_v3_run(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);
        $scenario = $this->createPublishedScenario('v3_suppression', [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'blocks' => [],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'some_block',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'current_block_id' => 'some_block',
                    'status' => 'waiting_input',
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramPayload(messageId: 910, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);
        $this->assertDatabaseMissing('messages', [
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_suppressed_for_v3',
        ]);
    }

    public function test_max_contact_share_suppresses_global_phone_confirmation_after_v3_request_contact_prompt(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => 'scenario_'.Scenario::CONSTRUCTOR_WORKSPACE_CODE,
            'external_chat_id' => '700',
            'text' => 'Стартовое при любом сообщении (серый блок)',
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [[
                            'type' => 'inline_keyboard',
                            'payload' => [
                                'buttons' => [[[
                                    'type' => 'request_contact',
                                    'text' => '1',
                                ]]],
                            ],
                        ]],
                    ],
                ],
            ],
            'created_at' => now()->subMinute(),
        ]);

        $payload = $this->maxPayload(messageId: 'max-v3-contact-91', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-v3-contact-91',
            'contact' => [
                'phone' => '+7 999 123 45 67',
                'user_id' => 500,
            ],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        ]);
        $this->assertDatabaseMissing('messages', [
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_suppressed_for_v3',
        ]);
    }

    public function test_active_scenario_run_has_priority_over_active_data_collection_for_inbound_user(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'welcome',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 902,
            text: 'Герман',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessScenarioInboundJob::class, function (ProcessScenarioInboundJob $job) use ($storedMessage, $run): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->scenarioRunId === $run->id;
        });
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
    }

    public function test_telegram_contact_share_with_active_database_run_falls_back_to_legacy_phone_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'welcome',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramPayload(messageId: 93, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
    }

    public function test_telegram_contact_share_with_active_database_run_on_phone_capture_queues_scenario_inbound_job(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza_apply', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramPayload(messageId: 94, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();
        $contact->refresh();
        $dialog->refresh();

        Queue::assertPushed(ProcessScenarioInboundJob::class, function (ProcessScenarioInboundJob $job) use ($storedMessage, $run): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->scenarioRunId === $run->id;
        });
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);
        $this->assertSame('+7 999 123 45 67', $dialog->confirmed_phone_raw);
        $this->assertSame('+79991234567', $dialog->confirmed_phone_normalized);
    }

    public function test_telegram_contact_share_with_active_database_run_on_phone_capture_and_sender_mismatch_does_not_queue_scenario_inbound_job(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza_apply', [
            'version' => 1,
            'start_block_id' => 'capture_phone',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramPayload(messageId: 95, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 999,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
    }

    public function test_telegram_vip_ibiza_deep_link_with_active_vip_ibiza_run_sends_blocking_reply_without_restart(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 501,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [
                'run' => [
                    'budget_tier' => '15,000 USD и выше',
                ],
            ],
            'started_at' => now()->subMinutes(10),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 903,
            text: '/start vip_ibiza_apply',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();
        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('capture_phone', $run->current_step);
        $this->assertNull($run->exit_outcome);
        $this->assertNull($run->finished_at);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'У тебя уже есть активная анкета. Сначала заверши её.');

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже есть активная анкета. Сначала заверши её.',
        ]);
    }

    public function test_repeated_telegram_vip_ibiza_deep_link_with_active_run_sends_blocking_reply_only_once(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 502,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];
        $payload = $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 904,
            text: '/start vip_ibiza_apply',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $run = ScenarioRun::query()->active()->where('dialog_id', $dialog->id)->firstOrFail();
        $message = $this->inboundMessages()->firstOrFail();

        $this->assertSame($scenario->code, $run->scenario_code);
        $this->assertSame('capture_phone', $run->current_step);
        $this->assertNotNull($message->auto_reply_sent_at);

        Http::assertSentCount(1);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_plain_telegram_start_does_not_trigger_vip_ibiza_active_run_guard(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 905,
            text: '/start',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $run = ScenarioRun::query()->active()->where('dialog_id', $dialog->id)->firstOrFail();

        $this->assertSame($scenario->code, $run->scenario_code);
        $this->assertSame('capture_phone', $run->current_step);

        Queue::assertPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
    }

    public function test_telegram_vip_ibiza_deep_link_with_other_active_scenario_sends_blocking_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 503,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $this->createPublishedScenario('other_flow', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'other_flow',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'other_flow',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_topic',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 906,
            text: '/start vip_ibiza_inst1',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $run = ScenarioRun::query()->active()->where('dialog_id', $dialog->id)->firstOrFail();
        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('other_flow', $run->scenario_code);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже есть активная анкета. Сначала заверши её.',
        ]);
    }

    public function test_telegram_vip_ibiza_deep_link_with_active_collector_and_no_active_run_sends_blocking_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 504,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 908,
            text: '/start vip_ibiza_tg1',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'У тебя уже есть активная анкета. Сначала заверши её.');

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже есть активная анкета. Сначала заверши её.',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
    }

    public function test_telegram_vip_ibiza_deep_link_without_active_run_starts_scenario_as_before(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 907,
            text: '/start vip_ibiza_tg1',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessScenarioStartJob::class, function (ProcessScenarioStartJob $job) use ($storedMessage, $dialog, $scenario): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->dialogId === $dialog->id
                && $job->scenarioCode === $scenario->code;
        });
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertNull($storedMessage->fresh()->auto_reply_sent_at);
    }

    public function test_telegram_generic_scenario_callback_is_answered_and_ignored_for_database_backed_run(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');
        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'welcome',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramCallbackPayload(
            callbackId: 'callback-94',
            callbackData: "scenario:{$run->id}:start_selection",
            messageId: 91,
        );

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-94');
    }

    public function test_stale_telegram_generic_scenario_callback_is_answered_and_ignored(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramCallbackPayload(
            callbackId: 'callback-941',
            callbackData: 'scenario:999:start_selection',
            messageId: 93,
        );

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-941');
    }

    public function test_dispatch_ignores_stored_generic_scenario_callback_for_database_backed_run(): void
    {
        Queue::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');
        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'welcome',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $storedMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'external_chat_id' => '300',
            'external_message_id' => 'callback-942',
            'text' => 'scenario:start_selection',
            'message_kind' => Message::KIND_INBOUND_USER,
            'raw_payload' => [
                'callback_query' => [
                    'id' => 'callback-942',
                    'data' => "scenario:{$run->id}:start_selection",
                ],
            ],
        ]);

        $handled = app(DispatchStoredInboundScenarioAction::class)->continueActiveRun($storedMessage);

        $this->assertFalse($handled);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
    }

    public function test_telegram_generic_scenario_callback_queues_inbound_job_for_builtin_run(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_topic',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramCallbackPayload(
            callbackId: 'callback-95',
            callbackData: "scenario:warmup:{$run->id}:positive",
            messageId: 92,
        );

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessScenarioInboundJob::class, function (ProcessScenarioInboundJob $job) use ($storedMessage, $run): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->scenarioRunId === $run->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertSame('warmup:positive', $storedMessage->text);
    }

    public function test_max_contact_share_webhook_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-90', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-contact-90',
            'contact' => [
                'phone' => '+7 999 123 45 67',
                'user_id' => 500,
            ],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_max_contact_share_webhook_with_vcf_attachment_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-vcf-90', text: null);
        $payload['message']['sender']['user_id'] = 228532008;
        $payload['message']['body'] = [
            'mid' => 'max-contact-vcf-90',
            'text' => null,
            'attachments' => [[
                'type' => 'contact',
                'payload' => [
                    'max_info' => [
                        'user_id' => 228532008,
                    ],
                    'vcf_info' => "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:79263527111\r\nFN:Герман Абрикосов\r\nEND:VCARD",
                ],
            ]],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '79263527111',
            'phone_normalized' => '+79263527111',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_max_webhook_logs_unhandled_payload_when_normalizer_returns_null_due_to_missing_chat_id(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 228532008,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'user_id' => 241737700,
                    'chat_type' => 'dialog',
                ],
                'body' => [
                    'mid' => 'max-unhandled-contact-1',
                    'contact' => [
                        'name' => 'Герман Абрикосов',
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.received',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.max_unhandled_payload',
        ]);

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('missing_chat_id', data_get($log->context, 'reason'));
        $this->assertSame('message_created', data_get($log->context, 'update_type'));
        $this->assertSame('max-unhandled-contact-1', data_get($log->context, 'message_mid'));
        $this->assertTrue((bool) data_get($log->context, 'has_sender_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_user_id'));
        $this->assertFalse((bool) data_get($log->context, 'has_recipient_chat_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_body_contact'));
        $this->assertFalse((bool) data_get($log->context, 'has_vcf_info'));
        $this->assertIsString(data_get($log->context, 'payload_excerpt'));
    }

    public function test_max_webhook_logs_reason_when_update_type_is_not_supported(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_callback',
            'timestamp' => 1_775_578_788_491,
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('unsupported_update_type', data_get($log->context, 'reason'));
        $this->assertSame('message_callback', data_get($log->context, 'update_type'));
        $this->assertNull(data_get($log->context, 'message_mid'));
        $this->assertFalse((bool) data_get($log->context, 'has_sender'));
        $this->assertFalse((bool) data_get($log->context, 'has_attachments'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_max_webhook_logs_reason_when_message_payload_is_missing(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'user_locale' => 'ru',
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('missing_message_payload', data_get($log->context, 'reason'));
        $this->assertFalse((bool) data_get($log->context, 'has_sender'));
        $this->assertFalse((bool) data_get($log->context, 'has_recipient'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_max_webhook_logs_reason_when_sender_is_bot(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 228532008,
                    'is_bot' => true,
                ],
                'recipient' => [
                    'chat_id' => 66552012,
                    'user_id' => 241737700,
                    'chat_type' => 'dialog',
                ],
                'body' => [
                    'mid' => 'max-unhandled-bot-sender-1',
                    'text' => 'hello',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('sender_is_bot', data_get($log->context, 'reason'));
        $this->assertSame('max-unhandled-bot-sender-1', data_get($log->context, 'message_mid'));
        $this->assertTrue((bool) data_get($log->context, 'has_sender_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_chat_id'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_max_webhook_logs_reason_when_payload_is_not_dialog(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'message' => [
                'sender' => [
                    'user_id' => 228532008,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 66552012,
                    'chat_type' => 'dialog',
                ],
                'body' => [
                    'mid' => 'max-unhandled-not-dialog-1',
                    'text' => 'hello',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('not_dialog', data_get($log->context, 'reason'));
        $this->assertSame('max-unhandled-not-dialog-1', data_get($log->context, 'message_mid'));
        $this->assertTrue((bool) data_get($log->context, 'has_sender_user_id'));
        $this->assertFalse((bool) data_get($log->context, 'has_recipient_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_chat_id'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_max_webhook_logs_reason_when_sender_user_id_is_missing(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 66552012,
                    'user_id' => 241737700,
                    'chat_type' => 'dialog',
                ],
                'body' => [
                    'mid' => 'max-unhandled-missing-user-1',
                    'text' => 'hello',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('missing_user_id', data_get($log->context, 'reason'));
        $this->assertSame('max-unhandled-missing-user-1', data_get($log->context, 'message_mid'));
        $this->assertFalse((bool) data_get($log->context, 'has_sender_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_chat_id'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_late_max_contact_share_logs_delayed_received_and_phone_capture_arrived_late(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $payload = $this->maxPayload(
                messageId: 'max-contact-late-90',
                text: null,
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $payload['message']['body'] = [
                'mid' => 'max-contact-late-90',
                'contact' => [
                    'phone' => '+7 999 123 45 67',
                    'user_id' => 500,
                ],
            ];

            $response = $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $payload);

            $response->assertOk()->assertExactJson([
                'ok' => true,
            ]);

            $storedMessage = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-90')
                ->firstOrFail();

            Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
                return $job->inboundMessageId === $storedMessage->id
                    && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
            });

            $delayedLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'webhook.delayed_received')
                ->latest('id')
                ->firstOrFail();

            $latePhoneCaptureLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'contact.phone_capture_arrived_late')
                ->latest('id')
                ->firstOrFail();

            $this->assertGreaterThan(60, (int) data_get($delayedLog->context, 'delivery_lag_seconds'));
            $this->assertSame('max-contact-late-90', data_get($delayedLog->context, 'external_message_id'));
            $this->assertSame(
                StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW,
                data_get($latePhoneCaptureLog->context, 'phone_capture_status'),
            );
            $this->assertGreaterThan(60, (int) data_get($latePhoneCaptureLog->context, 'delivery_lag_seconds'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_max_contact_share_logs_out_of_order_when_newer_inbound_exists(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $newerPayload = $this->maxPayload(
                messageId: 'max-user-newer-91',
                text: 'что?',
                timestamp: '2026-03-31T19:05:30+03:00',
            );

            $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $newerPayload)->assertOk();

            $newerInbound = $this->inboundMessages()
                ->where('external_message_id', 'max-user-newer-91')
                ->firstOrFail();

            $latePayload = $this->maxPayload(
                messageId: 'max-contact-late-order-92',
                text: null,
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $latePayload['message']['body'] = [
                'mid' => 'max-contact-late-order-92',
                'contact' => [
                    'phone' => '+7 999 123 45 67',
                    'user_id' => 500,
                ],
            ];

            $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $latePayload)->assertOk();

            $lateInbound = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-order-92')
                ->firstOrFail();

            $outOfOrderLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'webhook.out_of_order_received')
                ->latest('id')
                ->firstOrFail();

            $this->assertSame($lateInbound->id, (int) data_get($outOfOrderLog->context, 'message_id'));
            $this->assertSame($newerInbound->id, (int) data_get($outOfOrderLog->context, 'newer_inbound_message_id'));
            $this->assertGreaterThan(0, (int) data_get($outOfOrderLog->context, 'seconds_behind_latest_inbound'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_max_contact_share_still_merges_into_existing_root_and_queues_follow_up(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $existingRoot = Contact::factory()->create([
                'first_name' => 'Герман',
                'country' => 'Россия',
                'city' => 'Москва',
                'age_range' => '30_39',
            ]);
            ContactPhoneNumber::factory()->create([
                'contact_id' => $existingRoot->id,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
                'is_primary' => true,
            ]);

            $payload = $this->maxPayload(
                userId: 228532008,
                messageId: 'max-contact-late-merge-93',
                text: null,
                username: 'max_user_merge',
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $payload['message']['body'] = [
                'mid' => 'max-contact-late-merge-93',
                'attachments' => [[
                    'type' => 'contact',
                    'payload' => [
                        'max_info' => [
                            'user_id' => 228532008,
                        ],
                        'vcf_info' => "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:79991234567\r\nFN:Герман Абрикосов\r\nEND:VCARD",
                    ],
                ]],
            ];

            $response = $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $payload);

            $response->assertOk()->assertExactJson([
                'ok' => true,
            ]);

            $storedMessage = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-merge-93')
                ->firstOrFail();

            Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
                return $job->inboundMessageId === $storedMessage->id
                    && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT;
            });

            $this->assertSame($existingRoot->id, $storedMessage->contact_id);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'webhook.delayed_received',
            ]);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'contact.phone_capture_arrived_late',
            ]);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'contact.phone_merged_to_existing_root',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_max_contact_share_with_unknown_format_logs_skip_event_and_does_not_queue_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-91', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-contact-91',
            'contact' => [],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Http::assertNothingSent();

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'max.contact_share_unknown_format',
        ]);
    }

    public function test_max_contact_share_with_active_database_run_on_phone_capture_and_unknown_format_does_not_queue_scenario_inbound_job(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza_apply', [
            'version' => 1,
            'start_block_id' => 'capture_phone',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-92', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-contact-92',
            'contact' => [],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
    }

    public function test_telegram_contact_share_webhook_merges_into_existing_root_and_queues_merged_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $existingRoot = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '30_39',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $existingRoot->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $payload = $this->telegramPayload(messageId: 190, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT;
        });

        $this->assertSame($existingRoot->id, $storedMessage->contact_id);
        $this->assertDatabaseCount('contact_merge_logs', 1);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_merged_to_existing_root',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_telegram_contact_share_webhook_marks_review_pending_when_phone_matches_multiple_roots(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        foreach ([1, 2] as $index) {
            $contact = Contact::factory()->create([
                'first_name' => 'Контакт '.$index,
            ]);
            ContactPhoneNumber::factory()->create([
                'contact_id' => $contact->id,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
                'is_primary' => true,
            ]);
        }

        $payload = $this->telegramPayload(messageId: 191, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING;
        });

        $this->assertDatabaseCount('contact_merge_logs', 0);
        $this->assertDatabaseHas('contact_duplicate_reviews', [
            'contact_id' => $storedMessage->contact_id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_review_pending_multiple_roots',
        ]);
    }

    public function test_telegram_contact_share_with_sender_mismatch_does_not_queue_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramPayload(messageId: 91, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 999,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Http::assertNothingSent();
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_skipped_sender_mismatch',
        ]);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_does_not_queue_second_job_after_successful_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 42,
            text: 'duplicate telegram message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $message = $this->inboundMessages()->firstOrFail();
        $message->forceFill([
            'auto_reply_sent_at' => now(),
        ])->save();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_requeues_after_previous_failure(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 43,
            text: 'telegram retry message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $message = $this->inboundMessages()->firstOrFail();

        $this->assertSame('43', $message->provider_event_key);
        $this->assertNull($message->auto_reply_sent_at);

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_retry_reply',
        ]);
    }

    public function test_repeated_max_webhook_with_same_external_message_id_requeues_after_previous_failure(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];

        $payload = $this->maxPayload(
            messageId: 'max-43',
            text: 'max retry message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame('max-43', $this->inboundMessages()->firstOrFail()->provider_event_key);
    }

    public function test_repeat_max_webhook_from_same_user_with_different_message_ids_creates_two_inbound_messages(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
                messageId: 'max-100',
                text: 'first max message',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
                messageId: 'max-101',
                text: 'second max message',
            ))
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
    }

    public function test_inactive_channel_does_not_process_event(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => false,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload())->assertNotFound();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_invalid_telegram_webhook_secret_is_rejected(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'expected-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload())->assertForbidden();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_empty_max_webhook_secret_is_rejected(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'expected-secret',
            ],
        ]);

        $this->postJson("/webhooks/max/{$channel->id}", [
            'update_type' => 'message_created',
            'message' => [
                'sender' => [
                    'user_id' => 1,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'user_id' => 2,
                ],
            ],
        ])->assertForbidden();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_repeat_telegram_webhook_from_same_user_reuses_contact_identity_and_contact(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                messageId: 10,
                text: 'first message',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                messageId: 11,
                text: 'second message',
            ))
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
    }

    public function test_telegram_webhook_without_update_id_keeps_legacy_non_deduplicated_behavior(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 77,
            text: 'legacy telegram message',
            includeUpdateId: false,
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => null,
        ]);
    }

    public function test_new_telegram_webhook_from_different_user_creates_new_contact_and_identity(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                userId: 200,
                chatId: 300,
                messageId: 10,
                text: 'first message',
                username: 'telegram_user',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                userId: 201,
                chatId: 301,
                messageId: 11,
                text: 'second message',
                username: 'telegram_user_2',
            ))
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseCount('contact_identities', 2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'external_user_id' => '201',
            'external_username' => 'telegram_user_2',
        ]);
    }

    public function test_active_data_collection_routes_inbound_user_to_collector_instead_of_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 901,
            text: 'Герман',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
    }

    public function test_active_data_collection_with_unprompted_current_field_requeues_question_instead_of_processing_response(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'data_collection_last_prompted_field' => null,
            'data_collection_started_at' => now(),
            'data_collection_current_field_started_at' => null,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 903,
            text: 'Санкт-Петербург',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionQuestionJob::class, function (ProcessDataCollectionQuestionJob $job) use ($storedMessage): bool {
            return $job->sourceMessageId === $storedMessage->id
                && $job->contactId === $storedMessage->contact_id
                && $job->expectedField === Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY
                && $job->forceSend === false;
        });
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_pending_question_queued',
        ]);
    }

    public function test_active_data_collection_with_legacy_sent_question_does_not_requeue_prompt_again(): void
    {
        Queue::fake();
        Http::fake();

        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $fieldStartedAt = now()->subMinute();
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => null,
            'data_collection_started_at' => $fieldStartedAt->copy()->subMinute(),
            'data_collection_current_field_started_at' => $fieldStartedAt,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'message_parameter' => null,
            'text' => 'Как вас зовут?',
            'received_at' => $fieldStartedAt,
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 904,
            text: 'Герман',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->latest('id')->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->contactId === $storedMessage->contact_id
                && $job->expectedField === Contact::DATA_COLLECTION_FIELD_FIRST_NAME;
        });
        Queue::assertNotPushed(ProcessDataCollectionQuestionJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_pending_question_queued',
        ]);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_does_not_requeue_collector_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];
        $payload = $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 902,
            text: 'Герман',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertPushed(ProcessDataCollectionResponseJob::class, 1);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_active_age_range_callback_routes_to_collector_and_answers_callback(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-901',
            callbackData: 'age_range:24_29',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('24_29', $storedMessage->text);
        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-901');
    }

    public function test_stale_age_range_callback_is_answered_and_ignored(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-902',
            callbackData: 'age_range:24_29',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-902');
    }

    public function test_active_russian_region_confirm_callback_routes_to_collector_and_answers_callback(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-903',
            callbackData: 'russian_region_confirm:2',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('russian_region_confirm:2', $storedMessage->text);
        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-903');
    }

    public function test_stale_russian_region_confirm_callback_is_answered_and_ignored(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-904',
            callbackData: 'russian_region_confirm:2',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-904');
    }

    /**
     * @return array<string, mixed>
     */
    protected function telegramPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        int|string $messageId = 10,
        ?string $text = 'hello',
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
        bool $includeUpdateId = true,
    ): array {
        $payload = [
            'message' => [
                'message_id' => $messageId,
                'date' => $date,
                'text' => $text,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
            ],
        ];

        if ($includeUpdateId) {
            $payload['update_id'] = $messageId;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function telegramCallbackPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        string $callbackId = 'callback-1',
        string $callbackData = 'age_range:24_29',
        int|string $messageId = 10,
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
        bool $includeUpdateId = true,
    ): array {
        $payload = [
            'callback_query' => [
                'id' => $callbackId,
                'data' => $callbackData,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => $messageId,
                    'date' => $date,
                    'chat' => [
                        'id' => $chatId,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        if ($includeUpdateId) {
            $payload['update_id'] = $messageId;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function telegramMyChatMemberPayload(
        int|string $userId = 200,
        int|string $chatId = 200,
        string $oldStatus = 'member',
        string $newStatus = 'kicked',
        int $date = 1_711_539_200,
        int|string $updateId = 2010,
        ?string $username = 'telegram_user',
    ): array {
        return [
            'update_id' => $updateId,
            'my_chat_member' => [
                'date' => $date,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
                'old_chat_member' => [
                    'status' => $oldStatus,
                ],
                'new_chat_member' => [
                    'status' => $newStatus,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function maxPayload(
        int|string $userId = 500,
        int|string $chatId = 700,
        int|string $messageId = 'max-10',
        ?string $text = 'hello',
        ?string $username = 'max_user',
        string $timestamp = '2026-03-27T12:00:00+03:00',
    ): array {
        return [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'timestamp' => $timestamp,
            'message' => [
                'message_id' => $messageId,
                'timestamp' => $timestamp,
                'sender' => [
                    'user_id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => $chatId,
                ],
                'body' => [
                    'text' => $text,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function maxBotStartedPayload(
        int|string $userId = 500,
        int|string $chatId = 700,
        ?string $payload = 'promo_123',
        string $timestamp = '2026-04-03T10:00:00+03:00',
    ): array {
        $update = [
            'update_type' => 'bot_started',
            'chat_id' => $chatId,
            'timestamp' => $timestamp,
            'user' => [
                'user_id' => $userId,
                'username' => 'max_user',
                'name' => 'Герман',
            ],
        ];

        if ($payload !== null) {
            $update['payload'] = $payload;
        }

        return $update;
    }

    /**
     * @param  array<string, mixed>|null  $schemaPayload
     */
    protected function createPublishedScenario(string $code, ?array $schemaPayload = null): Scenario
    {
        $scenario = Scenario::query()->create([
            'code' => $code,
            'name' => 'VIP Ibiza',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => $schemaPayload ?? [
                'version' => 1,
                'start_block_id' => 'welcome',
                'triggers' => [
                    [
                        'type' => 'parameter',
                        'value' => $code,
                    ],
                ],
                'blocks' => [
                    'welcome' => [
                        'type' => 'message',
                        'text' => 'Добро пожаловать',
                        'next' => 'end',
                    ],
                    'end' => [
                        'type' => 'complete',
                    ],
                ],
            ],
        ]);

        return $scenario->fresh('publishedVersion');
    }

    protected function assertMessageDirectionCount(string $direction, int $expectedCount): void
    {
        $this->assertSame(
            $expectedCount,
            Message::query()->where('direction', $direction)->count(),
        );
    }

    protected function inboundMessages()
    {
        return Message::query()->where('direction', Message::DIRECTION_INBOUND);
    }

    protected function outboundMessages()
    {
        return Message::query()->where('direction', Message::DIRECTION_OUTBOUND);
    }
}
