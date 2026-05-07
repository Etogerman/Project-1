<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\ProcessBitrix24OpenLinesWebhookAction;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\Bitrix24\ResolveCurrentBitrix24ProfileAction;
use App\Services\Bitrix24\StoreBitrix24OpenLinesOutboundMessageAction;
use App\Services\Bots\ChannelWebhookUrlGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24OpenLinesInboundBridgeTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
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

        Http::assertSent(function (Request $request) use ($dialog): bool {
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

    public function test_openlines_operator_message_from_recent_successful_send_chat_is_delivered_when_newer_current_chat_exists(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->makeDialogContactNumeric($this->createTelegramLiveDialog());
        $route = Bitrix24OpenLineRoute::query()->findOrFail($dialog->bitrix24_open_line_route_id);
        $sentAt = now();

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => implode('|', [
                $route->connector_code,
                $route->line_id,
                'abrikosoff-dialog:'.$dialog->id,
                '19',
            ]),
            'bitrix24_open_line_resolved_chat_id_override' => '26',
            'bitrix24_open_line_binding_verified_at' => $sentAt,
            'bitrix24_live_last_exported_at' => $sentAt,
        ])->save();
        $this->seedSuccessfulInboundClientTransportExport(
            $dialog,
            '23',
            'Клиентское сообщение, принятое Bitrix в chat 23',
            $sentAt,
        );

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7101,
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
                        'chat_id' => 23,
                        'message_id' => 616,
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Ответ из chat успешной отправки',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(
            'abrikosoff_telegram|line-telegram|abrikosoff-dialog:'.$dialog->id.'|19',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('26', $dialog->bitrix24_open_line_resolved_chat_id_override);

        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:616',
            'external_message_id' => '7101',
            'text' => 'Ответ из chat успешной отправки',
        ]);

        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'operation' => 'openlines_stale_chat_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
        ]);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json');
    }

    public function test_openlines_operator_message_from_mutable_binding_chat_is_ignored_when_last_successful_send_used_other_chat(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->makeDialogContactNumeric($this->createTelegramLiveDialog());
        $route = Bitrix24OpenLineRoute::query()->findOrFail($dialog->bitrix24_open_line_route_id);
        $sentAt = now();

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => implode('|', [
                $route->connector_code,
                $route->line_id,
                'abrikosoff-dialog:'.$dialog->id,
                '15',
            ]),
            'bitrix24_open_line_resolved_chat_id_override' => '23',
            'bitrix24_open_line_binding_verified_at' => $sentAt,
            'bitrix24_live_last_exported_at' => $sentAt,
        ])->save();
        $this->seedSuccessfulInboundClientTransportExport(
            $dialog,
            '26',
            'Клиентское сообщение, принятое Bitrix в chat 26',
            $sentAt,
        );

        Http::fake(array_merge($this->currentOpenLineLookupFakes($dialog, [
            '15' => 23,
            '19' => 26,
        ]), [
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7103,
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]));

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 23,
                        'message_id' => 618,
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Ответ из mutable binding chat',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(
            'abrikosoff_telegram|line-telegram|abrikosoff-dialog:'.$dialog->id.'|19',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('26', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:618',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_stale_chat_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/'));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json');
    }

    public function test_openlines_inbound_client_echo_from_successful_send_chat_is_skipped(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->makeDialogContactNumeric($this->createTelegramLiveDialog());
        $sentAt = now();

        $this->seedSuccessfulInboundClientTransportExport(
            $dialog,
            '23',
            'Текст клиента для Bitrix',
            $sentAt,
        );

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7104,
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
                        'chat_id' => 23,
                        'message_id' => 619,
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Текст клиента для Bitrix',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:619',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_inbound_echo_skipped',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
        ]);

        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/'));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
    }

    public function test_openlines_operator_message_refreshes_verified_binding_after_current_chat_lookup(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->makeDialogContactNumeric($this->createTelegramLiveDialog());
        $route = Bitrix24OpenLineRoute::query()->findOrFail($dialog->bitrix24_open_line_route_id);
        $oldVerifiedAt = now()->subHours(2);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => implode('|', [
                $route->connector_code,
                $route->line_id,
                'abrikosoff-dialog:'.$dialog->id,
                '15',
            ]),
            'bitrix24_open_line_resolved_chat_id_override' => '23',
            'bitrix24_open_line_binding_verified_at' => $oldVerifiedAt,
        ])->save();

        Http::fake(array_merge($this->currentOpenLineLookupFakes($dialog, [
            '15' => 23,
        ]), [
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7102,
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]));

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 23,
                        'message_id' => 617,
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Ответ после обновления verified binding',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertTrue($dialog->bitrix24_open_line_binding_verified_at->greaterThan($oldVerifiedAt));
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:617',
            'external_message_id' => '7102',
            'text' => 'Ответ после обновления verified binding',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage');
    }

    public function test_openlines_operator_message_from_old_source_chat_is_ignored_when_newer_current_chat_exists(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->makeDialogContactNumeric($this->createTelegramLiveDialog());

        Http::fake(array_merge($this->currentOpenLineLookupFakes($dialog, [
            '6' => 8,
            '15' => 23,
            '19' => 26,
        ]), [
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7200,
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]));

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 23,
                        'message_id' => 614,
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => '2',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(
            'abrikosoff_telegram|line-telegram|abrikosoff-dialog:'.$dialog->id.'|19',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('26', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);

        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:614',
        ]);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_stale_chat_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);

        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/'));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json');
    }

    public function test_openlines_operator_message_uses_canonical_chat_when_higher_chat_belongs_to_another_contact(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->makeDialogContactNumeric($this->createTelegramLiveDialog());

        Http::fake(array_merge($this->currentOpenLineLookupFakes($dialog, [
            '6' => 8,
            '15' => 23,
            '19' => 26,
        ], invalidConnectorUsers: ['19']), [
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7201,
                ],
            ]),
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::response([
                'result' => true,
            ], 200),
        ]));

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 23,
                        'message_id' => 615,
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Ответ из актуальной ОЛ после invalid newest',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(
            'abrikosoff_telegram|line-telegram|abrikosoff-dialog:'.$dialog->id.'|15',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('23', $dialog->bitrix24_open_line_resolved_chat_id_override);

        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:615',
            'external_message_id' => '7201',
            'text' => 'Ответ из актуальной ОЛ после invalid newest',
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage'
                && $request['chat_id'] === 'telegram-chat-100'
                && $request['text'] === 'Ответ из актуальной ОЛ после invalid newest';
        });
    }

    public function test_openlines_operator_message_rebinds_old_unpinned_dialog_to_matching_route(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $routeId = $dialog->bitrix24_open_line_route_id;

        $dialog->forceFill([
            'bitrix24_open_line_route_id' => null,
        ])->save();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7101,
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
                        'chat_id' => 'bitrix-chat-old-dialog',
                        'message_id' => 'bitrix-im-old-dialog',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Ответ в старый диалог',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame($routeId, $dialog->bitrix24_open_line_route_id);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-old-dialog',
            'external_message_id' => '7101',
            'text' => 'Ответ в старый диалог',
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage'
                && $request['chat_id'] === 'telegram-chat-100'
                && $request['text'] === 'Ответ в старый диалог';
        });
    }

    public function test_openlines_operator_message_is_ignored_when_callback_route_does_not_match_current_profile(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        Http::fake();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'foreign_connector',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-1',
                        'message_id' => 'bitrix-im-foreign-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Чужой маршрут',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->failed_at);
        $this->assertNull($event->failure_reason);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_route_mismatch_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'skipped',
        ]);
        $this->assertDatabaseMissing('messages', [
            'channel_id' => $dialog->channel_id,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-foreign-1',
        ]);

        Http::assertNothingSent();
    }

    public function test_openlines_operator_message_is_ignored_without_connector_or_line(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        Http::fake();

        foreach ([
            'missing-connector' => [
                'LINE' => 'line-telegram',
            ],
            'missing-line' => [
                'CONNECTOR' => 'abrikosoff_telegram',
            ],
        ] as $suffix => $routeFields) {
            $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
                'data' => array_merge($routeFields, [
                    'DATA' => [[
                        'im' => [
                            'chat_id' => 'bitrix-chat-'.$suffix,
                            'message_id' => 'bitrix-im-'.$suffix,
                        ],
                        'chat' => [
                            'id' => 'abrikosoff-dialog:'.$dialog->id,
                        ],
                        'message' => [
                            'text' => 'Неполный маршрут',
                        ],
                    ]],
                ]),
            ]);

            $this->runWebhookEventJob($event);

            $event->refresh();

            $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
            $this->assertNotNull($event->processed_at);
            $this->assertDatabaseMissing('messages', [
                'channel_id' => $dialog->channel_id,
                'provider_event_key' => 'bitrix24-openlines:bitrix-im-'.$suffix,
            ]);
        }

        $this->assertSame(2, Bitrix24SyncLog::query()
            ->where('operation', 'openlines_route_mismatch_ignored')
            ->count());
        Http::assertNothingSent();
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

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_telegram'
                && ($payload['LINE'] ?? null) === 'line-telegram'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === 'telegram_bot:channel:'.$dialog->channel_id.':user:telegram-user-'.$dialog->contact_id
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Система: Сообщение не отправлено. Клиент заблокировал бота.';
        });

        Http::assertSent(function (Request $request) use ($dialog): bool {
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

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_max'
                && ($payload['LINE'] ?? null) === 'line-max'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Система: Сообщение не отправлено. Клиент заблокировал бота.';
        });

        Http::assertSent(function (Request $request) use ($dialog): bool {
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
            'https://client-endpoint.example/rest/imconnector.send.status.delivery.json' => Http::sequence()
                ->push([
                    'error' => 'ERROR_ARGUMENT',
                    'error_description' => "Argument 'MESSAGES' is null or empty",
                ], 200)
                ->push([
                    'result' => true,
                ], 200),
        ]);

        $this->runWebhookEventJob($event, finalAttempt: true);

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

        $blockedFeedbackRequests = Http::recorded(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');

        $this->assertCount(1, $blockedFeedbackRequests);
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

        $this->runWebhookEventJob($event, finalAttempt: true);

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

    public function test_retryable_rerun_after_store_failure_reuses_logged_delivery_without_resending_to_messenger(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7003,
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
                        'chat_id' => 'bitrix-chat-store-retry',
                        'message_id' => 'bitrix-im-store-retry',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Сообщение с падением store',
                    ],
                ]],
            ],
        ]);

        $storeAction = Mockery::mock(StoreBitrix24OpenLinesOutboundMessageAction::class);
        $storeAction->shouldReceive('handle')
            ->once()
            ->andThrow(new \RuntimeException('Store exploded after delivery.'));
        $this->app->instance(StoreBitrix24OpenLinesOutboundMessageAction::class, $storeAction);

        try {
            $this->runWebhookEventJob($event);
            $this->fail('Expected webhook event job to bubble the retryable store exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Store exploded after delivery.', $exception->getMessage());
        }

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertDatabaseMissing('messages', [
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-store-retry',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_message_delivery_sent',
            'entity_type' => 'openlines_delivery_phase',
            'entity_id' => 'bitrix-im-store-retry',
            'status' => 'success',
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage'
                && $request['chat_id'] === 'telegram-chat-100'
                && $request['text'] === 'Сообщение с падением store';
        });

        $this->app->forgetInstance(StoreBitrix24OpenLinesOutboundMessageAction::class);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-store-retry',
            'external_message_id' => '7003',
            'text' => 'Сообщение с падением store',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_message_delivery_resumed',
            'entity_type' => 'openlines_delivery_phase',
            'entity_id' => 'bitrix-im-store-retry',
            'status' => 'skipped',
        ]);

        $telegramDeliveries = Http::recorded(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage'
            && $request['text'] === 'Сообщение с падением store');

        $this->assertCount(1, $telegramDeliveries);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.status.delivery.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['im']['message_id'] ?? null) === 'bitrix-im-store-retry'
                && ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === '7003';
        });
    }

    public function test_foreign_portal_delivery_phase_log_is_not_reused_for_current_event(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $foreignConnection = $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: [
                'portal_domain' => 'foreign.bitrix24.ru',
                'member_id' => 'member-foreign',
                'application_token' => 'foreign-app-token',
                'status' => Bitrix24Connection::STATUS_INVALID,
                'access_token_encrypted' => 'foreign-access-token',
                'refresh_token_encrypted' => 'foreign-refresh-token',
                'scope' => ['imconnector', 'imopenlines'],
            ],
            profileOverrides: [
                'portal_domain' => 'foreign.bitrix24.ru',
                'profile_key' => 'foreign-portal',
                'callback_base_url' => 'https://foreign-project.example.com',
            ],
            useForCurrentRuntime: false,
        );

        $chatId = 'bitrix-chat-portal-scope';
        $bitrixMessageId = 'bitrix-im-portal-scope-1';

        Bitrix24SyncLog::query()->create([
            'connection_id' => $foreignConnection->id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_message_delivery_sent',
            'entity_type' => 'openlines_delivery_phase',
            'entity_id' => $bitrixMessageId,
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'response_payload' => [
                'external_message_id' => 'foreign-7004',
                'text' => 'Сообщение из другого портала',
                'raw_payload' => ['foreign' => true],
            ],
            'fingerprint' => hash('sha256', implode('|', [
                (string) $foreignConnection->id,
                $foreignConnection->portal_domain,
                $chatId,
                $bitrixMessageId,
                'abrikosoff_telegram',
                'line-telegram',
            ])),
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 7004,
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
                        'chat_id' => $chatId,
                        'message_id' => $bitrixMessageId,
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'Сообщение из другого портала',
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:'.$bitrixMessageId,
            'external_message_id' => '7004',
            'text' => 'Сообщение из другого портала',
        ]);
        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:'.$bitrixMessageId,
            'external_message_id' => 'foreign-7004',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'connection_id' => $connection->id,
            'operation' => 'openlines_message_delivery_sent',
            'entity_type' => 'openlines_delivery_phase',
            'entity_id' => $bitrixMessageId,
            'status' => 'success',
        ]);
        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'connection_id' => $connection->id,
            'operation' => 'openlines_message_delivery_resumed',
            'entity_type' => 'openlines_delivery_phase',
            'entity_id' => $bitrixMessageId,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage'
                && $request['chat_id'] === 'telegram-chat-100'
                && $request['text'] === 'Сообщение из другого портала';
        });
    }

    public function test_exact_echo_callback_is_skipped_and_acked_using_existing_manual_reply(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $manualReply = $this->createManualReplyWithLiveExport(
            dialog: $dialog,
            text: 'Эхо manual reply',
            externalMessageId: 'telegram-manual-echo-1',
            exportStatus: Bitrix24MessageExport::STATUS_EXPORTED,
            transportMethod: Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            remoteMessageId: 'bitrix-im-echo-1',
        );

        Http::fake([
            'https://api.telegram.org/*' => Http::response([], 500),
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
                        'text' => 'Эхо manual reply',
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
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_exact_echo_skipped',
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

            return ($payload['MESSAGES'][0]['im']['message_id'] ?? null) === 'bitrix-im-echo-1'
                && ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === $manualReply->external_message_id;
        });
    }

    public function test_suspicious_echo_candidate_is_delayed_without_delivery_or_ack(): void
    {
        Queue::fake();

        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $this->createManualReplyWithLiveExport(
            dialog: $dialog,
            text: "Привет\r\nмир",
            externalMessageId: 'telegram-manual-pending-1',
            exportStatus: Bitrix24MessageExport::STATUS_PENDING,
        );

        Http::fake();

        $event = $this->makeOpenlinesWebhookEvent($connection, 'OnSendMessageCustom', [
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'LINE' => 'line-telegram',
                'DATA' => [[
                    'im' => [
                        'chat_id' => 'bitrix-chat-pending',
                        'message_id' => 'bitrix-im-pending-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => "Привет\nмир",
                    ],
                ]],
            ],
        ]);

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertNotNull($event->recheck_scheduled_at);
        $this->assertNull($event->recheck_attempted_at);
        $this->assertSame(0, $event->attempts);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_delayed_recheck_scheduled',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'success',
        ]);

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, function (ProcessBitrix24WebhookEventJob $job) use ($event): bool {
            return $job->webhookEventId === $event->id
                && $job->delay !== null;
        });

        Http::assertNothingSent();
    }

    public function test_delayed_recheck_confirmed_echo_skips_delivery_and_acks_existing_manual_reply(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $manualReply = $this->createManualReplyWithLiveExport(
            dialog: $dialog,
            text: 'подтвержденное эхо',
            externalMessageId: 'telegram-manual-echo-2',
            exportStatus: Bitrix24MessageExport::STATUS_PENDING,
        );

        Http::fake([
            'https://api.telegram.org/*' => Http::response([], 500),
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
                        'chat_id' => 'bitrix-chat-recheck-echo',
                        'message_id' => 'bitrix-im-recheck-echo-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'подтвержденное эхо',
                    ],
                ]],
            ],
        ]);

        $event->forceFill([
            'recheck_scheduled_at' => now()->subDay(),
            'recheck_attempted_at' => null,
        ])->save();

        Bitrix24MessageExport::query()
            ->where('message_id', $manualReply->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->update([
                'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
                'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
                'bitrix_remote_message_id' => 'bitrix-im-recheck-echo-1',
                'exported_at' => now(),
            ]);

        $this->runWebhookEventJob($event);

        $event->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertNotNull($event->recheck_attempted_at);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertNotNull($dialog->bitrix24_live_last_imported_at);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_delayed_recheck_confirmed_echo',
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

            return ($payload['MESSAGES'][0]['im']['message_id'] ?? null) === 'bitrix-im-recheck-echo-1'
                && ($payload['MESSAGES'][0]['message']['id'][0] ?? null) === $manualReply->external_message_id;
        });
    }

    public function test_delayed_recheck_without_exact_match_falls_through_to_real_operator_reply(): void
    {
        $connection = $this->makeActiveConnection();
        $dialog = $this->createTelegramLiveDialog();
        $this->createManualReplyWithLiveExport(
            dialog: $dialog,
            text: 'тот же текст',
            externalMessageId: 'telegram-manual-fallthrough-1',
            exportStatus: Bitrix24MessageExport::STATUS_PENDING,
        );

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9001,
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
                        'chat_id' => 'bitrix-chat-recheck-fallthrough',
                        'message_id' => 'bitrix-im-recheck-fallthrough-1',
                    ],
                    'chat' => [
                        'id' => 'abrikosoff-dialog:'.$dialog->id,
                    ],
                    'message' => [
                        'text' => 'тот же текст',
                    ],
                ]],
            ],
        ]);

        $event->forceFill([
            'recheck_scheduled_at' => now()->subDay(),
            'recheck_attempted_at' => null,
        ])->save();

        $this->runWebhookEventJob($event);

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_delayed_recheck_fell_through',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'provider_event_key' => 'bitrix24-openlines:bitrix-im-recheck-fallthrough-1',
            'external_message_id' => '9001',
            'text' => 'тот же текст',
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-live-token/sendMessage'
                && $request['chat_id'] === 'telegram-chat-100'
                && $request['text'] === 'тот же текст';
        });
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

        $this->runWebhookEventJob($event, finalAttempt: true);

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

        $this->runWebhookEventJob($event, finalAttempt: true);

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

    private function runWebhookEventJob(Bitrix24WebhookEvent $event, bool $finalAttempt = false): void
    {
        $job = new ProcessBitrix24WebhookEventJob($event->id);

        if ($finalAttempt) {
            $job = $job->withFakeQueueInteractions();
            $job->job->attempts = $job->tries;
        }

        try {
            $job->handle(
                app(ProcessBitrix24OpenLinesWebhookAction::class),
                app(LogBitrix24ApiCallAction::class),
            );
        } catch (\Throwable $throwable) {
            if (! $finalAttempt) {
                throw $throwable;
            }

            $event->refresh();
            $event->forceFill([
                'processing_status' => Bitrix24WebhookEvent::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $throwable->getMessage(),
                'attempts' => $job->attempts(),
            ])->save();

            app(LogBitrix24ApiCallAction::class)->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_event_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'webhook_event_id' => $event->id,
                    'event_name' => $event->event_name,
                    'callback_type' => $event->callback_type,
                ],
                connection: $event->connection,
                errorMessage: $throwable->getMessage(),
                entityType: 'openlines_webhook_event',
                entityId: (string) $event->id,
            );

            $job->fail($throwable);
        }
    }

    private function createTelegramLiveDialog(): Dialog
    {
        $profile = $this->currentRuntimeBitrix24Profile();
        $contact = Contact::factory()->create([
            'name' => 'Bitrix Telegram Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'B24-CONTACT-TG-1',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $channel = $this->findChannelByOpenLineRoute($profile, 'abrikosoff_telegram', 'line-telegram');

        if (! $channel instanceof Channel) {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => ['token' => 'telegram-live-token'],
            ]);
        }

        $this->markTelegramChannelConnected($channel);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-'.$contact->id,
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

        $this->pinDialogOpenLineRoute($dialog, $profile, 'abrikosoff_telegram', 'line-telegram');

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

    private function makeDialogContactNumeric(Dialog $dialog, string $bitrix24ContactId = '9'): Dialog
    {
        $dialog->contact()->update([
            'bitrix24_contact_id' => $bitrix24ContactId,
        ]);

        return $dialog->fresh(['contact', 'channel', 'currentContactIdentity']);
    }

    private function pinDialogOpenLineRoute(
        Dialog $dialog,
        Bitrix24Profile $profile,
        string $connectorCode = 'abrikosoff_telegram',
        string $lineId = 'line-telegram',
    ): Bitrix24OpenLineRoute {
        $dialog->loadMissing('channel');

        $route = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $dialog->channel_id)
            ->first();

        if (! $route instanceof Bitrix24OpenLineRoute) {
            $route = Bitrix24OpenLineRoute::query()->create([
                'bitrix24_profile_id' => $profile->id,
                'channel_id' => $dialog->channel_id,
                'portal_domain' => $profile->portal_domain,
                'profile_key' => $profile->profile_key,
                'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($dialog->channel),
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'source_id' => $dialog->channel->platform === Channel::PLATFORM_MAX
                    ? $profile->max_source_id
                    : $profile->telegram_source_id,
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        } else {
            $route->forceFill([
                'source_id' => $dialog->channel->platform === Channel::PLATFORM_MAX
                    ? $profile->max_source_id
                    : $profile->telegram_source_id,
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ])->save();
        }

        $dialog->forceFill([
            'bitrix24_open_line_route_id' => $route->id,
        ])->save();

        return $route;
    }

    private function createMaxLiveDialog(): Dialog
    {
        $profile = $this->currentRuntimeBitrix24Profile();
        $contact = Contact::factory()->create([
            'name' => 'Bitrix MAX Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'B24-CONTACT-MAX-1',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $channel = $this->findChannelByOpenLineRoute($profile, 'abrikosoff_max', 'line-max');

        if (! $channel instanceof Channel) {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => ['token' => 'max-live-token'],
            ]);
        }
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-user-'.$contact->id,
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

        $this->pinDialogOpenLineRoute($dialog, $profile, 'abrikosoff_max', 'line-max');

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

    private function currentRuntimeBitrix24Profile(): Bitrix24Profile
    {
        return app(ResolveCurrentBitrix24ProfileAction::class)->handle();
    }

    private function findChannelByOpenLineRoute(
        Bitrix24Profile $profile,
        string $connectorCode,
        string $lineId,
    ): ?Channel {
        $route = Bitrix24OpenLineRoute::query()
            ->with('channel')
            ->where('bitrix24_profile_id', $profile->id)
            ->where('connector_code', $connectorCode)
            ->where('line_id', $lineId)
            ->where('status', Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->first();

        return $route instanceof Bitrix24OpenLineRoute
            ? $route->channel
            : null;
    }

    private function markTelegramChannelConnected(Channel $channel): void
    {
        if (! $channel->supportsConnectionCheck()) {
            return;
        }

        $webhookUrl = app(ChannelWebhookUrlGenerator::class)->for($channel);

        $channel->forceFill([
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now(),
            'connection_error_message' => null,
            'provider_webhook_url' => $webhookUrl,
            'expected_webhook_url' => $webhookUrl,
        ])->saveQuietly();
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

    /**
     * @param  array<int|string, int>  $chatIdsByConnectorUser
     * @param  list<string>  $invalidConnectorUsers
     * @return array<string, mixed>
     */
    private function currentOpenLineLookupFakes(
        Dialog $dialog,
        array $chatIdsByConnectorUser,
        array $invalidConnectorUsers = [],
    ): array {
        $dialog->loadMissing('contact');

        $route = Bitrix24OpenLineRoute::query()->findOrFail($dialog->bitrix24_open_line_route_id);
        $contactId = (string) $dialog->contact->bitrix24_contact_id;
        $connectorChatId = 'abrikosoff-dialog:'.$dialog->id;
        $userCodesByConnectorUser = [];

        foreach ($chatIdsByConnectorUser as $connectorUser => $chatId) {
            $userCodesByConnectorUser[$connectorUser] = implode('|', [
                $route->connector_code,
                $route->line_id,
                $connectorChatId,
                $connectorUser,
            ]);
        }

        return [
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'ID' => $contactId,
                    'IM' => array_map(
                        static fn (string $userCode): array => [
                            'VALUE_TYPE' => 'IMOL',
                            'VALUE' => 'imol|'.$userCode,
                        ],
                        array_values($userCodesByConnectorUser),
                    ),
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => function (Request $request) use (
                $chatIdsByConnectorUser,
                $contactId,
                $invalidConnectorUsers,
                $userCodesByConnectorUser,
            ) {
                $userCode = trim((string) $request['USER_CODE']);
                $connectorUser = array_search($userCode, $userCodesByConnectorUser, true);

                if ($connectorUser === false) {
                    return Http::response([
                        'error' => 'NOT_FOUND',
                        'error_description' => 'USER_CODE was not found.',
                    ], 404);
                }

                $connectorUser = (string) $connectorUser;

                $entityContactId = in_array($connectorUser, $invalidConnectorUsers, true)
                    ? '999'
                    : $contactId;

                return Http::response([
                    'result' => [
                        'id' => $chatIdsByConnectorUser[$connectorUser],
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|'.$entityContactId.'|DEAL|12',
                    ],
                ], 200);
            },
        ];
    }

    private function makeActiveConnection(): Bitrix24Connection
    {
        return $this->makeProfileLinkedActiveBitrix24Connection([
            'application_token' => 'app-token',
            'access_token_encrypted' => 'secret-access-token',
            'refresh_token_encrypted' => 'secret-refresh-token',
            'scope' => ['imconnector', 'imopenlines'],
        ]);
    }

    private function createManualReplyWithLiveExport(
        Dialog $dialog,
        string $text,
        string $externalMessageId,
        string $exportStatus,
        ?string $transportMethod = null,
        ?string $remoteMessageId = null,
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
            'raw_payload' => [],
            'received_at' => now(),
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => (string) $dialog->contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => $exportStatus,
            'transport_method' => $transportMethod,
            'resolved_bitrix_chat_id' => 'bitrix-chat-local',
            'bitrix_remote_message_id' => $remoteMessageId,
            'exported_at' => $exportStatus === Bitrix24MessageExport::STATUS_EXPORTED ? now() : null,
            'failed_at' => null,
            'failure_reason' => null,
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        return $message;
    }

    private function seedSuccessfulInboundClientTransportExport(
        Dialog $dialog,
        string $resolvedChatId,
        string $text,
        \DateTimeInterface $exportedAt,
    ): Message {
        $dialog->loadMissing('contact');

        $message = Message::query()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'provider_event_key' => null,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'telegram-inbound-export-'.$dialog->id.'-'.$resolvedChatId,
            'text' => $text,
            'raw_payload' => [],
            'received_at' => $exportedAt,
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => (string) $dialog->contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => $resolvedChatId,
            'bitrix_remote_message_id' => null,
            'exported_at' => $exportedAt,
            'failed_at' => null,
            'failure_reason' => null,
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        return $message;
    }
}
