<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24OpenLinesInboundBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.features.openlines_enabled', true);
        config()->set('bitrix24.openlines.telegram_connector_code', 'abrikosoff_telegram');
        config()->set('bitrix24.openlines.telegram_line_id', 'line-telegram');
        config()->set('bitrix24.openlines.max_connector_code', 'abrikosoff_max');
        config()->set('bitrix24.openlines.max_line_id', 'line-max');
        config()->set('bitrix24.openlines.session_finish_event_names', ['OnSessionFinish']);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_openlines_operator_message_is_delivered_to_telegram_stored_locally_and_acked(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7001,
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-1',
                        'message_id' => 'bitrix-im-101',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Ответ оператора из Bitrix',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertNotNull($dialog->bitrix24_live_last_imported_at);

        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-101',
            'external_message_id' => '7001',
            'text' => 'Ответ оператора из Bitrix',
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage'
                && $request['chat_id'] === 'telegram-chat-100'
                && $request['text'] === 'Ответ оператора из Bitrix';
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_telegram'
                && ($payload['LINE'] ?? null) === 'line-telegram'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['im']['message_id'] ?? null) === 'bitrix-im-101'
                && ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === '7001';
        });
    }

    public function test_openlines_operator_message_is_delivered_to_max_and_acked(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createMaxLiveDialog();

        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'body' => [
                        'mid' => 'max-mid-1',
                    ],
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_max',
                'LINE' => 'line-max',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-max-chat',
                        'message_id' => 'bitrix-im-max-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Ответ оператора в MAX',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-max-1',
            'external_message_id' => 'max-mid-1',
            'text' => 'Ответ оператора в MAX',
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
        ]);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
                && $request['text'] === 'Ответ оператора в MAX';
        });
    }

    public function test_blocked_telegram_dialog_sends_feedback_to_openlines_and_acks_without_transport_or_failed_status(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-blocked-tg',
                        'message_id' => 'bitrix-im-blocked-tg',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Не должно уйти в Telegram',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertNotNull($dialog->bitrix24_live_last_imported_at);
        $this->assertDatabaseMissing('messages', [
            'channel_id' => $dialog->channel_id,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-blocked-tg',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_message_skipped_blocked_dialog',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_telegram'
                && ($payload['LINE'] ?? null) === 'line-telegram'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Система: Сообщение не отправлено. Клиент заблокировал бота.';
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_telegram'
                && ($payload['LINE'] ?? null) === 'line-telegram'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === 'abrikosoff-openlines-blocked:bitrix-im-blocked-tg';
        });

        Http::assertSentCount(2);
    }

    public function test_blocked_max_dialog_sends_feedback_to_openlines_and_acks_without_transport_or_failed_status(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createMaxLiveDialog();
        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_max',
                'LINE' => 'line-max',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-blocked-max',
                        'message_id' => 'bitrix-im-blocked-max',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Не должно уйти в MAX',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertNotNull($dialog->bitrix24_live_last_imported_at);
        $this->assertDatabaseMissing('messages', [
            'channel_id' => $dialog->channel_id,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-blocked-max',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_message_skipped_blocked_dialog',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_max'
                && ($payload['LINE'] ?? null) === 'line-max'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Система: Сообщение не отправлено. Клиент заблокировал бота.';
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_max'
                && ($payload['LINE'] ?? null) === 'line-max'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === 'abrikosoff-openlines-blocked:bitrix-im-blocked-max';
        });

        Http::assertSentCount(2);
    }

    public function test_max_suspended_transport_response_marks_dialog_blocked_and_sends_feedback_to_openlines(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createMaxLiveDialog();

        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'code' => 'chat.denied',
                'message' => 'Key: error.dialog.suspended, args: [228532008,].',
            ], 403),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_max',
                'LINE' => 'line-max',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-max-suspended',
                        'message_id' => 'bitrix-im-max-suspended',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Попытка в заблокированный MAX',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER, $dialog->bot_subscription_status);
        $this->assertNotNull($dialog->bot_subscription_changed_at);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseMissing('messages', [
            'channel_id' => $dialog->channel_id,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-max-suspended',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_message_skipped_blocked_dialog',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
                && str_contains($request->url(), 'chat_id=max-chat-100')
                && $request['text'] === 'Попытка в заблокированный MAX';
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_max'
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Система: Сообщение не отправлено. Клиент заблокировал бота.';
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_max'
                && ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === 'abrikosoff-openlines-blocked:bitrix-im-max-suspended';
        });

        Http::assertSentCount(3);
    }

    public function test_blocked_dialog_feedback_reactivates_closed_and_failed_live_bridge_statuses(): void
    {
        $connection = $this->makeActiveConnection();

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        foreach ([
            Dialog::BITRIX24_LIVE_STATUS_CLOSED => 'bitrix-im-blocked-reopen-closed',
            Dialog::BITRIX24_LIVE_STATUS_FAILED => 'bitrix-im-blocked-reopen-failed',
        ] as $previousLiveStatus => $bitrixMessageId) {
            $dialog = $this->createTelegramLiveDialog();
            $dialog->forceFill([
                'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
                'bitrix24_live_status' => $previousLiveStatus,
            ])->save();

            $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
                'data' => [
                    'CONNECTOR' => 'abrikosoff_telegram',
                    'LINE' => 'line-telegram',
                    'DATA' => [[
                        'im' => [
                            'chat_id' => 'bitrix-chat-'.$bitrixMessageId,
                            'message_id' => $bitrixMessageId,
                        ],
                        'chat' => [
                            'id' => 'abrikosoff-dialog:'.$dialog->id,
                        ],
                        'message' => [
                            'text' => 'Blocked dialog should reactivate live bridge status',
                        ],
                    ]],
                ],
            ]);

            $this->runWebhookEventJob($event);

            $event->refresh();
            $dialog->refresh();

            $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
            $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
            $this->assertNotNull($dialog->bitrix24_live_last_imported_at);
            $this->assertDatabaseHas('bitrix24_sync_logs', [
                'operation' => 'openlines_dialog_reopened',
                'entity_type' => 'dialog',
                'entity_id' => (string) $dialog->id,
                'status' => 'success',
            ]);
        }
    }

    public function test_blocked_dialog_retry_does_not_repeat_feedback_after_ack_failure(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
        ])->save();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-blocked-retry',
                        'message_id' => 'bitrix-im-blocked-retry',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Повтор blocked operator attempt',
                    ],
                ]],
            ],
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'error' => 'ERROR_ARGUMENT',
                'error_description' => "Argument 'MESSAGES' is null or empty",
            ], 200),
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame("Argument 'MESSAGES' is null or empty", $event->failure_reason);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_blocked_feedback_sent',
            'entity_type' => 'openlines_blocked_attempt',
            'entity_id' => 'abrikosoff-openlines-blocked:bitrix-im-blocked-retry',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_blocked_feedback_ack_failed',
            'entity_type' => 'openlines_blocked_attempt',
            'entity_id' => 'abrikosoff-openlines-blocked:bitrix-im-blocked-retry',
            'status' => 'failed',
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json';
        });
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json';
        });

        $event->forceFill([
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_blocked_feedback_ack_sent',
            'entity_type' => 'openlines_blocked_attempt',
            'entity_id' => 'abrikosoff-openlines-blocked:bitrix-im-blocked-retry',
            'status' => 'success',
        ]);

        Http::assertNotSent(function (Request $request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json';
        });
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === 'abrikosoff-openlines-blocked:bitrix-im-blocked-retry';
        });
    }

    public function test_duplicate_openlines_callback_does_not_resend_to_messenger_and_still_acks(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        $existingMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-dup-1',
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'telegram-existing-1',
            'text' => 'Старый импорт',
            'received_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-dup',
                        'message_id' => 'bitrix-im-dup-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Дубль из Bitrix',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(1, Message::query()
            ->where('provider_event_key', 'bitrix24-openlines:bitrix-im-dup-1')
            ->count());

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), 'api.telegram.org');
        });
        Http::assertSent(function (Request $request) use ($existingMessage): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === $existingMessage->external_message_id;
        });
    }

    public function test_legacy_phase_1a_exact_echo_callback_is_acked_and_not_delivered_to_messenger(): void
    {
        $this->addLegacyPhaseOneAMessageExportColumns();

        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $manualReply = $this->createLegacyManualReplyWithLiveExport(
            dialog: $dialog,
            text: 'Legacy manual reply',
            externalMessageId: 'telegram-legacy-echo-1',
            remoteMessageId: 'bitrix-im-legacy-echo-1',
        );

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-legacy-echo',
                        'message_id' => 'bitrix-im-legacy-echo-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Legacy manual reply',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(2, Message::query()->count());
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_legacy_exact_echo_skipped',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'success',
        ]);

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), 'api.telegram.org');
        });
        Http::assertSent(function (Request $request) use ($manualReply): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['im']['message_id'] ?? null) === 'bitrix-im-legacy-echo-1'
                && ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === $manualReply->external_message_id;
        });
    }

    public function test_legacy_delayed_recheck_event_is_drained_without_delivery(): void
    {
        $this->addLegacyPhaseOneAWebhookEventColumns();

        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        Http::fake();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-legacy-recheck',
                        'message_id' => 'bitrix-im-legacy-recheck-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Legacy delayed recheck',
                    ],
                ]],
            ],
        ]);

        $event->forceFill([
            'recheck_scheduled_at' => now()->subSecond(),
            'recheck_attempted_at' => null,
        ])->save();

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(1, Message::query()->count());
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_legacy_recheck_dropped',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);

        Http::assertNothingSent();
    }

    public function test_failed_delivery_ack_marks_event_failed_after_message_is_stored(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7002,
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'error' => 'ERROR_ARGUMENT',
                'error_description' => "Argument 'MESSAGES' is null or empty",
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-ack-fail',
                        'message_id' => 'bitrix-im-ack-fail',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Сообщение с падающим ack',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame("Argument 'MESSAGES' is null or empty", $event->failure_reason);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_FAILED, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-ack-fail',
            'external_message_id' => '7002',
            'text' => 'Сообщение с падающим ack',
        ]);
    }

    public function test_invalid_chat_anchor_marks_openlines_event_as_failed(): void
    {
        $connection = $this->makeActiveConnection();

        Http::fake();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-invalid',
                        'message_id' => 'bitrix-im-invalid',
                    ],
                    'chat' => [
                        'id' => 'foreign-chat-anchor',
                    ],
                    'message' => [
                        'text' => 'Невалидный route',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertNotNull($event->failure_reason);
        Http::assertNothingSent();
    }

    public function test_session_finish_callback_marks_dialog_closed(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSessionFinish', [
            'data' => [
                'chat' => [
                    'id' => 'abrikosoff-dialog:'.$dialog->id,
                ],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_CLOSED, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_session_closed',
            'entity_type' => 'dialog',
            'entity_id' => (string) $dialog->id,
            'status' => 'success',
        ]);
    }

    public function test_session_finish_with_invalid_chat_anchor_is_ignored(): void
    {
        $connection = $this->makeActiveConnection();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSessionFinish', [
            'data' => [
                'chat' => [
                    'id' => 'foreign-chat-anchor',
                ],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_session_closed_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);
    }

    public function test_update_openlines_event_is_ignored_and_logged(): void
    {
        $connection = $this->makeActiveConnection();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnUpdateMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_message_update_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);
    }

    public function test_delete_openlines_event_is_ignored_and_logged(): void
    {
        $connection = $this->makeActiveConnection();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnDeleteMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_message_delete_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);
    }

    public function test_openlines_disabled_callback_is_ignored_and_logged(): void
    {
        $connection = $this->makeActiveConnection();
        config()->set('bitrix24.features.openlines_enabled', false);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-disabled',
                        'message_id' => 'bitrix-im-disabled',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:999',
                    ],
                    'message' => [
                        'text' => 'Не должно обрабатываться',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_disabled_callback_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);
    }

    public function test_closed_dialog_is_reactivated_by_next_inbound_operator_message(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $dialog->forceFill([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_CLOSED,
        ])->save();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 8101,
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-reopen',
                        'message_id' => 'bitrix-im-reopen',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Возвращаем bridge в active',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $dialog->refresh();

        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertSame('abrikosoff-dialog:'.$dialog->id, $dialog->bitrix24_live_chat_id);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_dialog_reopened',
            'entity_type' => 'dialog',
            'entity_id' => (string) $dialog->id,
            'status' => 'success',
        ]);
    }

    public function test_missing_external_message_id_from_messenger_marks_event_and_dialog_failed(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [],
            ]),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-no-id',
                        'message_id' => 'bitrix-im-no-id',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Нет внешнего id',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_FAILED, $dialog->bitrix24_live_status);
    }

    public function test_imported_openlines_message_is_skipped_by_live_export_queue_guard(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 8001,
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-echo',
                        'message_id' => 'bitrix-im-echo-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Сообщение для echo guard',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $storedMessage = Message::query()
            ->where('provider_event_key', 'bitrix24-openlines:bitrix-im-echo-1')
            ->firstOrFail();

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($storedMessage);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->ready);
    }

    private function runWebhookEventJob(Bitrix24WebhookEvent $event): void
    {
        $job = new ProcessBitrix24WebhookEventJob($event->id);

        app()->call([$job, 'handle']);
    }

    private function createTelegramLiveDialog(): Dialog
    {
        $contact = Contact::factory()->create([
            'name' => 'Bitrix Telegram Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'B24-CONTACT-TG-1',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-live-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-100',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-100',
            'bitrix24_live_chat_id' => 'abrikosoff-dialog:1',
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ]);

        $dialog->forceFill([
            'bitrix24_live_chat_id' => 'abrikosoff-dialog:'.$dialog->id,
        ])->save();

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'telegram-inbound-1',
            'text' => 'Исходное входящее',
            'received_at' => now()->subMinute(),
        ]);

        return $dialog->fresh(['contact', 'channel', 'currentContactIdentity']);
    }

    private function createMaxLiveDialog(): Dialog
    {
        $contact = Contact::factory()->create([
            'name' => 'Bitrix MAX Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'B24-CONTACT-MAX-1',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-live-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-user-100',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-chat-100',
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ]);

        $dialog->forceFill([
            'bitrix24_live_chat_id' => 'abrikosoff-dialog:'.$dialog->id,
        ])->save();

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'max-inbound-1',
            'text' => 'Исходное входящее в MAX',
            'received_at' => now()->subMinute(),
        ]);

        return $dialog->fresh(['contact', 'channel', 'currentContactIdentity']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeOpenlinesWebhookEvent(
        Bitrix24Connection $connection,
        string $eventName,
        array $payload,
    ): Bitrix24WebhookEvent {
        return Bitrix24WebhookEvent::query()->create([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_OPENLINES,
            'event_name' => $eventName,
            'member_id' => $connection->member_id,
            'application_token' => 'app-token',
            'portal_domain' => $connection->portal_domain,
            'payload_hash' => hash('sha256', json_encode([$eventName, $payload], JSON_THROW_ON_ERROR)),
            'payload' => $payload,
            'headers' => [],
            'query' => [],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
            'attempts' => 0,
        ]);
    }

    private function makeActiveConnection(): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.app',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'secret-access-token',
            'refresh_token_encrypted' => 'secret-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['imconnector', 'imopenlines'],
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'install_payload' => [],
            'installed_at' => now(),
        ]);
    }

    private function addLegacyPhaseOneAMessageExportColumns(): void
    {
        if (Schema::hasColumn('bitrix24_message_exports', 'transport_method')) {
            return;
        }

        Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
            $table->string('transport_method')->nullable();
            $table->string('resolved_bitrix_chat_id')->nullable();
            $table->string('bitrix_remote_message_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->boolean('failure_uncertain')->default(false);
        });
    }

    private function addLegacyPhaseOneAWebhookEventColumns(): void
    {
        if (Schema::hasColumn('bitrix24_webhook_events', 'recheck_scheduled_at')) {
            return;
        }

        Schema::table('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->timestamp('recheck_scheduled_at')->nullable();
            $table->timestamp('recheck_attempted_at')->nullable();
        });
    }

    private function createLegacyManualReplyWithLiveExport(
        Dialog $dialog,
        string $text,
        string $externalMessageId,
        string $remoteMessageId,
    ): Message {
        $dialog->loadMissing('contact');

        $message = Message::query()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'provider_event_key' => null,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => $externalMessageId,
            'text' => $text,
            'received_at' => now(),
        ]);

        Bitrix24MessageExport::query()->insert([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => (string) $dialog->contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => 'imopenlines.crm.message.add',
            'resolved_bitrix_chat_id' => 'bitrix-chat-local',
            'bitrix_remote_message_id' => $remoteMessageId,
            'batch_uuid' => null,
            'bitrix24_timeline_entry_id' => null,
            'exported_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
            'failure_code' => null,
            'failure_uncertain' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $message;
    }
}
