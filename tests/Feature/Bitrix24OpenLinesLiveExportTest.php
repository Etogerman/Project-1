<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bots\IncomingBotMessage;
use App\Jobs\DedupeBitrix24ContactPhonesJob;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\LogBitrix24RawContactPhoneSnapshotJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Bitrix24\Bitrix24ApiException;
use App\Services\Bitrix24\Bitrix24ConnectionStateException;
use App\Services\Bitrix24\Bitrix24LiveExportTransportException;
use App\Services\Bitrix24\Bitrix24OpenLinesManualReplyExportException;
use App\Services\Bitrix24\BuildBitrix24OpenLinesMessagePayloadAction;
use App\Services\Bitrix24\DedupeBitrix24ContactPhonesAction;
use App\Services\Bitrix24\ExportMessageToBitrix24OpenLinesAction;
use App\Services\Bitrix24\LogBitrix24RawContactPhoneSnapshotAction;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\Bitrix24\ResolveCurrentBitrix24ProfileAction;
use App\Services\Bots\ChannelWebhookUrlGenerator;
use App\Services\Bots\SendManualDialogReplyAction;
use App\Services\Bots\StoreDataCollectionOutboundMessageAction;
use App\Services\Bots\StoreInboundMessageAction;
use App\Services\Bots\StoreOutboundAutoReplyMessageAction;
use App\Services\Bots\StorePhoneCaptureConfirmationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24OpenLinesLiveExportTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config()->set('app.timezone', 'Europe/Moscow');
        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.features.openlines_enabled', true);
        config()->set('bitrix24.openlines.telegram_connector_code', 'abrikosoff_telegram');
        config()->set('bitrix24.openlines.telegram_line_id', 'line-telegram');
        config()->set('bitrix24.openlines.max_connector_code', 'abrikosoff_max');
        config()->set('bitrix24.openlines.max_line_id', 'line-max');
        config()->set('bitrix24.openlines.service_user_id', 321);
        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', false);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_dialogs_table_has_bitrix24_live_fields_with_expected_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('dialogs', [
            'bitrix24_live_chat_id',
            'bitrix24_live_status',
            'bitrix24_open_line_user_code_override',
            'bitrix24_open_line_resolved_chat_id_override',
            'bitrix24_open_line_binding_verified_at',
            'bitrix24_live_last_exported_at',
            'bitrix24_live_last_imported_at',
        ]));

        $dialog = Dialog::factory()->create();
        $dialog->refresh();

        $this->assertNull($dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED, $dialog->bitrix24_live_status);
        $this->assertNull($dialog->bitrix24_open_line_user_code_override);
        $this->assertNull($dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertNull($dialog->bitrix24_live_last_exported_at);
        $this->assertNull($dialog->bitrix24_live_last_imported_at);
        $this->assertFalse($dialog->isBitrix24LiveActive());
    }

    public function test_store_inbound_message_queues_live_export_job_for_ready_bitrix_contact(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $channel = $dialog->channel()->firstOrFail();

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: (string) $dialog->external_chat_id,
                externalUserId: (string) $dialog->currentContactIdentity()->firstOrFail()->external_user_id,
                providerEventKey: 'tg-live-101',
                externalMessageId: 'ext-101',
                externalUsername: 'live_user',
                contactName: 'Live Contact',
                text: 'Привет из Telegram',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет из Telegram']],
                receivedAt: Carbon::parse('2026-04-01 12:00:00', 'Europe/Moscow'),
            ),
        );

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($storedResult): bool {
            return $job->messageId === $storedResult->message->id
                && $job->retryAfterSync === false;
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $storedResult->message->id,
            'contact_id' => $dialog->contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_outbound_store_actions_queue_live_export_jobs(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $contact = $dialog->contact()->firstOrFail();
        $channel = $dialog->channel()->firstOrFail();
        $identity = $dialog->currentContactIdentity()->firstOrFail();
        $inboundMessage = $this->makeMessage($dialog, [
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Входящее сообщение',
        ]);

        $autoReply = app(StoreOutboundAutoReplyMessageAction::class)->handle(
            $channel,
            $inboundMessage,
            new AutoReplyDeliveryResult(
                text: 'Автоответ',
                externalMessageId: 'auto-1',
                rawPayload: ['ok' => true],
            ),
        );

        $phoneCapture = app(StorePhoneCaptureConfirmationAction::class)->handle(
            $dialog,
            $inboundMessage,
            new AutoReplyDeliveryResult(
                text: 'Подтверждение телефона',
                externalMessageId: 'phone-1',
                rawPayload: ['ok' => true],
            ),
        );

        $collector = app(StoreDataCollectionOutboundMessageAction::class)->handle(
            $inboundMessage,
            new AutoReplyDeliveryResult(
                text: 'Следующий вопрос анкеты',
                externalMessageId: 'collector-1',
                rawPayload: ['ok' => true],
            ),
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            $dialog,
        );

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, 6);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, fn (ExportMessageToBitrix24OpenLinesJob $job): bool => $job->messageId === $autoReply->id);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, fn (ExportMessageToBitrix24OpenLinesJob $job): bool => $job->messageId === $phoneCapture->id);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, fn (ExportMessageToBitrix24OpenLinesJob $job): bool => $job->messageId === $collector->id);
    }

    public function test_send_manual_dialog_reply_queues_live_export_job(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9001,
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createLiveReadyDialog(channelAttributes: [
            'credentials' => ['token' => 'telegram-live-token'],
        ], contactAttributes: [
            'assigned_user_id' => $employee->id,
        ]);
        $contact = $dialog->contact()->firstOrFail();
        $channel = $dialog->channel()->firstOrFail();
        $identity = $dialog->currentContactIdentity()->firstOrFail();

        $this->makeMessage($dialog, [
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Нужно ответить',
        ]);

        $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
            $dialog,
            $employee,
            'Ручной live-ответ',
        );

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($outboundMessage): bool {
            return $job->messageId === $outboundMessage->id;
        });
    }

    public function test_manual_reply_live_export_uses_openlines_crm_message_add_path(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ из Abrikosoff',
            'text_format' => Message::TEXT_FORMAT_HTML,
            'source_text' => '<b>Ручной ответ из Abrikosoff</b>',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'current-max-chat-202',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'bitrix-chat-100',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-100',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame('abrikosoff-dialog:'.$dialog->id, $dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-chat-100',
            'bitrix_remote_message_id' => 'remote-message-100',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return false;
            }

            return $request['CRM_ENTITY_TYPE'] === 'CONTACT'
                && $request['CRM_ENTITY'] === 'B24-CONTACT-100'
                && $request['ACTIVE_ONLY'] === 'Y';
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json') {
                return false;
            }

            return $request['CRM_ENTITY_TYPE'] === 'CONTACT'
                && $request['CRM_ENTITY'] === 'B24-CONTACT-100'
                && $request['USER_ID'] === 321
                && $request['CHAT_ID'] === 'bitrix-chat-100'
                && $request['MESSAGE'] === 'Ручной ответ из Abrikosoff';
        });

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_live_export_uses_current_runtime_profile_connection_when_multiple_active_connections_exist(): void
    {
        $this->makeActiveConnection();
        $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: [
                'member_id' => 'member-2',
                'application_token' => 'app-token-2',
                'client_endpoint' => 'https://ignored-client.example/rest/',
                'server_endpoint' => 'https://ignored-server.example/rest/',
                'access_token_encrypted' => 'ignored-access-token',
                'refresh_token_encrypted' => 'ignored-refresh-token',
                'scope' => ['imconnector', 'imopenlines'],
            ],
            profileOverrides: [
                'profile_key' => 'dev-alex',
                'display_name' => 'Dev Alex',
                'application_code' => 'local.app.code.dev-alex',
                'callback_base_url' => 'https://other.example.com',
            ],
            useForCurrentRuntime: false,
        );

        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ через current runtime profile',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'bitrix-chat-200',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-200',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-chat-200',
            'bitrix_remote_message_id' => 'remote-message-200',
        ]);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://client-endpoint.example/rest/'));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://ignored-client.example/rest/'));
    }

    public function test_manual_reply_reuses_last_successful_remote_chat_before_active_lookup(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'bitrix-reuse-chat-210');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ через reuse',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'bitrix-reuse-chat-210',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-210',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-reuse-chat-210',
            'bitrix_remote_message_id' => 'remote-message-210',
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json') {
                return false;
            }

            return $request['CHAT_ID'] === 'bitrix-reuse-chat-210'
                && $request['USER_ID'] === 321
                && $request['MESSAGE'] === 'Ручной ответ через reuse';
        });

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
    }

    public function test_manual_reply_keeps_reusable_chat_available_for_lookup_when_route_proof_is_unavailable(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'bitrix-reuse-chat-211');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ через lookup после недоступного route proof',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'error' => 'TEMPORARY_ERROR',
                'error_description' => 'Dialog lookup temporarily unavailable.',
            ], 503),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'bitrix-reuse-chat-211',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-211',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-reuse-chat-211',
            'bitrix_remote_message_id' => 'remote-message-211',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'bitrix-reuse-chat-211');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
    }

    public function test_manual_reply_reusable_chat_access_denied_recovers_with_chat_user_add_and_single_retry(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'bitrix-reuse-chat-220');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ с recovery',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'bitrix-reuse-chat-220',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'bitrix-reuse-chat-220',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'ACCESS_DENIED',
                    'error_description' => 'User is not in chat.',
                ], 200)
                ->push([
                    'result' => [
                        'MESSAGE_ID' => 'remote-message-220',
                    ],
                ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.user.add.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-reuse-chat-220',
            'bitrix_remote_message_id' => 'remote-message-220',
        ]);

        $messageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');

        $chatUserAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.user.add.json');

        $this->assertCount(2, $messageAddRequests);
        $this->assertCount(1, $chatUserAddRequests);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
    }

    public function test_manual_reply_reusable_chat_deterministic_failure_continues_to_lookup_and_session_open(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'bitrix-reuse-chat-230');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ после invalid reusable chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'bitrix-reuse-chat-230',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'INVALID_CHAT',
                    'error_description' => 'Chat is no longer available.',
                ], 200)
                ->push([
                    'result' => [
                        'MESSAGE_ID' => 'remote-message-230',
                    ],
                ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'result' => [
                    'CHAT_ID' => 'bitrix-fallback-chat-230',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-fallback-chat-230',
            'bitrix_remote_message_id' => 'remote-message-230',
        ]);

        $messageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->values();

        $this->assertCount(2, $messageAddRequests);
        $this->assertSame('bitrix-reuse-chat-230', $messageAddRequests[0][0]['CHAT_ID']);
        $this->assertSame('bitrix-fallback-chat-230', $messageAddRequests[1][0]['CHAT_ID']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
    }

    public function test_manual_reply_reusable_chat_uncertain_failure_does_not_degrade_to_lookup_or_session_open(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'bitrix-reuse-chat-240');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ с uncertain reusable chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'bitrix-reuse-chat-240',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'error' => 'TEMPORARY_ERROR',
                'error_description' => 'Server temporarily unavailable.',
            ], 503),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN, $exception->failureCode);
            $this->assertTrue($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
    }

    public function test_manual_reply_reusable_chat_is_excluded_from_lookup_after_exhausted_recovery_cycle(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'bitrix-reuse-chat-250');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ после exhausted recovery',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'bitrix-reuse-chat-250',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'ACCESS_DENIED',
                    'error_description' => 'User is not in chat.',
                ], 200)
                ->push([
                    'error' => 'ACCESS_DENIED',
                    'error_description' => 'User is not in chat.',
                ], 200)
                ->push([
                    'result' => [
                        'MESSAGE_ID' => 'remote-message-250',
                    ],
                ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.user.add.json' => Http::response([
                'result' => true,
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'bitrix-reuse-chat-250',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'result' => [
                    'CHAT_ID' => 'bitrix-fallback-chat-250',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-fallback-chat-250',
            'bitrix_remote_message_id' => 'remote-message-250',
        ]);

        $messageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->values();

        $chatUserAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.user.add.json');

        $this->assertCount(3, $messageAddRequests);
        $this->assertCount(1, $chatUserAddRequests);
        $this->assertSame('bitrix-reuse-chat-250', $messageAddRequests[0][0]['CHAT_ID']);
        $this->assertSame('bitrix-reuse-chat-250', $messageAddRequests[1][0]['CHAT_ID']);
        $this->assertSame('bitrix-fallback-chat-250', $messageAddRequests[2][0]['CHAT_ID']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
    }

    public function test_manual_reply_live_export_uses_session_open_fallback_when_active_chat_lookup_is_empty(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ через fallback',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'result' => [
                    'CHAT_ID' => 'bitrix-fallback-chat-200',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-200',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-fallback-chat-200',
            'bitrix_remote_message_id' => 'remote-message-200',
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imopenlines.session.open.json') {
                return false;
            }

            return $request['USER_CODE'] === $this->expectedManualReplyUserCode($dialog, 'abrikosoff_max', 'line-max');
        });
    }

    public function test_manual_reply_uses_verified_legacy_open_line_binding_without_creating_new_session(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX, dialogAttributes: [
            'bitrix24_open_line_user_code_override' => 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5',
            'bitrix24_open_line_resolved_chat_id_override' => 'legacy-chat-7',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ в старую ОЛ',
        ]);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [
                        [
                            'CHAT_ID' => 'legacy-chat-7',
                            'CONNECTOR_ID' => 'abrikosoff_max',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5',
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                return Http::response([
                    'result' => [
                        'id' => 'legacy-chat-7',
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|B24-CONTACT-100|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json') {
                return Http::response([
                    'result' => [
                        'MESSAGE_ID' => 'remote-message-legacy-7',
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'legacy-chat-7',
            'resolved_crm_entity_type' => 'CONTACT',
            'resolved_crm_entity_id' => 'B24-CONTACT-100',
            'bitrix_remote_message_id' => 'remote-message-legacy-7',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json'
            && $request['USER_CODE'] === 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json'
            && $request['ACTIVE_ONLY'] === 'Y');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'legacy-chat-7'
            && $request['MESSAGE'] === 'Ручной ответ в старую ОЛ');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.start.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.operator.answer.json');
    }

    public function test_manual_reply_binding_failure_does_not_fallback_to_new_session(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX, dialogAttributes: [
            'bitrix24_open_line_user_code_override' => 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5',
            'bitrix24_open_line_resolved_chat_id_override' => 'legacy-chat-7',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ в старую ОЛ',
        ]);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [
                        [
                            'CHAT_ID' => 'legacy-chat-7',
                            'CONNECTOR_ID' => 'abrikosoff_max',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5',
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                return Http::response([
                    'result' => [
                        'id' => 'legacy-chat-7',
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|B24-CONTACT-100|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json') {
                return Http::response([
                    'error' => 'ACCESS_DENIED',
                    'error_description' => 'Not in chat.',
                ], 400);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.user.add.json') {
                return Http::response([
                    'error' => 'ACCESS_DENIED',
                    'error_description' => 'Cannot add user.',
                ], 400);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $this->expectException(Bitrix24OpenLinesManualReplyExportException::class);
        $this->expectExceptionMessage('verified dialog binding send failed');

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
        } finally {
            $this->assertDatabaseHas('bitrix24_message_exports', [
                'message_id' => $message->id,
                'export_mode' => Bitrix24MessageExport::MODE_LIVE,
                'export_status' => Bitrix24MessageExport::STATUS_FAILED,
                'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
                'failure_code' => Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            ]);

            Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json'
                && $request['USER_CODE'] === 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5');
            Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json'
                && $request['ACTIVE_ONLY'] === 'Y');
            Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.user.add.json');
            Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
            Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.start.json');
            Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.operator.answer.json');
            Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
        }
    }

    public function test_manual_reply_binding_chat_not_in_crm_does_not_use_mutating_legacy_transport(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX, dialogAttributes: [
            'bitrix24_live_chat_id' => 'legacy-dialog-23',
            'bitrix24_open_line_user_code_override' => 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5',
            'bitrix24_open_line_resolved_chat_id_override' => 'legacy-chat-7',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ через legacy binding fallback',
        ]);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [
                        [
                            'CHAT_ID' => 'legacy-chat-7',
                            'CONNECTOR_ID' => 'abrikosoff_max',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5',
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                return Http::response([
                    'result' => [
                        'id' => 'legacy-chat-7',
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|B24-CONTACT-100|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json') {
                return Http::response([
                    'error' => 'CHAT_NOT_IN_CRM',
                    'error_description' => 'Chat does not belong to the CRM entity being checked',
                ], 400);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(
                Bitrix24MessageExport::FAILURE_VERIFIED_BINDING_CRM_MESSAGE_ADD_UNAVAILABLE,
                $exception->failureCode,
            );
        }

        $dialog->refresh();

        $this->assertSame('legacy-dialog-23', $dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_FAILED, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'failure_code' => Bitrix24MessageExport::FAILURE_VERIFIED_BINDING_CRM_MESSAGE_ADD_UNAVAILABLE,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json'
            && $request['USER_CODE'] === 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'legacy-chat-7');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json'
            && $request['ACTIVE_ONLY'] === 'Y');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.start.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.operator.answer.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_resyncs_stale_verified_binding_before_crm_message_add(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_MAX,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $oldUserCode = sprintf('abrikosoff_max|line-max|abrikosoff-dialog:%d|14', $dialog->id);
        $newUserCode = sprintf('abrikosoff_max|line-max|abrikosoff-dialog:%d|23', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $oldUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '19',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Manual reply должен уйти в текущую ОЛ',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [
                        [
                            'CHAT_ID' => '19',
                            'CONNECTOR_ID' => 'abrikosoff_max',
                        ],
                        [
                            'CHAT_ID' => '30',
                            'CONNECTOR_ID' => 'abrikosoff_max',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '19',
                    $newUserCode => '30',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json') {
                return Http::response([
                    'result' => [
                        'MESSAGE_ID' => 'remote-message-current-30',
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('30', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => '30',
            'bitrix_remote_message_id' => 'remote-message-current-30',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === '30'
            && $request['USER_ID'] === 321
            && $request['MESSAGE'] === 'Manual reply должен уйти в текущую ОЛ');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === '19');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.start.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.operator.answer.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_live_export_ignores_single_active_chat_from_other_connector_and_uses_session_open_fallback(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ не должен уйти в telegram chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'current-max-chat-202',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'telegram-chat-999',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'result' => [
                    'CHAT_ID' => 'bitrix-fallback-chat-201',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-201',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-fallback-chat-201',
            'bitrix_remote_message_id' => 'remote-message-201',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json') {
                return false;
            }

            return $request['CHAT_ID'] === 'bitrix-fallback-chat-201';
        });
    }

    public function test_manual_reply_ignores_poisoned_reusable_chat_from_other_connector_and_uses_session_open_fallback(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'telegram-chat-998');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ не должен переиспользовать telegram history',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'current-max-chat-202',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'telegram-chat-998',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'result' => [
                    'CHAT_ID' => 'bitrix-fallback-chat-202',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-202',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-fallback-chat-202',
            'bitrix_remote_message_id' => 'remote-message-202',
        ]);

        $messageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->values();

        $this->assertCount(1, $messageAddRequests);
        $this->assertSame('bitrix-fallback-chat-202', $messageAddRequests[0][0]['CHAT_ID']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'telegram-chat-998');
    }

    public function test_manual_reply_live_export_falls_back_to_old_transport_with_operator_signature_when_service_user_id_is_missing(): void
    {
        $this->makeActiveConnection();
        config()->set('bitrix24.openlines.service_user_id', 0);

        $dialog = $this->createLiveReadyDialog();
        $operator = User::factory()->create([
            'name' => 'Василий',
            'is_active' => true,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_user_id' => $operator->id,
            'text' => 'Ручной ответ без service user',
            'text_format' => Message::TEXT_FORMAT_HTML,
            'source_text' => '<b>Ручной ответ без service user</b>',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => null,
            'bitrix_remote_message_id' => null,
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'ℹ️ [Оператор Василий] Ручной ответ без service user';
        });
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');
    }

    public function test_manual_reply_live_export_falls_back_to_old_transport_with_generic_operator_signature_when_sender_is_missing(): void
    {
        $this->makeActiveConnection();
        config()->set('bitrix24.openlines.service_user_id', 0);

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_user_id' => null,
            'text' => 'Ручной ответ без оператора',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'ℹ️ [Оператор] Ручной ответ без оператора';
        });
    }

    public function test_auto_reply_live_export_uses_legacy_transport_with_autoreply_signature(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE,
            'text' => 'Автоответ по сценарию',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'ℹ️ [Автоответ] Автоответ по сценарию';
        });
    }

    public function test_manual_reply_live_export_marks_ambiguous_chat_as_failed_without_sending_message(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ручной ответ в ambiguous chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    ['CHAT_ID' => 'bitrix-chat-1', 'CONNECTOR_ID' => 'abrikosoff_telegram'],
                    ['CHAT_ID' => 'bitrix-chat-2', 'CONNECTOR_ID' => 'abrikosoff_telegram'],
                ],
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_AMBIGUOUS_CHAT, $exception->failureCode);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'failure_code' => Bitrix24MessageExport::FAILURE_AMBIGUOUS_CHAT,
            'failure_uncertain' => false,
        ]);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');
    }

    public function test_manual_reply_live_export_does_not_retry_mutating_methods(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Mutating call without transport retry',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    ['CHAT_ID' => 'bitrix-chat-300', 'CONNECTOR_ID' => 'abrikosoff_telegram'],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'error' => 'TEMPORARY_ERROR',
                'error_description' => 'Server temporarily unavailable.',
            ], 503),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN, $exception->failureCode);
            $this->assertTrue($exception->failureUncertain);
        }

        $messageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');

        $this->assertCount(1, $messageAddRequests);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);
    }

    public function test_manual_reply_session_open_does_not_retry_and_marks_uncertain_failure(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Session open without transport retry',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'error' => 'TEMPORARY_ERROR',
                'error_description' => 'Server temporarily unavailable.',
            ], 503),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN, $exception->failureCode);
            $this->assertTrue($exception->failureUncertain);
        }

        $sessionOpenRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');

        $this->assertCount(1, $sessionOpenRequests);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');
    }

    public function test_manual_reply_blocks_telegram_session_open_when_open_line_history_requires_verified_binding(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'stale-telegram-chat-9');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Не открывать новую Telegram ОЛ',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'IM' => [
                        [
                            'VALUE' => sprintf('imol|abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|legacy-user-6', $dialog->id),
                            'VALUE_TYPE' => 'IMOL',
                        ],
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'result' => [
                    'CHAT_ID' => 'new-telegram-chat-18',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-18',
                ],
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'failure_code' => Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');
    }

    public function test_manual_reply_does_not_fall_back_to_legacy_transport_when_session_open_is_denied(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Не создавать дубль через legacy transport',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'error' => 'ACCESS_DENIED',
                'error_description' => 'Вы не можете открыть этот разговор, т.к. у вас недостаточно прав.',
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_SESSION_OPEN_FAILED, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => null,
            'bitrix_remote_message_id' => null,
            'failure_code' => Bitrix24MessageExport::FAILURE_SESSION_OPEN_FAILED,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');
    }

    public function test_manual_reply_does_not_reuse_legacy_session_chat_when_only_foreign_active_chat_exists(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'bitrix-max-chat-700');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'MAX должен остаться на legacy session chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'telegram-chat-998',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'result' => [
                    'CHAT_ID' => 'bitrix-fallback-chat-700',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-700',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-fallback-chat-700',
            'bitrix_remote_message_id' => 'remote-message-700',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'bitrix-fallback-chat-700');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'bitrix-max-chat-700');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'telegram-chat-998');
    }

    public function test_manual_reply_uses_current_same_connector_chat_instead_of_trusted_legacy_chat(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'old-max-chat-701');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'MAX должен перейти на current same-connector chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'current-max-chat-701',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-701',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'current-max-chat-701',
            'bitrix_remote_message_id' => 'remote-message-701',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'current-max-chat-701');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'old-max-chat-701');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
    }

    public function test_manual_reply_does_not_reuse_legacy_history_when_lookup_returns_empty(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'trusted-max-chat-702');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'MAX должен использовать trusted legacy chat при empty lookup',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.session.open.json' => Http::response([
                'result' => [
                    'CHAT_ID' => 'bitrix-fallback-chat-702',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => [
                    'MESSAGE_ID' => 'remote-message-702',
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'bitrix-fallback-chat-702',
            'bitrix_remote_message_id' => 'remote-message-702',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'bitrix-fallback-chat-702');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json'
            && $request['CHAT_ID'] === 'trusted-max-chat-702');
    }

    public function test_manual_reply_stops_uncertain_when_no_reusable_chat_exists_and_lookup_fails(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'trusted-max-chat-703');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'MAX должен использовать trusted legacy chat при падении lookup',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'error' => 'TEMPORARY_ERROR',
                'error_description' => 'Lookup temporarily unavailable.',
            ], 503),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN, $exception->failureCode);
            $this->assertTrue($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
    }

    public function test_manual_reply_retries_reusable_chat_with_dialog_binding_when_chat_is_not_in_crm(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'trusted-telegram-chat-704');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Телеграм должен повторить send через dialog binding',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'trusted-telegram-chat-704',
                    'entity_data_2' => 'LEAD|123|COMPANY|456|CONTACT|B24-CONTACT-200|DEAL|789',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'CHAT_NOT_IN_CRM',
                    'error_description' => 'Chat does not belong to the CRM entity being checked',
                ], 400)
                ->push([
                    'result' => 'remote-message-704',
                ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'trusted-telegram-chat-704',
            'resolved_crm_entity_type' => 'CONTACT',
            'resolved_crm_entity_id' => 'B24-CONTACT-200',
            'bitrix_remote_message_id' => 'remote-message-704',
        ]);

        $crmMessageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->map(fn (array $pair): Request => $pair[0])
            ->values();

        $this->assertCount(2, $crmMessageAddRequests);
        $this->assertSame('B24-CONTACT-100', $crmMessageAddRequests[0]['CRM_ENTITY']);
        $this->assertSame('B24-CONTACT-200', $crmMessageAddRequests[1]['CRM_ENTITY']);
        $this->assertSame('trusted-telegram-chat-704', $crmMessageAddRequests[0]['CHAT_ID']);
        $this->assertSame('trusted-telegram-chat-704', $crmMessageAddRequests[1]['CHAT_ID']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json'
            && $request['USER_CODE'] === $this->expectedManualReplyUserCode($dialog, 'abrikosoff_telegram', 'line-telegram'));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_reuses_rebound_chat_on_next_message_after_dialog_binding_recovery(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'trusted-telegram-chat-705');

        $firstMessage = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Первое сообщение должно rebinding-нуться',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'trusted-telegram-chat-705',
                    'entity_data_2' => 'LEAD|0|CONTACT|B24-CONTACT-200|DEAL|0',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'CHAT_NOT_IN_CRM',
                    'error_description' => 'Chat does not belong to the CRM entity being checked',
                ], 400)
                ->push([
                    'result' => 'remote-message-705a',
                ], 200)
                ->push([
                    'result' => 'remote-message-705b',
                ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($firstMessage);

        $secondMessage = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Второе сообщение должно reuse-ить rebound chat',
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($secondMessage);

        $crmMessageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->map(fn (array $pair): Request => $pair[0])
            ->values();

        $this->assertCount(3, $crmMessageAddRequests);
        $this->assertSame('B24-CONTACT-100', $crmMessageAddRequests[0]['CRM_ENTITY']);
        $this->assertSame('B24-CONTACT-200', $crmMessageAddRequests[1]['CRM_ENTITY']);
        $this->assertSame('B24-CONTACT-200', $crmMessageAddRequests[2]['CRM_ENTITY']);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $secondMessage->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'trusted-telegram-chat-705',
            'resolved_crm_entity_type' => 'CONTACT',
            'resolved_crm_entity_id' => 'B24-CONTACT-200',
            'bitrix_remote_message_id' => 'remote-message-705b',
        ]);

        Http::assertSentCount(3 + 3); // three dialog.get + three crm.message.add
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_uses_latest_persisted_binding_for_same_reusable_chat(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'trusted-telegram-chat-705c');
        $this->seedSuccessfulManualReplyTransportExport(
            $dialog,
            'trusted-telegram-chat-705c',
            'CONTACT',
            'B24-CONTACT-200',
        );
        $this->seedSuccessfulManualReplyTransportExport(
            $dialog,
            'trusted-telegram-chat-705c',
            'CONTACT',
            'B24-CONTACT-201',
        );

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Нужно взять самый новый persisted binding для того же chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'trusted-telegram-chat-705c',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => 'remote-message-705c',
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $crmMessageAddRequest = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');

        $this->assertNotNull($crmMessageAddRequest);
        $this->assertSame('trusted-telegram-chat-705c', $crmMessageAddRequest['CHAT_ID']);
        $this->assertSame('B24-CONTACT-201', $crmMessageAddRequest['CRM_ENTITY']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
    }

    public function test_manual_reply_ignores_failed_rows_when_loading_persisted_binding(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'trusted-telegram-chat-705d');
        $this->seedSuccessfulManualReplyTransportExport(
            $dialog,
            'trusted-telegram-chat-705d',
            'CONTACT',
            'B24-CONTACT-200',
        );
        $failedMessage = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Synthetic failed row for read-path guard',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $failedMessage->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'trusted-telegram-chat-705d',
            'resolved_crm_entity_type' => 'CONTACT',
            'resolved_crm_entity_id' => 'B24-CONTACT-999',
            'failed_at' => now(),
            'failure_code' => Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            'failure_uncertain' => false,
            'failure_reason' => 'Synthetic failed row should be ignored on read path.',
        ]);

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Failed rows не должны seed-ить persisted binding',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'trusted-telegram-chat-705d',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => 'remote-message-705d',
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $crmMessageAddRequest = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');

        $this->assertNotNull($crmMessageAddRequest);
        $this->assertSame('B24-CONTACT-200', $crmMessageAddRequest['CRM_ENTITY']);
        $this->assertSame('trusted-telegram-chat-705d', $crmMessageAddRequest['CHAT_ID']);
    }

    public function test_manual_reply_uses_rebound_crm_binding_for_chat_user_add_recovery(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'trusted-telegram-chat-706');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Телеграм должен чинить доступ уже на rebound CRM binding',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'trusted-telegram-chat-706',
                    'entity_data_2' => 'LEAD|0|CONTACT|B24-CONTACT-200|DEAL|0',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'CHAT_NOT_IN_CRM',
                    'error_description' => 'Chat does not belong to the CRM entity being checked',
                ], 400)
                ->push([
                    'error' => 'ACCESS_DENIED',
                    'error_description' => 'Access denied.',
                ], 400)
                ->push([
                    'result' => 'remote-message-706',
                ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.user.add.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $chatUserAddRequest = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.user.add.json');

        $this->assertNotNull($chatUserAddRequest);
        $this->assertSame('B24-CONTACT-200', $chatUserAddRequest['CRM_ENTITY']);
        $this->assertSame('CONTACT', $chatUserAddRequest['CRM_ENTITY_TYPE']);
        $this->assertSame('trusted-telegram-chat-706', $chatUserAddRequest['CHAT_ID']);

        $crmMessageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->map(fn (array $pair): Request => $pair[0])
            ->values();

        $this->assertCount(3, $crmMessageAddRequests);
        $this->assertSame('B24-CONTACT-100', $crmMessageAddRequests[0]['CRM_ENTITY']);
        $this->assertSame('B24-CONTACT-200', $crmMessageAddRequests[1]['CRM_ENTITY']);
        $this->assertSame('B24-CONTACT-200', $crmMessageAddRequests[2]['CRM_ENTITY']);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_allows_duplicate_same_contact_in_dialog_binding_recovery(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'trusted-telegram-chat-707');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Дубликаты одного CONTACT не должны считаться ambiguity',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'trusted-telegram-chat-707',
                    'entity_data_2' => 'CONTACT|B24-CONTACT-200|CONTACT|B24-CONTACT-200|LEAD|123',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'CHAT_NOT_IN_CRM',
                    'error_description' => 'Chat does not belong to the CRM entity being checked',
                ], 400)
                ->push([
                    'result' => 'remote-message-707',
                ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $crmMessageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->map(fn (array $pair): Request => $pair[0])
            ->values();

        $this->assertCount(2, $crmMessageAddRequests);
        $this->assertSame('B24-CONTACT-200', $crmMessageAddRequests[1]['CRM_ENTITY']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
    }

    public function test_manual_reply_does_not_recover_when_dialog_binding_has_multiple_different_contacts(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'trusted-telegram-chat-708');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Разные CONTACT должны останавливать rebinding',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'trusted-telegram-chat-708',
                    'entity_data_2' => 'CONTACT|B24-CONTACT-200|CONTACT|B24-CONTACT-201|DEAL|777',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'error' => 'CHAT_NOT_IN_CRM',
                'error_description' => 'Chat does not belong to the CRM entity being checked',
            ], 400),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED, $exception->failureCode);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_crm_entity_type' => null,
            'resolved_crm_entity_id' => null,
            'failure_code' => Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_ignores_persisted_binding_from_old_trusted_chat_when_current_winner_chat_changes(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulLegacyManualReplyTransportExport(
            $dialog,
            'old-max-chat-709',
            'CONTACT',
            'B24-CONTACT-200',
        );

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Новый winner chat не должен получать binding старого trusted chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'current-max-chat-709b',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'current-max-chat-709',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => 'remote-message-709',
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $crmMessageAddRequest = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');

        $this->assertNotNull($crmMessageAddRequest);
        $this->assertSame('current-max-chat-709', $crmMessageAddRequest['CHAT_ID']);
        $this->assertSame('B24-CONTACT-100', $crmMessageAddRequest['CRM_ENTITY']);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'resolved_bitrix_chat_id' => 'current-max-chat-709',
            'resolved_crm_entity_type' => null,
            'resolved_crm_entity_id' => null,
        ]);
    }

    public function test_manual_reply_loads_persisted_binding_for_current_winner_chat_selected_from_active_lookup(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulLegacyManualReplyTransportExport(
            $dialog,
            'old-max-chat-709b',
            'CONTACT',
            'B24-CONTACT-200',
        );
        $this->seedSuccessfulManualReplyTransportExport(
            $dialog,
            'current-max-chat-709b',
            'CONTACT',
            'B24-CONTACT-201',
        );

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Winner chat должен поднять свой persisted binding после lookup',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'current-max-chat-709b',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'current-max-chat-709b',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::response([
                'result' => 'remote-message-709b',
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $crmMessageAddRequest = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');

        $this->assertNotNull($crmMessageAddRequest);
        $this->assertSame('current-max-chat-709b', $crmMessageAddRequest['CHAT_ID']);
        $this->assertSame('B24-CONTACT-201', $crmMessageAddRequest['CRM_ENTITY']);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'resolved_bitrix_chat_id' => 'current-max-chat-709b',
            'resolved_crm_entity_type' => 'CONTACT',
            'resolved_crm_entity_id' => 'B24-CONTACT-201',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_retries_chat_not_in_crm_for_service_actor_reusable_chat_without_legacy_ancestry(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulManualReplyTransportExport($dialog, 'service-telegram-chat-710');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Service-actor reusable chat должен пройти dialog rebinding без legacy ancestry',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'service-telegram-chat-710',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'service-telegram-chat-710',
                    'entity_data_2' => 'LEAD|0|CONTACT|B24-CONTACT-210|DEAL|0',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'CHAT_NOT_IN_CRM',
                    'error_description' => 'Chat does not belong to the CRM entity being checked',
                ], 400)
                ->push([
                    'result' => 'remote-message-710',
                ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $crmMessageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->map(fn (array $pair): Request => $pair[0])
            ->values();

        $this->assertCount(2, $crmMessageAddRequests);
        $this->assertSame('service-telegram-chat-710', $crmMessageAddRequests[0]['CHAT_ID']);
        $this->assertSame('service-telegram-chat-710', $crmMessageAddRequests[1]['CHAT_ID']);
        $this->assertSame('B24-CONTACT-100', $crmMessageAddRequests[0]['CRM_ENTITY']);
        $this->assertSame('B24-CONTACT-210', $crmMessageAddRequests[1]['CRM_ENTITY']);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'service-telegram-chat-710',
            'resolved_crm_entity_type' => 'CONTACT',
            'resolved_crm_entity_id' => 'B24-CONTACT-210',
            'bitrix_remote_message_id' => 'remote-message-710',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json'
            && $request['USER_CODE'] === $this->expectedManualReplyUserCode($dialog, 'abrikosoff_telegram', 'line-telegram'));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/im.dialog.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_uses_im_dialog_get_recovery_when_openlines_dialog_get_is_denied(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'MAX должен восстановить binding через im.dialog.get',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'current-max-chat-710x',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'error' => 'ACCESS_ERROR',
                'error_description' => 'You do not have access to the specified dialog',
            ], 400),
            'https://client-endpoint.example/rest/im.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'current-max-chat-710x',
                    'entity_data_2' => 'LEAD|0|CONTACT|B24-CONTACT-210|DEAL|0',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.crm.message.add.json' => Http::sequence()
                ->push([
                    'error' => 'CHAT_NOT_IN_CRM',
                    'error_description' => 'Chat does not belong to the CRM entity being checked',
                ], 400)
                ->push([
                    'result' => 'remote-message-710x',
                ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $crmMessageAddRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json')
            ->map(fn (array $pair): Request => $pair[0])
            ->values();

        $this->assertCount(2, $crmMessageAddRequests);
        $this->assertSame('current-max-chat-710x', $crmMessageAddRequests[0]['CHAT_ID']);
        $this->assertSame('current-max-chat-710x', $crmMessageAddRequests[1]['CHAT_ID']);
        $this->assertSame('B24-CONTACT-100', $crmMessageAddRequests[0]['CRM_ENTITY']);
        $this->assertSame('B24-CONTACT-210', $crmMessageAddRequests[1]['CRM_ENTITY']);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => 'current-max-chat-710x',
            'resolved_crm_entity_type' => 'CONTACT',
            'resolved_crm_entity_id' => 'B24-CONTACT-210',
            'bitrix_remote_message_id' => 'remote-message-710x',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json'
            && $request['USER_CODE'] === $this->expectedManualReplyUserCode($dialog, 'abrikosoff_max', 'line-max'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/im.dialog.get.json'
            && $request['DIALOG_ID'] === 'chatcurrent-max-chat-710x');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_manual_reply_keeps_ambiguity_guardrail_when_lookup_returns_multiple_same_connector_chats_for_trusted_legacy_chat(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'old-max-chat-704');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'MAX не должен разруливать ambiguity trusted history',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'old-max-chat-704',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                    [
                        'CHAT_ID' => 'current-max-chat-704',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24OpenLinesManualReplyExportException was not thrown.');
        } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_AMBIGUOUS_CHAT, $exception->failureCode);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'failure_code' => Bitrix24MessageExport::FAILURE_AMBIGUOUS_CHAT,
            'failure_uncertain' => false,
        ]);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.message.add.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
    }

    public function test_feature_flag_off_disables_live_export_queueing(): void
    {
        Queue::fake();

        config()->set('bitrix24.features.openlines_enabled', false);
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Сообщение без bridge',
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->ready);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
    }

    public function test_store_inbound_system_event_queues_live_export_job_for_ready_bitrix_contact(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $channel = $dialog->channel()->firstOrFail();

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: (string) $dialog->external_chat_id,
                externalUserId: (string) $dialog->currentContactIdentity()->firstOrFail()->external_user_id,
                providerEventKey: 'tg-system-live-101',
                externalMessageId: null,
                externalUsername: 'live_user',
                contactName: 'Live Contact',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['my_chat_member' => ['status' => 'kicked']],
                receivedAt: Carbon::parse('2026-04-01 12:01:00', 'Europe/Moscow'),
                systemEventCode: IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER,
            ),
        );

        $this->assertNotNull($storedResult);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($storedResult): bool {
            return $job->messageId === $storedResult->message->id
                && $job->retryAfterSync === false;
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $storedResult->message->id,
            'contact_id' => $dialog->contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_live_export_action_marks_message_as_exported_and_updates_dialog_live_state(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'first_name' => 'Герман',
            'last_name' => 'Германов',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $dialog->contact_id,
            'phone_raw' => '+7 926 352-71-11',
            'phone_normalized' => '+79263527111',
            'is_primary' => true,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Привет в Open Lines',
            'received_at' => Carbon::parse('2026-04-01 13:30:00', 'Europe/Moscow'),
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame('abrikosoff-dialog:'.$dialog->id, $dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertNotNull($dialog->bitrix24_live_last_exported_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);

        Http::assertSent(function (Request $request) use ($dialog, $message): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_telegram'
                && ($payload['LINE'] ?? null) === 'line-telegram'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['user']['name'] ?? null) === 'Герман Германов'
                && ($payload['MESSAGES'][0]['user']['last_name'] ?? null) === 'Германов'
                && ($payload['MESSAGES'][0]['user']['phone'] ?? null) === '+79263527111'
                && ($payload['MESSAGES'][0]['message']['id'] ?? null) === 'abrikosoff-message:'.$message->id
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Привет в Open Lines';
        });
    }

    public function test_live_export_uses_verified_legacy_open_line_binding_for_connector_chat_and_user(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_MAX, dialogAttributes: [
            'bitrix24_open_line_user_code_override' => 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5',
            'bitrix24_open_line_resolved_chat_id_override' => 'legacy-chat-7',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Сообщение в старую ОЛ',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'legacy-chat-7',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 'legacy-chat-7',
                    'entity_id' => 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5',
                    'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|B24-CONTACT-100|DEAL|12',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => [
                    'DATA' => [
                        'RESULT' => [
                            [
                                'session' => [
                                    'CHAT_ID' => 'legacy-chat-7',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame('legacy-dialog-23', $dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => 'legacy-chat-7',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_max'
                && ($payload['LINE'] ?? null) === 'line-max'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'legacy-dialog-23'
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Сообщение в старую ОЛ';
        });
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json'
            && $request['USER_CODE'] === 'abrikosoff_max|line-max|legacy-dialog-23|legacy-user-5');
    }

    public function test_live_export_uses_verified_binding_when_active_lookup_is_empty_but_dialog_lookup_matches_contact(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $userCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|19', $dialog->id);
        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $userCode,
            'bitrix24_open_line_resolved_chat_id_override' => '26',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Клиент пишет в актуальную ОЛ',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'IM' => [
                        [
                            'VALUE' => 'imol|'.$userCode,
                            'VALUE_TYPE' => 'IMOL',
                        ],
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 26,
                    'entity_id' => $userCode,
                    'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                ],
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => [
                    'DATA' => [
                        'RESULT' => [
                            [
                                'session' => [
                                    'CHAT_ID' => '26',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '26',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json'
            && $request['CRM_ENTITY_TYPE'] === 'CONTACT'
            && $request['CRM_ENTITY'] === '9'
            && $request['ACTIVE_ONLY'] === 'Y');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json'
            && $request['USER_CODE'] === $userCode);
        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_telegram'
                && ($payload['LINE'] ?? null) === 'line-telegram'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
            && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Клиент пишет в актуальную ОЛ';
        });
    }

    public function test_live_export_syncs_missing_binding_to_current_open_line_before_mutating_send(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, '23');

        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|15', $dialog->id);
        $newUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|19', $dialog->id);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Клиент должен попасть в актуальную ОЛ',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '23',
                    $newUserCode => '26',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '26',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('26', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '26',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Клиент должен попасть в актуальную ОЛ';
        });
    }

    public function test_live_export_marks_last_successful_stale_chat_returned_after_newer_history_uncertain(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, '23');

        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|6', $dialog->id);
        $currentUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|15', $dialog->id);
        $newerUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|19', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $newerUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '26',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Bitrix должен остаться на уже выбранной ОЛ',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $currentUserCode, $newerUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response(['result' => []], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$currentUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newerUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '8',
                    $currentUserCode => '23',
                    $newerUserCode => '26',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'user' => '15',
                                    'session' => [
                                        'CHAT_ID' => '23',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN, $exception->failureCode);
            $this->assertTrue($exception->failureUncertain);
            $this->assertStringContainsString('unexpected chat id [23], expected [26]', $exception->getMessage());
        }

        $dialog->refresh();

        $this->assertSame($newerUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('26', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => null,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Bitrix должен остаться на уже выбранной ОЛ';
        });
    }

    public function test_live_export_accepts_existing_history_chat_returned_by_bitrix_after_verified_binding_mismatch(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );

        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|6', $dialog->id);
        $currentUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|15', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $oldUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '8',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Bitrix вернул существующую ОЛ после mismatch',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $currentUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response(['result' => []], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$currentUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '8',
                    $currentUserCode => '23',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'user' => '15',
                                    'session' => [
                                        'CHAT_ID' => '23',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($currentUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('23', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '23',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);
    }

    public function test_live_export_missing_binding_current_lookup_failure_remains_retryable_without_mutating_send(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, '23');

        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|15', $dialog->id);
        $newUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|19', $dialog->id);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Current lookup временно недоступен',
        ]);

        $contactLookupCount = 0;

        Http::fake(function (Request $request) use (&$contactLookupCount, $oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                $contactLookupCount++;

                if ($contactLookupCount === 1) {
                    return Http::response([
                        'error' => 'TEMPORARY_ERROR',
                        'error_description' => 'Contact lookup temporarily unavailable.',
                    ], 503);
                }

                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '23',
                    $newUserCode => '26',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '26',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failure_code' => Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
            'failure_uncertain' => false,
        ]);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('26', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '26',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);
    }

    public function test_live_export_resyncs_stale_verified_binding_when_active_lookup_is_empty_but_current_history_has_newer_chat(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|14', $dialog->id);
        $newUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|23', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $oldUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '19',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Bitrix active lookup пустой, но история знает новую ОЛ',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '19',
                    $newUserCode => '30',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '30',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('30', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '30',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Bitrix active lookup пустой, но история знает новую ОЛ';
        });
    }

    public function test_live_export_resyncs_stale_max_verified_binding_before_mutating_send(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_MAX,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $oldUserCode = sprintf('abrikosoff_max|line-max|abrikosoff-dialog:%d|14', $dialog->id);
        $newUserCode = sprintf('abrikosoff_max|line-max|abrikosoff-dialog:%d|23', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $oldUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '19',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'MAX binding должен обновиться до отправки',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '19',
                    $newUserCode => '30',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '30',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('30', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '30',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['CONNECTOR'] ?? null) === 'abrikosoff_max'
                && ($payload['LINE'] ?? null) === 'line-max'
                && ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'MAX binding должен обновиться до отправки';
        });
    }

    public function test_live_export_blocks_stale_verified_legacy_binding_when_current_chat_cannot_be_resolved(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
            dialogAttributes: [
                'bitrix24_open_line_user_code_override' => 'abrikosoff_telegram|line-telegram|legacy-dialog-24|legacy-user-15',
                'bitrix24_open_line_resolved_chat_id_override' => '23',
                'bitrix24_open_line_binding_verified_at' => now(),
            ],
        );
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Bitrix не должен получить mutating send',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => '26',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'IM' => [],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
            $this->assertStringContainsString('expected chat id [23] is not current', $exception->getMessage());
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => null,
            'failure_code' => Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_live_export_resyncs_stale_verified_binding_when_old_and_new_active_chats_exist(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|14', $dialog->id);
        $newUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|23', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $oldUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '19',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Bitrix уже показывает новую активную ОЛ',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [
                        [
                            'CHAT_ID' => '19',
                            'CONNECTOR_ID' => 'abrikosoff_telegram',
                        ],
                        [
                            'CHAT_ID' => '30',
                            'CONNECTOR_ID' => 'abrikosoff_telegram',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '19',
                    $newUserCode => '30',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '30',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('30', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '30',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
            && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Bitrix уже показывает новую активную ОЛ';
        });
    }

    public function test_live_export_uses_current_verified_binding_when_active_lookup_returns_only_older_chat(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|14', $dialog->id);
        $newUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|23', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $newUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '30',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Bitrix active lookup вернул только старую ОЛ',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [
                        [
                            'CHAT_ID' => '19',
                            'CONNECTOR_ID' => 'abrikosoff_telegram',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '19',
                    $newUserCode => '30',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '30',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('30', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '30',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Bitrix active lookup вернул только старую ОЛ';
        });
    }

    public function test_live_export_does_not_block_current_binding_when_newer_active_chat_belongs_to_other_dialog(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $currentUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|23', $dialog->id);
        $otherDialogUserCode = 'abrikosoff_telegram|line-telegram|abrikosoff-dialog:999|31';

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $currentUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '30',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'У контакта есть более новая ОЛ другого диалога',
        ]);

        Http::fake(function (Request $request) use ($currentUserCode, $otherDialogUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [
                        [
                            'CHAT_ID' => '30',
                            'CONNECTOR_ID' => 'abrikosoff_telegram',
                        ],
                        [
                            'CHAT_ID' => '31',
                            'CONNECTOR_ID' => 'abrikosoff_telegram',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$currentUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$otherDialogUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];

                if ($userCode !== $currentUserCode) {
                    return Http::response(['error' => 'Unexpected user code'], 500);
                }

                return Http::response([
                    'result' => [
                        'id' => 30,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '30',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($currentUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('30', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '30',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'У контакта есть более новая ОЛ другого диалога';
        });
    }

    public function test_live_export_resolves_current_open_line_by_root_contact_for_merged_dialog(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => null,
                'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            ],
        );
        $rootContact = Contact::factory()->create([
            'name' => 'Root Live Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => '9',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $dialog->contact()->update([
            'merged_into_contact_id' => $rootContact->id,
        ]);

        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|14', $dialog->id);
        $newUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|23', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $oldUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '19',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Merged child должен использовать root CONTACT',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                if ($request['ID'] !== '9') {
                    return Http::response(['error' => 'Unexpected contact id'], 500);
                }

                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '19',
                    $newUserCode => '30',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '30',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('30', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'contact_id' => $rootContact->id,
            'bitrix24_contact_id' => '9',
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '30',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(function (Request $request) use ($dialog): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['chat']['id'] ?? null) === 'abrikosoff-dialog:'.$dialog->id
                && ($payload['MESSAGES'][0]['user']['id'] ?? null) === $this->expectedOpenLinesExternalUserId($dialog)
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Merged child должен использовать root CONTACT';
        });
    }

    public function test_live_export_rejects_verified_legacy_binding_when_bitrix_returns_different_chat(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
            dialogAttributes: [
                'bitrix24_open_line_user_code_override' => 'abrikosoff_telegram|line-telegram|legacy-dialog-24|legacy-user-15',
                'bitrix24_open_line_resolved_chat_id_override' => '23',
                'bitrix24_open_line_binding_verified_at' => now(),
            ],
        );
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Bitrix не должен подменить verified binding chat',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => '23',
                        'CONNECTOR_ID' => 'abrikosoff_telegram',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => [
                    'DATA' => [
                        'RESULT' => [
                            [
                                'session' => [
                                    'CHAT_ID' => '26',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'IM' => [],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'result' => [
                    'id' => 23,
                    'entity_id' => 'abrikosoff_telegram|line-telegram|legacy-dialog-24|legacy-user-15',
                    'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                ],
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN, $exception->failureCode);
            $this->assertTrue($exception->failureUncertain);
            $this->assertStringContainsString('unexpected chat id [26], expected [23]', $exception->getMessage());
        }

        $dialog->refresh();

        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_FAILED, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => null,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_live_export_marks_post_send_current_chat_lookup_failure_uncertain(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
            dialogAttributes: [
                'bitrix24_open_line_user_code_override' => 'abrikosoff_telegram|line-telegram|legacy-dialog-24|legacy-user-15',
                'bitrix24_open_line_resolved_chat_id_override' => '23',
                'bitrix24_open_line_binding_verified_at' => now(),
            ],
        );
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Post-send lookup failed after mutating request',
        ]);

        $contactLookupCount = 0;

        Http::fake(function (Request $request) use (&$contactLookupCount) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response([
                    'result' => [
                        [
                            'CHAT_ID' => '23',
                            'CONNECTOR_ID' => 'abrikosoff_telegram',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                $contactLookupCount++;

                if ($contactLookupCount === 1) {
                    return Http::response([
                        'result' => [
                            'IM' => [],
                        ],
                    ], 200);
                }

                return Http::response([
                    'error' => 'TEMPORARY_ERROR',
                    'error_description' => 'Contact lookup temporarily unavailable.',
                ], 503);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                return Http::response([
                    'result' => [
                        'id' => 23,
                        'entity_id' => 'abrikosoff_telegram|line-telegram|legacy-dialog-24|legacy-user-15',
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '26',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN, $exception->failureCode);
            $this->assertTrue($exception->failureUncertain);
            $this->assertStringContainsString('post-send lookup outcome is uncertain', $exception->getMessage());
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => null,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json');
    }

    public function test_live_export_marks_old_chat_returned_after_current_history_mismatch_uncertain(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $oldUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|14', $dialog->id);
        $newUserCode = sprintf('abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|23', $dialog->id);

        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $oldUserCode,
            'bitrix24_open_line_resolved_chat_id_override' => '19',
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Bitrix создал новую актуальную ОЛ',
        ]);

        Http::fake(function (Request $request) use ($oldUserCode, $newUserCode) {
            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json') {
                return Http::response(['result' => []], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json') {
                return Http::response([
                    'result' => [
                        'IM' => [
                            [
                                'VALUE' => 'imol|'.$oldUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                            [
                                'VALUE' => 'imol|'.$newUserCode,
                                'VALUE_TYPE' => 'IMOL',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imopenlines.dialog.get.json') {
                $userCode = (string) $request['USER_CODE'];
                $chatId = match ($userCode) {
                    $oldUserCode => '19',
                    $newUserCode => '30',
                    default => null,
                };

                if ($chatId === null) {
                    return Http::response(['error' => 'NOT_FOUND'], 404);
                }

                return Http::response([
                    'result' => [
                        'id' => $chatId,
                        'entity_id' => $userCode,
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return Http::response([
                    'result' => [
                        'DATA' => [
                            'RESULT' => [
                                [
                                    'session' => [
                                        'CHAT_ID' => '19',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN, $exception->failureCode);
            $this->assertTrue($exception->failureUncertain);
            $this->assertStringContainsString('unexpected chat id [19], expected [30]', $exception->getMessage());
        }

        $dialog->refresh();

        $this->assertSame($newUserCode, $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('30', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => null,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);
    }

    public function test_live_export_creates_controlled_telegram_open_line_when_stale_history_cannot_be_reused(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(
            platform: Channel::PLATFORM_TELEGRAM,
            contactAttributes: [
                'bitrix24_contact_id' => '9',
            ],
        );
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'stale-telegram-chat-8');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Можно создать новую Telegram ОЛ на существующем CONTACT',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'active-max-chat-7',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'IM' => [
                        [
                            'VALUE' => sprintf('imol|abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|legacy-user-6', $dialog->id),
                            'VALUE_TYPE' => 'IMOL',
                        ],
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imopenlines.dialog.get.json' => Http::response([
                'error' => 'NOT_FOUND',
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => [
                    'DATA' => [
                        'RESULT' => [
                            [
                                'session' => [
                                    'CHAT_ID' => 'new-telegram-chat-29',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame('abrikosoff-dialog:'.$dialog->id, $dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => 'new-telegram-chat-29',
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json');
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/imconnector.send.messages.json') {
                return false;
            }

            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['user']['crm_contact_id'] ?? null) === '9'
                && ($payload['MESSAGES'][0]['message']['params']['crm_contact_id_probe'] ?? null) === '9'
                && ! isset($payload['MESSAGES'][0]['message']['params']['retry_after_sync_probe']);
        });
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
    }

    public function test_live_export_blocks_telegram_legacy_send_when_open_line_history_requires_verified_binding(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'stale-telegram-chat-8');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Нельзя создавать новую Telegram ОЛ',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'active-max-chat-7',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'IM' => [
                        [
                            'VALUE' => sprintf('imol|abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|legacy-user-6', $dialog->id),
                            'VALUE_TYPE' => 'IMOL',
                        ],
                        [
                            'VALUE' => sprintf('imol|abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|legacy-user-15', $dialog->id),
                            'VALUE_TYPE' => 'IMOL',
                        ],
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failure_code' => Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imopenlines.session.open.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_live_export_treats_null_transport_success_as_legacy_history_for_guard(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $legacyMessage = $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'old-null-transport-chat-8');

        Bitrix24MessageExport::query()
            ->where('message_id', $legacyMessage->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->update(['transport_method' => null]);

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Старая строка без transport_method тоже должна блокировать новую ОЛ',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'active-max-chat-8',
                        'CONNECTOR_ID' => 'abrikosoff_max',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'IM' => [
                        [
                            'VALUE' => sprintf('imol|abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|legacy-user-6', $dialog->id),
                            'VALUE_TYPE' => 'IMOL',
                        ],
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failure_code' => Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_live_export_does_not_treat_active_chat_without_connector_as_safe_match(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'stale-telegram-chat-no-connector');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Active chat без connector не должен обходить guard',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [
                    [
                        'CHAT_ID' => 'active-chat-without-connector',
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'IM' => [
                        [
                            'VALUE' => sprintf('imol|abrikosoff_telegram|line-telegram|abrikosoff-dialog:%d|legacy-user-6', $dialog->id),
                            'VALUE_TYPE' => 'IMOL',
                        ],
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failure_code' => Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING,
            'failure_uncertain' => false,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');
    }

    public function test_live_export_guard_lookup_failure_remains_retryable_without_mutating_request(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'stale-telegram-chat-8');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Retryable guard lookup failure',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::sequence()
                ->push([
                    'error' => 'TEMPORARY_ERROR',
                    'error_description' => 'Lookup temporarily unavailable.',
                ], 503)
                ->push([
                    'result' => [
                        [
                            'CHAT_ID' => 'active-telegram-chat-guard',
                            'CONNECTOR_ID' => 'abrikosoff_telegram',
                        ],
                    ],
                ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failure_code' => Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
            'failure_uncertain' => false,
        ]);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        $chatLookupRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json');
        $legacySendRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');

        $this->assertCount(2, $chatLookupRequests);
        $this->assertCount(1, $legacySendRequests);
    }

    public function test_live_export_guard_contact_lookup_failure_remains_retryable_without_mutating_request(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(platform: Channel::PLATFORM_TELEGRAM);
        $this->seedSuccessfulLegacyManualReplyTransportExport($dialog, 'stale-telegram-chat-contact-lookup');

        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Retryable contact lookup failure',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imopenlines.crm.chat.get.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::sequence()
                ->push([
                    'error' => 'TEMPORARY_ERROR',
                    'error_description' => 'Contact lookup temporarily unavailable.',
                ], 503)
                ->push([
                    'result' => [
                        'IM' => [],
                    ],
                ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24LiveExportTransportException was not thrown.');
        } catch (Bitrix24LiveExportTransportException $exception) {
            $this->assertSame(Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED, $exception->failureCode);
            $this->assertFalse($exception->failureUncertain);
        }

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failure_code' => Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
            'failure_uncertain' => false,
        ]);

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failure_code' => null,
            'failure_uncertain' => false,
        ]);

        $contactLookupRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/crm.contact.get.json');
        $legacySendRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://client-endpoint.example/rest/imconnector.send.messages.json');

        $this->assertCount(2, $contactLookupRequests);
        $this->assertCount(1, $legacySendRequests);
    }

    public function test_fake_happy_path_live_export_marks_message_as_exported_without_bitrix_request(): void
    {
        Queue::fake();
        Http::fake();

        config()->set('bitrix24.features.fake_happy_path_enabled', true);
        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', true);

        $dialog = $this->createLiveReadyDialog(
            contactAttributes: [
                'first_name' => 'Макс',
                'last_name' => 'Тестов',
            ],
            dialogAttributes: [
                'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
            ],
        );
        ContactPhoneNumber::factory()->create([
            'contact_id' => $dialog->contact_id,
            'phone_raw' => '+7 926 352-71-11',
            'phone_normalized' => '+79263527111',
            'is_primary' => true,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Fake Open Lines export',
            'received_at' => Carbon::parse('2026-04-01 13:31:00', 'Europe/Moscow'),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame('fake-live-dialog-'.$dialog->id, $dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertNotNull($dialog->bitrix24_live_last_exported_at);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_live_exported_fake',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'entity_type' => 'message',
            'entity_id' => (string) $message->id,
        ]);

        Http::assertNothingSent();
        Queue::assertNotPushed(DedupeBitrix24ContactPhonesJob::class);
        Queue::assertNotPushed(LogBitrix24RawContactPhoneSnapshotJob::class);
    }

    public function test_live_payload_omits_optional_crm_binding_fields_without_phone_and_last_name(): void
    {
        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'name' => 'Live Contact',
            'first_name' => null,
            'last_name' => null,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Минимальный live payload',
            'received_at' => Carbon::parse('2026-04-01 13:45:00', 'Europe/Moscow'),
        ]);

        $payload = app(BuildBitrix24OpenLinesMessagePayloadAction::class)->handle(
            $message,
            new Bitrix24OpenLinesRouteData(
                platform: Channel::PLATFORM_TELEGRAM,
                connectorCode: 'abrikosoff_telegram',
                lineId: 'line-telegram',
            ),
        );

        $this->assertSame(
            $this->expectedOpenLinesExternalUserId($dialog),
            $payload['MESSAGES'][0]['user']['id'] ?? null,
        );
        $this->assertSame('@'.$dialog->currentContactIdentity->external_username, $payload['MESSAGES'][0]['user']['name'] ?? null);
        $this->assertArrayNotHasKey('last_name', $payload['MESSAGES'][0]['user']);
        $this->assertArrayNotHasKey('phone', $payload['MESSAGES'][0]['user']);
        $this->assertArrayNotHasKey('crm_contact_id', $payload['MESSAGES'][0]['user']);
        $this->assertArrayNotHasKey('params', $payload['MESSAGES'][0]['message']);
    }

    public function test_live_payload_uses_plain_text_fallback_for_html_message(): void
    {
        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'first_name' => 'Герман',
            'last_name' => 'Германов',
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Привет в Open Lines',
            'text_format' => Message::TEXT_FORMAT_HTML,
            'source_text' => '<b>Привет в Open Lines</b>',
            'received_at' => Carbon::parse('2026-04-01 13:46:00', 'Europe/Moscow'),
        ]);

        $payload = app(BuildBitrix24OpenLinesMessagePayloadAction::class)->handle(
            $message,
            new Bitrix24OpenLinesRouteData(
                platform: Channel::PLATFORM_TELEGRAM,
                connectorCode: 'abrikosoff_telegram',
                lineId: 'line-telegram',
            ),
        );

        $this->assertSame('Привет в Open Lines', $payload['MESSAGES'][0]['message']['text'] ?? null);
    }

    public function test_shared_live_payload_builder_does_not_add_signature_for_manual_reply_without_explicit_fallback_context(): void
    {
        $dialog = $this->createLiveReadyDialog();
        $operator = User::factory()->create([
            'name' => 'Василий',
            'is_active' => true,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_user_id' => $operator->id,
            'text' => 'Текст без подписи',
        ]);

        $payload = app(BuildBitrix24OpenLinesMessagePayloadAction::class)->handle(
            $message,
            new Bitrix24OpenLinesRouteData(
                platform: Channel::PLATFORM_TELEGRAM,
                connectorCode: 'abrikosoff_telegram',
                lineId: 'line-telegram',
            ),
        );

        $this->assertSame('Текст без подписи', $payload['MESSAGES'][0]['message']['text'] ?? null);
    }

    public function test_retry_after_sync_live_payload_includes_explicit_contact_probe_carriers(): void
    {
        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'bitrix24_contact_id' => '70906',
            'first_name' => 'Герман',
            'last_name' => 'Германов',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $dialog->contact_id,
            'phone_raw' => '+7 926 352-71-11',
            'phone_normalized' => '+79263527111',
            'is_primary' => true,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Клиент поделился телефоном',
            'received_at' => Carbon::parse('2026-04-01 13:47:00', 'Europe/Moscow'),
        ]);

        $payload = app(BuildBitrix24OpenLinesMessagePayloadAction::class)->handle(
            $message,
            new Bitrix24OpenLinesRouteData(
                platform: Channel::PLATFORM_TELEGRAM,
                connectorCode: 'abrikosoff_telegram',
                lineId: 'line-telegram',
            ),
            true,
        );

        $this->assertSame('70906', $payload['MESSAGES'][0]['user']['crm_contact_id'] ?? null);
        $this->assertSame('+79263527111', $payload['MESSAGES'][0]['user']['phone'] ?? null);
        $this->assertSame('70906', $payload['MESSAGES'][0]['message']['params']['crm_contact_id_probe'] ?? null);
        $this->assertSame('Y', $payload['MESSAGES'][0]['message']['params']['retry_after_sync_probe'] ?? null);
    }

    public function test_first_live_attach_logs_immediate_snapshot_and_queues_delayed_raw_snapshot_when_diagnostic_is_enabled(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Диагностический attach',
        ]);

        $dialog->forceFill([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ])->save();

        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', true);
        config()->set('bitrix24.duplicate_phone_diagnostic.delay_seconds', 60);

        $diagnosticSpy = Mockery::spy(LogBitrix24RawContactPhoneSnapshotAction::class);
        $this->app->instance(LogBitrix24RawContactPhoneSnapshotAction::class, $diagnosticSpy);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $diagnosticSpy->shouldHaveReceived('handle')
            ->once()
            ->withArgs(static fn (Contact $loggedContact, string $stage, ?Dialog $loggedDialog, ?Message $loggedMessage): bool => $loggedContact->id === $dialog->contact_id
                && $stage === 'after_live_export'
                && $loggedDialog?->id === $dialog->id
                && $loggedMessage?->id === $message->id);

        Queue::assertPushed(LogBitrix24RawContactPhoneSnapshotJob::class, function (LogBitrix24RawContactPhoneSnapshotJob $job) use ($dialog, $message): bool {
            return $job->contactId === $dialog->contact_id
                && $job->stage === 'delayed_post_attach'
                && $job->dialogId === $dialog->id
                && $job->messageId === $message->id;
        });
    }

    public function test_regular_active_dialog_message_does_not_log_or_queue_duplicate_phone_snapshots(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Обычное сообщение в active диалоге',
        ]);

        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', true);

        $diagnosticSpy = Mockery::spy(LogBitrix24RawContactPhoneSnapshotAction::class);
        $this->app->instance(LogBitrix24RawContactPhoneSnapshotAction::class, $diagnosticSpy);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $diagnosticSpy->shouldNotHaveReceived('handle');
        Queue::assertNotPushed(LogBitrix24RawContactPhoneSnapshotJob::class);
    }

    public function test_inbound_contact_share_is_exported_as_synthetic_text(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => null,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Клиент поделился номером телефона';
        });
    }

    public function test_max_bot_started_without_text_is_queued_for_live_export(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog(Channel::PLATFORM_MAX);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => null,
            'message_parameter' => null,
            'raw_payload' => [
                'update_type' => 'bot_started',
            ],
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertTrue($result->queued);
        $this->assertTrue($result->ready);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($message): bool {
            return $job->messageId === $message->id;
        });
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_max_bot_started_live_payload_uses_bot_start_text(): void
    {
        $dialog = $this->createLiveReadyDialog(Channel::PLATFORM_MAX);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => null,
            'message_parameter' => 'deep-link-payload',
            'raw_payload' => [
                'update_type' => 'bot_started',
            ],
            'received_at' => Carbon::parse('2026-04-01 13:49:00', 'Europe/Moscow'),
        ]);

        $payload = app(BuildBitrix24OpenLinesMessagePayloadAction::class)->handle(
            $message,
            new Bitrix24OpenLinesRouteData(
                platform: Channel::PLATFORM_MAX,
                connectorCode: 'abrikosoff_max',
                lineId: 'line-max',
            ),
        );

        $this->assertSame(
            $this->expectedOpenLinesExternalUserId($dialog),
            $payload['MESSAGES'][0]['user']['id'] ?? null,
        );
        $this->assertSame('Клиент запустил MAX-бота', $payload['MESSAGES'][0]['message']['text'] ?? null);
    }

    public function test_telegram_start_live_payload_uses_bot_start_text(): void
    {
        $dialog = $this->createLiveReadyDialog(Channel::PLATFORM_TELEGRAM);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => '/start promo_123',
            'message_parameter' => 'promo_123',
            'raw_payload' => [
                'message' => [
                    'text' => '/start promo_123',
                ],
            ],
            'received_at' => Carbon::parse('2026-04-01 13:50:00', 'Europe/Moscow'),
        ]);

        $payload = app(BuildBitrix24OpenLinesMessagePayloadAction::class)->handle(
            $message,
            new Bitrix24OpenLinesRouteData(
                platform: Channel::PLATFORM_TELEGRAM,
                connectorCode: 'abrikosoff_telegram',
                lineId: 'line-telegram',
            ),
        );

        $this->assertSame('Клиент запустил Telegram-бота', $payload['MESSAGES'][0]['message']['text'] ?? null);
    }

    public function test_blocked_system_event_is_exported_as_system_text(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => null,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Система: Клиент заблокировал бота';
        });
    }

    public function test_unblocked_system_event_payload_uses_system_text(): void
    {
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_UNBLOCKED_BY_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => null,
            'received_at' => Carbon::parse('2026-04-01 13:48:00', 'Europe/Moscow'),
        ]);

        $payload = app(BuildBitrix24OpenLinesMessagePayloadAction::class)->handle(
            $message,
            new Bitrix24OpenLinesRouteData(
                platform: Channel::PLATFORM_TELEGRAM,
                connectorCode: 'abrikosoff_telegram',
                lineId: 'line-telegram',
            ),
        );

        $this->assertSame('Система: Клиент разблокировал бота', $payload['MESSAGES'][0]['message']['text'] ?? null);
    }

    public function test_unknown_system_event_is_not_queued_for_live_export(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => 'unsupported_system_event',
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => null,
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->ready);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        $this->assertDatabaseMissing('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
        ]);
    }

    public function test_missing_openlines_route_config_marks_export_failed_without_sending_request(): void
    {
        $this->makeProfileLinkedActiveBitrix24Connection(
            profileOverrides: [
                'telegram_connector_code' => null,
            ],
        );

        $dialog = $this->createLiveReadyDialog(createOpenLineRoute: false);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Маршрут не настроен',
        ]);

        Http::fake();

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24ApiException was not thrown.');
        } catch (Bitrix24ApiException $exception) {
            $this->assertStringContainsString('route is not configured', $exception->getMessage());
        }

        $dialog->refresh();

        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_FAILED, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
        ]);

        Http::assertNothingSent();
    }

    public function test_already_live_exported_message_is_not_resent(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Уже экспортировано',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'exported_at' => now()->subMinute(),
        ]);

        Http::fake();

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        Http::assertNothingSent();
    }

    public function test_queue_action_does_not_requeue_already_live_exported_message(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Уже в live export',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'exported_at' => now()->subMinute(),
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertTrue($result->ready);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
    }

    public function test_queue_action_does_not_requeue_uncertain_failed_live_export_message(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Uncertain failed live export',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failed_at' => now()->subMinute(),
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
            'failure_reason' => 'Bitrix24 Open Lines live export transport outcome is uncertain.',
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertTrue($result->ready);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);
    }

    public function test_queue_action_requeues_pending_live_export_when_claim_is_expired(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Expired claim should be requeued',
        ]);
        $liveBatchUuid = fake()->uuid();
        $liveClaimUuid = fake()->uuid();

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
            'live_batch_uuid' => $liveBatchUuid,
            'live_claim_uuid' => $liveClaimUuid,
            'live_claimed_at' => now()->subHours(5),
            'live_claim_expires_at' => now()->subHours(4),
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertTrue($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertTrue($result->ready);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($message, $liveBatchUuid): bool {
            return $job->messageId === $message->id
                && $job->liveBatchUuid !== null
                && $job->liveBatchUuid !== $liveBatchUuid;
        });
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
            'live_claim_uuid' => null,
        ]);
    }

    public function test_queue_action_requeues_legacy_pending_live_export_without_claim_metadata(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Legacy pending export should be requeued',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
            'live_batch_uuid' => null,
            'live_claim_uuid' => null,
            'live_claimed_at' => null,
            'live_claim_expires_at' => null,
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertTrue($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertTrue($result->ready);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($message): bool {
            return $job->messageId === $message->id
                && $job->liveBatchUuid !== null;
        });

        $export = Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->firstOrFail();

        $this->assertNotNull($export->live_batch_uuid);
        $this->assertNull($export->live_claim_uuid);
        $this->assertNull($export->live_claim_expires_at);
    }

    public function test_queue_action_requeues_stale_unclaimed_pending_live_export(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Stale unclaimed pending export should be requeued',
        ]);
        $liveBatchUuid = fake()->uuid();

        $export = Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
            'live_batch_uuid' => $liveBatchUuid,
            'live_claim_uuid' => null,
            'live_claimed_at' => null,
            'live_claim_expires_at' => null,
        ]);

        $export->timestamps = false;
        $export->forceFill([
            'updated_at' => now()->subHours(4),
        ])->save();

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertTrue($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertTrue($result->ready);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($message, $liveBatchUuid): bool {
            return $job->messageId === $message->id
                && $job->liveBatchUuid !== null
                && $job->liveBatchUuid !== $liveBatchUuid;
        });
    }

    public function test_queue_action_keeps_fresh_unclaimed_pending_live_export_as_already_pending(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Fresh unclaimed pending export should stay pending',
        ]);
        $liveBatchUuid = fake()->uuid();

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
            'live_batch_uuid' => $liveBatchUuid,
            'live_claim_uuid' => null,
            'live_claimed_at' => null,
            'live_claim_expires_at' => null,
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertFalse($result->queued);
        $this->assertTrue($result->alreadyPending);
        $this->assertTrue($result->ready);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
    }

    public function test_initial_live_export_queue_schedules_delayed_recovery_job_with_same_batch_uuid(): void
    {
        Queue::fake();

        $now = Carbon::parse('2026-04-23 12:00:00', 'Europe/Moscow');
        Carbon::setTestNow($now);
        try {
            $dialog = $this->createLiveReadyDialog();
            $message = $this->makeMessage($dialog, [
                'direction' => Message::DIRECTION_INBOUND,
                'message_kind' => Message::KIND_INBOUND_USER,
                'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                'text' => 'Initial live export should schedule autonomous recovery',
            ]);

            $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

            $this->assertTrue($result->queued);
            $this->assertFalse($result->alreadyPending);
            $this->assertTrue($result->ready);
            Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, 2);

            $immediateBatchUuid = null;

            Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($message, &$immediateBatchUuid): bool {
                if (
                    $job->messageId !== $message->id
                    || $job->delay !== null
                    || $job->afterCommit !== true
                    || blank($job->liveBatchUuid)
                ) {
                    return false;
                }

                $immediateBatchUuid = $job->liveBatchUuid;

                return true;
            });

            Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($message, $now, $immediateBatchUuid): bool {
                return $job->messageId === $message->id
                    && $job->delay instanceof Carbon
                    && $job->afterCommit === false
                    && $job->delay->equalTo($now->copy()->addSeconds(
                        QueueBitrix24LiveMessageExportAction::UNCLAIMED_PENDING_RECOVERY_SECONDS + 5
                    ))
                    && filled($job->liveBatchUuid)
                    && $job->liveBatchUuid === $immediateBatchUuid;
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pending_live_claim_blocks_duplicate_direct_export_send(): void
    {
        $this->makeActiveConnection();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Second writer must be blocked',
        ]);
        $liveBatchUuid = fake()->uuid();
        $liveClaimUuid = fake()->uuid();

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
            'live_batch_uuid' => $liveBatchUuid,
            'live_claim_uuid' => $liveClaimUuid,
            'live_claimed_at' => now()->subSecond(),
            'live_claim_expires_at' => now()->addMinute(),
        ]);

        Http::fake();

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message, liveBatchUuid: $liveBatchUuid);

        Http::assertNothingSent();
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
            'live_batch_uuid' => $liveBatchUuid,
            'live_claim_uuid' => $liveClaimUuid,
        ]);
    }

    public function test_closed_dialog_recovers_to_active_after_successful_live_export(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(dialogAttributes: [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_CLOSED,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Повторно открываем live bridge',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

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

    public function test_failed_dialog_recovers_to_active_after_successful_live_export(): void
    {
        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(dialogAttributes: [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_FAILED,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Восстановление после failed',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'openlines_dialog_reopened',
            'entity_type' => 'dialog',
            'entity_id' => (string) $dialog->id,
            'status' => 'success',
        ]);
    }

    public function test_first_live_attach_queues_contact_phone_dedupe_job(): void
    {
        Queue::fake();
        $this->makeActiveConnection();

        $dialog = $this->createLiveReadyDialog(dialogAttributes: [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Первый live attach',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        Queue::assertPushed(DedupeBitrix24ContactPhonesJob::class, function (DedupeBitrix24ContactPhonesJob $job) use ($dialog): bool {
            return $job->contactId === $dialog->contact_id
                && $job->attempt === 1
                && $job->delay !== null;
        });
    }

    public function test_regular_active_live_export_does_not_queue_contact_phone_dedupe_job(): void
    {
        Queue::fake();
        $this->makeActiveConnection();

        $dialog = $this->createLiveReadyDialog(dialogAttributes: [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
            'bitrix24_live_chat_id' => 'abrikosoff-dialog:100',
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Обычное следующее сообщение',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        Queue::assertNotPushed(DedupeBitrix24ContactPhonesJob::class);
    }

    public function test_contact_phone_dedupe_updates_raw_duplicate_phones_preferring_mobile(): void
    {
        $this->makeActiveConnection();

        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'bitrix24_contact_id' => 'B24-CONTACT-200',
        ]);
        $contact = $dialog->contact()->firstOrFail();

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'ID' => 'B24-CONTACT-200',
                    'PHONE' => [
                        ['VALUE' => '+7 989 713-33-93', 'VALUE_TYPE' => 'WORK'],
                        ['VALUE' => '+79897133393', 'VALUE_TYPE' => 'MOBILE'],
                    ],
                ],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $updated = app(DedupeBitrix24ContactPhonesAction::class)->handle($contact);

        $this->assertTrue($updated);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ($fields['PHONE'] ?? null) === [
                    ['VALUE' => '+79897133393', 'VALUE_TYPE' => 'MOBILE'],
                ];
        });
    }

    public function test_contact_phone_dedupe_skips_update_when_raw_snapshot_has_no_duplicates(): void
    {
        $this->makeActiveConnection();

        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'bitrix24_contact_id' => 'B24-CONTACT-201',
        ]);
        $contact = $dialog->contact()->firstOrFail();

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'ID' => 'B24-CONTACT-201',
                    'PHONE' => [
                        ['VALUE' => '+7 989 713-33-93', 'VALUE_TYPE' => 'MOBILE'],
                        ['VALUE' => '+7 926 352-71-11', 'VALUE_TYPE' => 'WORK'],
                    ],
                ],
            ], 200),
        ]);

        $updated = app(DedupeBitrix24ContactPhonesAction::class)->handle($contact);

        $this->assertFalse($updated);
        Http::assertSentCount(1);
        Http::assertNotSent(function (Request $request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/crm.contact.update.json';
        });
    }

    public function test_contact_phone_dedupe_job_queues_one_retry_when_first_check_has_no_duplicates(): void
    {
        Queue::fake();
        $this->makeActiveConnection();

        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'bitrix24_contact_id' => 'B24-CONTACT-202',
        ]);
        $contact = $dialog->contact()->firstOrFail();

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'ID' => 'B24-CONTACT-202',
                    'PHONE' => [
                        ['VALUE' => '+7 989 713-33-93', 'VALUE_TYPE' => 'MOBILE'],
                    ],
                ],
            ], 200),
        ]);

        app()->call([(new DedupeBitrix24ContactPhonesJob($contact->id, 1)), 'handle']);

        Queue::assertPushed(DedupeBitrix24ContactPhonesJob::class, function (DedupeBitrix24ContactPhonesJob $job) use ($contact): bool {
            return $job->contactId === $contact->id
                && $job->attempt === 2
                && $job->delay !== null;
        });
    }

    public function test_contact_phone_dedupe_job_does_not_queue_third_attempt(): void
    {
        Queue::fake();
        $this->makeActiveConnection();

        $dialog = $this->createLiveReadyDialog(contactAttributes: [
            'bitrix24_contact_id' => 'B24-CONTACT-203',
        ]);
        $contact = $dialog->contact()->firstOrFail();

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => [
                    'ID' => 'B24-CONTACT-203',
                    'PHONE' => [
                        ['VALUE' => '+7 989 713-33-93', 'VALUE_TYPE' => 'MOBILE'],
                    ],
                ],
            ], 200),
        ]);

        app()->call([(new DedupeBitrix24ContactPhonesJob($contact->id, 2)), 'handle']);

        Queue::assertNotPushed(DedupeBitrix24ContactPhonesJob::class);
    }

    public function test_message_from_bitrix24_openlines_is_not_queued_for_reexport(): void
    {
        Queue::fake();

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'text' => 'Эхо от Bitrix',
        ]);

        $result = app(QueueBitrix24LiveMessageExportAction::class)->handle($message);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->ready);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        $this->assertDatabaseMissing('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
        ]);
    }

    private function createLiveReadyDialog(
        string|array $platform = Channel::PLATFORM_TELEGRAM,
        array $contactAttributes = [],
        array $channelAttributes = [],
        array $dialogAttributes = [],
        bool $createOpenLineRoute = true,
    ): Dialog {
        if (is_array($platform)) {
            $dialogAttributes = $platform + $dialogAttributes;
            $platform = Channel::PLATFORM_TELEGRAM;
        }

        [$connectorCode, $lineId] = $this->routeConnectorAndLineForPlatform($platform);
        $profile = $createOpenLineRoute ? $this->currentRuntimeBitrix24Profile() : null;
        $contact = Contact::factory()->create(array_merge([
            'name' => 'Live Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'B24-CONTACT-100',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ], $contactAttributes));
        $channel = $profile instanceof Bitrix24Profile
            ? $this->findChannelByOpenLineRoute($profile, $connectorCode, $lineId)
            : null;

        if ($channel instanceof Channel && $channelAttributes !== []) {
            $channel->forceFill($channelAttributes)->save();
        }

        if (! $channel instanceof Channel) {
            $channel = Channel::factory()->create(array_merge([
                'platform' => $platform,
            ], $channelAttributes));
        }

        $this->markTelegramChannelConnected($channel);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $platform,
            'external_user_id' => $platform.'-user-'.$contact->id,
            'external_username' => $platform.'_user_'.$contact->id,
        ]);

        $dialog = Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $platform.'-chat-100',
        ], $dialogAttributes));

        if ($profile instanceof Bitrix24Profile) {
            $this->pinDialogOpenLineRoute($dialog, $profile, $connectorCode, $lineId);
        }

        return $dialog->fresh(['contact', 'channel', 'currentContactIdentity']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function routeConnectorAndLineForPlatform(string $platform): array
    {
        return match ($platform) {
            Channel::PLATFORM_MAX => ['abrikosoff_max', 'line-max'],
            default => ['abrikosoff_telegram', 'line-telegram'],
        };
    }

    private function currentRuntimeBitrix24Profile(): Bitrix24Profile
    {
        try {
            return app(ResolveCurrentBitrix24ProfileAction::class)->handle();
        } catch (Bitrix24ConnectionStateException) {
            $profile = Bitrix24Profile::query()->updateOrCreate(
                [
                    'portal_domain' => 'crm.alexlesley.biz',
                    'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
                ],
                [
                    'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
                    'display_name' => 'Staging',
                    'client_id' => 'local.app',
                    'application_code' => 'local.app.code',
                    'callback_base_url' => 'https://project.example.com',
                    'telegram_source_id' => 'ABRIKOSOFF_TELEGRAM',
                    'max_source_id' => 'ABRIKOSOFF_MAX',
                    'telegram_connector_code' => 'abrikosoff_telegram',
                    'max_connector_code' => 'abrikosoff_max',
                    'telegram_line_id' => 'line-telegram',
                    'max_line_id' => 'line-max',
                ],
            );

            $this->configureCurrentBitrix24RuntimeProfile($profile);

            return $profile;
        }
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

    private function pinDialogOpenLineRoute(
        Dialog $dialog,
        Bitrix24Profile $profile,
        string $connectorCode,
        string $lineId,
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
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        }

        $dialog->forceFill([
            'bitrix24_open_line_route_id' => $route->id,
        ])->save();

        return $route;
    }

    private function expectedOpenLinesExternalUserId(Dialog $dialog): string
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        return sprintf(
            '%s:channel:%d:user:%s',
            Bitrix24OpenLineRoute::channelTypeForChannel($dialog->channel),
            $dialog->channel_id,
            $dialog->currentContactIdentity?->external_user_id,
        );
    }

    private function expectedManualReplyUserCode(Dialog $dialog, string $connectorCode, string $lineId): string
    {
        $dialog->loadMissing('currentContactIdentity');

        return implode('|', [
            $connectorCode,
            $lineId,
            $dialog->external_chat_id,
            $dialog->currentContactIdentity?->external_user_id,
        ]);
    }

    private function seedSuccessfulManualReplyTransportExport(
        Dialog $dialog,
        string $resolvedChatId,
        ?string $resolvedCrmEntityType = null,
        ?string $resolvedCrmEntityId = null,
    ): Message {
        $previousMessage = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Предыдущий успешный manual reply',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $previousMessage->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD,
            'resolved_bitrix_chat_id' => $resolvedChatId,
            'resolved_crm_entity_type' => $resolvedCrmEntityType,
            'resolved_crm_entity_id' => $resolvedCrmEntityId,
            'bitrix_remote_message_id' => 'seed-remote-message-'.$dialog->id,
            'exported_at' => now()->subMinute(),
        ]);

        return $previousMessage;
    }

    private function seedSuccessfulLegacyManualReplyTransportExport(
        Dialog $dialog,
        string $resolvedChatId,
        ?string $resolvedCrmEntityType = null,
        ?string $resolvedCrmEntityId = null,
    ): Message {
        $previousMessage = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Предыдущий успешный legacy manual reply',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $previousMessage->id,
            'contact_id' => $dialog->contact_id,
            'bitrix24_contact_id' => $dialog->contact()->firstOrFail()->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => $resolvedChatId,
            'resolved_crm_entity_type' => $resolvedCrmEntityType,
            'resolved_crm_entity_id' => $resolvedCrmEntityId,
            'bitrix_remote_message_id' => null,
            'exported_at' => now()->subMinute(),
        ]);

        return $previousMessage;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeMessage(Dialog $dialog, array $attributes = []): Message
    {
        $dialog->loadMissing(['contact', 'channel', 'currentContactIdentity']);

        return Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => (string) fake()->numerify('######'),
            'received_at' => now(),
        ], $attributes));
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
}
