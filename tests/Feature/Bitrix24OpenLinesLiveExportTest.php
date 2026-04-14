<?php

namespace Tests\Feature;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bots\IncomingBotMessage;
use App\Jobs\DedupeBitrix24ContactPhonesJob;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\LogBitrix24RawContactPhoneSnapshotJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Services\Bitrix24\BuildBitrix24OpenLinesMessagePayloadAction;
use App\Services\Bitrix24\DedupeBitrix24ContactPhonesAction;
use App\Services\Bitrix24\ExportMessageToBitrix24OpenLinesAction;
use App\Services\Bitrix24\LogBitrix24RawContactPhoneSnapshotAction;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\Bitrix24\Bitrix24ApiException;
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
use Tests\TestCase;

class Bitrix24OpenLinesLiveExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Europe/Moscow');
        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.features.openlines_enabled', true);
        config()->set('bitrix24.openlines.telegram_connector_code', 'abrikosoff_telegram');
        config()->set('bitrix24.openlines.telegram_line_id', 'line-telegram');
        config()->set('bitrix24.openlines.max_connector_code', 'abrikosoff_max');
        config()->set('bitrix24.openlines.max_line_id', 'line-max');
        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', false);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_dialogs_table_has_bitrix24_live_fields_with_expected_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('dialogs', [
            'bitrix24_live_chat_id',
            'bitrix24_live_status',
            'bitrix24_live_last_exported_at',
            'bitrix24_live_last_imported_at',
        ]));

        $dialog = Dialog::factory()->create();
        $dialog->refresh();

        $this->assertNull($dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED, $dialog->bitrix24_live_status);
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

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, 3);
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
                && ($payload['MESSAGES'][0]['user']['name'] ?? null) === 'Герман Германов'
                && ($payload['MESSAGES'][0]['user']['last_name'] ?? null) === 'Германов'
                && ($payload['MESSAGES'][0]['user']['phone'] ?? null) === '+79263527111'
                && ($payload['MESSAGES'][0]['message']['id'] ?? null) === 'abrikosoff-message:'.$message->id
                && ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Привет в Open Lines';
        });
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
            'status' => \App\Models\Bitrix24SyncLog::STATUS_SUCCESS,
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

        $this->assertSame('Live Contact', $payload['MESSAGES'][0]['user']['name'] ?? null);
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

        try {
            app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);
            $this->fail('Expected Bitrix24ApiException was not thrown.');
        } catch (Bitrix24ApiException) {
            // Expected route failure.
        }

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $payload);

            return ($payload['MESSAGES'][0]['message']['text'] ?? null) === 'Клиент поделился номером телефона';
        });
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
        $this->makeActiveConnection();
        config()->set('bitrix24.openlines.telegram_connector_code', '');

        $dialog = $this->createLiveReadyDialog();
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Маршрут не настроен',
        ]);

        Http::fake();

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

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
        string $platform = Channel::PLATFORM_TELEGRAM,
        array $contactAttributes = [],
        array $channelAttributes = [],
        array $dialogAttributes = [],
    ): Dialog {
        $contact = Contact::factory()->create(array_merge([
            'name' => 'Live Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'B24-CONTACT-100',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ], $contactAttributes));
        $channel = Channel::factory()->create(array_merge([
            'platform' => $platform,
        ], $channelAttributes));
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $platform,
            'external_user_id' => $platform.'-user-100',
            'external_username' => $platform.'_user_100',
        ]);

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $platform.'-chat-100',
        ], $dialogAttributes));
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
}
