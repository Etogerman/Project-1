<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24DealSyncQueueResultData;
use App\Data\Bitrix24\Bitrix24HistoryExportQueueResultData;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\SyncContactToBitrix24Job;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\IsContactReadyForBitrix24SyncAction;
use App\Services\Bitrix24\LogBitrix24RawContactPhoneSnapshotAction;
use App\Services\Bitrix24\QueueBitrix24DealSyncAction;
use App\Services\Bitrix24\QueueBitrix24HistoryExportAction;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\Bitrix24\QueueMissedBitrix24OpenLinesRetryAction;
use App\Services\Bitrix24\SyncContactToBitrix24Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24ContactSyncJobTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.sources.telegram_id', 'ABC_TELEGRAM');
        config()->set('bitrix24.sources.max_id', 'ABC_MAX');
        config()->set('bitrix24.features.openlines_enabled', true);
        config()->set('bitrix24.openlines.telegram_connector_code', 'abrikosoff_telegram');
        config()->set('bitrix24.openlines.telegram_line_id', 'line-telegram');
        config()->set('bitrix24.openlines.max_connector_code', 'abrikosoff_max');
        config()->set('bitrix24.openlines.max_line_id', 'line-max');
        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', false);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_first_successful_contact_sync_queues_retry_for_missed_inbound_and_constructor_outbound_live_messages(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
            'bitrix24_sync_fingerprint' => null,
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel);
        $missedInbound = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Первое сообщение до sync',
            'received_at' => now()->subMinute(),
        ]);
        $missedConstructorOutbound = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BOT_CONSTRUCTOR_BLOCK,
            'text' => 'Сообщение конструктора до sync',
            'received_at' => now(),
        ]);

        $initialQueueResult = app(QueueBitrix24LiveMessageExportAction::class)->handle($missedInbound);
        $initialOutboundQueueResult = app(QueueBitrix24LiveMessageExportAction::class)->handle($missedConstructorOutbound);

        $this->assertFalse($initialQueueResult->queued);
        $this->assertFalse($initialQueueResult->ready);
        $this->assertFalse($initialOutboundQueueResult->queued);
        $this->assertFalse($initialOutboundQueueResult->ready);

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 501,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($missedInbound): bool {
            return $job->messageId === $missedInbound->id
                && $job->retryAfterSync === true;
        });
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($missedConstructorOutbound): bool {
            return $job->messageId === $missedConstructorOutbound->id
                && $job->retryAfterSync === true;
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $missedInbound->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $missedConstructorOutbound->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_contact_sync_uses_current_runtime_profile_connection_when_multiple_active_connections_exist(): void
    {
        Queue::fake();

        $this->makeActiveConnection([
            'client_endpoint' => 'https://selected-client.example/rest/',
            'server_endpoint' => 'https://selected-server.example/rest/',
        ]);
        $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: [
                'member_id' => 'member-2',
                'application_token' => 'application-token-2',
                'client_endpoint' => 'https://ignored-client.example/rest/',
                'server_endpoint' => 'https://ignored-server.example/rest/',
            ],
            profileOverrides: [
                'profile_key' => 'dev-alex',
                'display_name' => 'Dev Alex',
                'application_code' => 'local.app.code.dev-alex',
                'callback_base_url' => 'https://other.example.com',
            ],
            useForCurrentRuntime: false,
        );

        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
            'bitrix24_sync_fingerprint' => null,
        ], channel: $channel);

        Http::fake([
            'https://selected-client.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://selected-client.example/rest/crm.contact.add.json' => Http::response([
                'result' => 777,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('777', $contact->bitrix24_contact_id);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://selected-client.example/rest/'));
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://ignored-client.example/rest/'));
    }

    public function test_first_successful_contact_sync_queues_retry_for_missed_unsubscribe_system_event(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
            'bitrix24_sync_fingerprint' => null,
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel);
        $missedSystemEvent = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => 'Клиент заблокировал бота',
            'received_at' => now()->subMinute(),
        ]);

        $initialQueueResult = app(QueueBitrix24LiveMessageExportAction::class)->handle($missedSystemEvent);

        $this->assertFalse($initialQueueResult->queued);
        $this->assertFalse($initialQueueResult->ready);

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 501,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($missedSystemEvent): bool {
            return $job->messageId === $missedSystemEvent->id
                && $job->retryAfterSync === true;
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $missedSystemEvent->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_resync_of_already_linked_contact_does_not_queue_missed_live_retry(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subDay(),
            'bitrix24_sync_fingerprint' => 'existing-sync',
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel, [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ]);
        $missedInbound = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Старое missed сообщение',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                ]),
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        $this->assertDatabaseMissing('bitrix24_message_exports', [
            'message_id' => $missedInbound->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
        ]);
    }

    public function test_missed_live_retry_skips_latest_non_exportable_message_and_queues_older_exportable_candidate(): void
    {
        Queue::fake();

        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '70739',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subMinute(),
        ], channel: $channel);

        $readyDialog = $this->makeDialog($contact, $channel);
        $olderExportableMessage = $this->makeMessage($readyDialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'JBTTRENING',
            'received_at' => now()->subMinutes(2),
        ]);

        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Channel',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $this->attachChannelIdentity($contact, $maxChannel);

        $blockedDialog = $this->makeDialog($contact, $maxChannel);
        $latestNonExportableMessage = $this->makeMessage($blockedDialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => '',
            'received_at' => now()->subMinute(),
        ]);

        $queued = app(QueueMissedBitrix24OpenLinesRetryAction::class)->handle($contact);

        $this->assertTrue($queued);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($olderExportableMessage): bool {
            return $job->messageId === $olderExportableMessage->id
                && $job->retryAfterSync === true;
        });
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($latestNonExportableMessage): bool {
            return $job->messageId === $latestNonExportableMessage->id;
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $olderExportableMessage->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
        $this->assertDatabaseMissing('bitrix24_message_exports', [
            'message_id' => $latestNonExportableMessage->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
        ]);
    }

    public function test_missed_live_retry_skips_message_from_dialog_that_is_not_live_ready(): void
    {
        Queue::fake();

        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '70740',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subMinute(),
        ], channel: $channel);

        $readyDialog = $this->makeDialog($contact, $channel);
        $olderReadyMessage = $this->makeMessage($readyDialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Готовый диалог',
            'received_at' => now()->subMinutes(2),
        ]);

        $secondaryChannel = Channel::factory()->create([
            'name' => 'Telegram Secondary',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'bot_username' => 'secondary_tg',
            'bot_name' => 'Secondary TG',
        ]);
        $this->attachChannelIdentity($contact, $secondaryChannel);

        $notReadyDialog = $this->makeDialog($contact, $secondaryChannel, [
            'external_chat_id' => 'secondary-chat-'.$contact->id,
        ]);
        $latestNotReadyMessage = $this->makeMessage($notReadyDialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Новый диалог без route data',
            'received_at' => now()->subMinute(),
        ]);

        $queued = app(QueueMissedBitrix24OpenLinesRetryAction::class)->handle($contact);

        $this->assertTrue($queued);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($olderReadyMessage): bool {
            return $job->messageId === $olderReadyMessage->id
                && $job->retryAfterSync === true;
        });
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($latestNotReadyMessage): bool {
            return $job->messageId === $latestNotReadyMessage->id;
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $olderReadyMessage->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
        $this->assertDatabaseMissing('bitrix24_message_exports', [
            'message_id' => $latestNotReadyMessage->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
        ]);
    }

    public function test_missed_live_retry_scans_next_batch_until_it_finds_exportable_candidate(): void
    {
        Queue::fake();

        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '70741',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subMinute(),
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel);
        $validFallbackMessage = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Самый старый, но валидный missed inbound',
            'received_at' => now()->subHours(2),
        ]);

        for ($offset = 1; $offset <= 50; $offset++) {
            $this->makeMessage($dialog, [
                'direction' => Message::DIRECTION_INBOUND,
                'message_kind' => Message::KIND_INBOUND_USER,
                'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                'text' => '',
                'received_at' => now()->subMinutes($offset),
            ]);
        }

        $queued = app(QueueMissedBitrix24OpenLinesRetryAction::class)->handle($contact);

        $this->assertTrue($queued);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($validFallbackMessage): bool {
            return $job->messageId === $validFallbackMessage->id
                && $job->retryAfterSync === true;
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $validFallbackMessage->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_missed_live_retry_does_not_requeue_uncertain_failed_candidate(): void
    {
        Queue::fake();

        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '70742',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subMinute(),
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Uncertain failed missed inbound',
        ]);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'failed_at' => now()->subMinute(),
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
            'failure_reason' => 'Bitrix24 Open Lines live export transport outcome is uncertain.',
        ]);

        $queued = app(QueueMissedBitrix24OpenLinesRetryAction::class)->handle($contact);

        $this->assertFalse($queued);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'failure_code' => Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            'failure_uncertain' => true,
        ]);
    }

    public function test_first_successful_contact_sync_logs_raw_duplicate_phone_snapshot_when_diagnostic_is_enabled(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
            'bitrix24_sync_fingerprint' => null,
        ], channel: $channel);

        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', true);

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        $diagnosticSpy = Mockery::spy(LogBitrix24RawContactPhoneSnapshotAction::class);
        $this->app->instance(LogBitrix24RawContactPhoneSnapshotAction::class, $diagnosticSpy);

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 501,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $diagnosticSpy->shouldHaveReceived('handle')
            ->once()
            ->withArgs(static fn (Contact $loggedContact, string $stage): bool => $loggedContact->id === $contact->id
                && $stage === 'after_contact_sync');
    }

    public function test_resync_of_already_linked_contact_does_not_log_after_contact_sync_snapshot(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subDay(),
            'bitrix24_sync_fingerprint' => 'existing-sync',
        ], channel: $channel);

        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', true);

        $diagnosticSpy = Mockery::spy(LogBitrix24RawContactPhoneSnapshotAction::class);
        $this->app->instance(LogBitrix24RawContactPhoneSnapshotAction::class, $diagnosticSpy);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                ]),
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $diagnosticSpy->shouldNotHaveReceived('handle');
    }

    public function test_job_creates_bitrix24_contact_when_duplicate_search_returns_no_matches(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact(
            channel: $channel,
            phones: [
                ['raw' => '+7 999 123 45 67', 'normalized' => '+79991234567', 'is_primary' => true],
                ['raw' => '+7 999 555 55 55', 'normalized' => '+79995555555', 'is_primary' => false],
            ],
        );

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::sequence()
                ->push(['result' => ['CONTACT' => []]], 200)
                ->push(['result' => ['CONTACT' => []]], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 501,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('501', $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_SYNCED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertNotNull($contact->bitrix24_linked_at);
        $this->assertNotNull($contact->bitrix24_last_synced_at);
        $this->assertNotNull($contact->bitrix24_sync_fingerprint);

        Http::assertSent(function ($request) use ($contact, $channel): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.add.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && $fields['NAME'] === 'Герман'
                && $fields['LAST_NAME'] === 'Абрикосов'
                && $fields['SOURCE_ID'] === 'ABC_TELEGRAM'
                && $fields['UF_CRM_ABRIKOSOFF_CONTACT_ID'] === (string) $contact->id
                && $fields['UF_CRM_ABRIKOSOFF_CHANNEL_ID'] === (string) $channel->id
                && $fields['UF_CRM_ABRIKOSOFF_PLATFORM'] === Channel::PLATFORM_TELEGRAM
                && $fields['UF_CRM_ABRIKOSOFF_BOT_CODE'] === 'abrikosoff_tg'
                && $fields['UF_CRM_ABRIKOSOFF_BOT_NAME'] === 'Abrikosoff TG'
                && $fields['PHONE'][0]['VALUE'] === '+79991234567'
                && $fields['PHONE'][0]['VALUE_TYPE'] === 'WORK'
                && $fields['PHONE'][1]['VALUE'] === '+79995555555'
                && $fields['PHONE'][1]['VALUE_TYPE'] === 'OTHER';
        });
    }

    public function test_contact_create_payload_uses_current_profile_crm_schema_settings(): void
    {
        Queue::fake();

        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            profileOverrides: [
                'telegram_source_id' => 'ABC_TELEGRAM_PROFILE',
            ],
        );
        $connection->profile->forceFill([
            'crm_field_name_source' => 'UF_CRM_PROFILE_NAME_SOURCE',
            'crm_field_age_exact' => 'UF_CRM_PROFILE_AGE_EXACT',
            'crm_field_gender' => 'UF_CRM_PROFILE_GENDER',
            'crm_field_age_range' => 'UF_CRM_PROFILE_AGE_RANGE',
            'crm_field_contact_id' => 'UF_CRM_PROFILE_CONTACT_ID',
            'crm_field_channel_id' => 'UF_CRM_PROFILE_CHANNEL_ID',
            'crm_field_channel_name' => 'UF_CRM_PROFILE_CHANNEL_NAME',
            'crm_field_platform' => 'UF_CRM_PROFILE_PLATFORM',
            'crm_field_bot_code' => 'UF_CRM_PROFILE_BOT_CODE',
            'crm_field_bot_name' => 'UF_CRM_PROFILE_BOT_NAME',
            'crm_name_source_self_reported_id' => 9002,
            'crm_gender_male_id' => 9001,
        ])->save();

        $channel = $this->makeTelegramChannel([
            'name' => 'Profile Telegram',
            'bot_username' => 'profile_tg',
            'bot_name' => 'Profile TG',
        ]);
        $contact = $this->createSyncReadyContact(channel: $channel);

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 501,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Http::assertSent(function ($request) use ($contact, $channel): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.add.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ($fields['SOURCE_ID'] ?? null) === 'ABC_TELEGRAM_PROFILE'
                && ($fields['UF_CRM_PROFILE_NAME_SOURCE'] ?? null) === 9002
                && ($fields['UF_CRM_PROFILE_GENDER'] ?? null) === 9001
                && ($fields['UF_CRM_PROFILE_CONTACT_ID'] ?? null) === (string) $contact->id
                && ($fields['UF_CRM_PROFILE_CHANNEL_ID'] ?? null) === (string) $channel->id
                && ($fields['UF_CRM_PROFILE_CHANNEL_NAME'] ?? null) === 'Profile Telegram'
                && ($fields['UF_CRM_PROFILE_PLATFORM'] ?? null) === Channel::PLATFORM_TELEGRAM
                && ($fields['UF_CRM_PROFILE_BOT_CODE'] ?? null) === 'profile_tg'
                && ($fields['UF_CRM_PROFILE_BOT_NAME'] ?? null) === 'Profile TG'
                && ! array_key_exists('UF_CRM_ABRIKOSOFF_CONTACT_ID', $fields)
                && ! array_key_exists('UF_CRM_ABRIKOSOFF_PLATFORM', $fields);
        });
    }

    public function test_job_links_existing_unique_match_without_creating_new_contact(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact(channel: $channel);

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        $remoteSnapshot = $this->makeBitrix24ContactSnapshot($contact, $channel, [
            'ID' => '777',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => ['777']],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $remoteSnapshot,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('777', $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_SYNCED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertNotNull($contact->bitrix24_linked_at);
        $this->assertNotNull($contact->bitrix24_last_synced_at);
        $this->assertNotNull($contact->bitrix24_sync_fingerprint);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/crm.contact.get.json'
                && $request['id'] === '777';
        });
    }

    public function test_job_marks_contact_pending_review_when_duplicate_search_returns_conflicting_matches(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createSyncReadyContact(
            phones: [
                ['raw' => '+7 999 123 45 67', 'normalized' => '+79991234567', 'is_primary' => true],
                ['raw' => '+7 999 555 55 55', 'normalized' => '+79995555555', 'is_primary' => false],
            ],
        );

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::sequence()
                ->push(['result' => ['CONTACT' => ['101']]], 200)
                ->push(['result' => ['CONTACT' => ['202']]], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertNull($contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'contact_sync_conflict',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_job_treats_twenty_or_more_duplicate_matches_as_conflict(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createSyncReadyContact();

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => array_map(static fn (int $id): string => (string) $id, range(1, 20))],
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertNull($contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
    }

    public function test_job_marks_contact_failed_when_source_mapping_is_missing(): void
    {
        $this->makeProfileLinkedActiveBitrix24Connection(
            profileOverrides: [
                'telegram_source_id' => null,
            ],
        );

        $contact = $this->createSyncReadyContact();
        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake();

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertNull($contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_FAILED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        Http::assertNothingSent();
    }

    public function test_fake_happy_path_sync_succeeds_without_active_connection(): void
    {
        Queue::fake();
        Http::fake();

        config()->set('bitrix24.features.fake_happy_path_enabled', true);

        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_linked_at' => null,
            'bitrix24_last_synced_at' => null,
            'bitrix24_sync_fingerprint' => null,
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('FAKE-B24-CONTACT-'.$contact->id, $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_SYNCED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertNotNull($contact->bitrix24_linked_at);
        $this->assertNotNull($contact->bitrix24_last_synced_at);
        $this->assertSame('fake-happy-path:'.$contact->id, $contact->bitrix24_sync_fingerprint);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'contact_sync_fake_succeeded',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);

        Http::assertNothingSent();
    }

    public function test_linked_contact_with_matching_remote_snapshot_is_a_noop_sync(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_sync_fingerprint' => 'old-fingerprint',
        ], channel: $channel);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                ]),
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('999', $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_SYNCED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertNotNull($contact->bitrix24_last_synced_at);
        $this->assertNotSame('old-fingerprint', $contact->bitrix24_sync_fingerprint);

        Http::assertSentCount(1);
    }

    public function test_linked_contact_with_diff_runs_safe_update(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ], channel: $channel);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'ADDRESS_CITY' => 'Казань',
                    'SOURCE_ID' => 'OLD_SOURCE',
                    'UF_CRM_ABRIKOSOFF_CHANNEL_NAME' => 'Old channel name',
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_SYNCED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertNotNull($contact->bitrix24_sync_fingerprint);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ($fields['ADDRESS_CITY'] ?? null) === 'Москва'
                && ($fields['SOURCE_ID'] ?? null) === 'ABC_TELEGRAM'
                && ($fields['UF_CRM_ABRIKOSOFF_CHANNEL_NAME'] ?? null) === 'Telegram Sales';
        });
    }

    public function test_training_verified_name_preserves_remote_name_and_updates_alt_fields(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
            'last_name' => 'Абрикосов',
        ], channel: $channel);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'NAME' => 'Максим',
                    'LAST_NAME' => 'Петров',
                    'UF_CRM_64D7457E4DC07' => (string) config('bitrix24.values.name_source.training_verified_id'),
                    'UF_CRM_ABRIKOSOFF_ALT_FIRST_NAME' => null,
                    'UF_CRM_ABRIKOSOFF_ALT_LAST_NAME' => null,
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ! array_key_exists('NAME', $fields)
                && ! array_key_exists('LAST_NAME', $fields)
                && ($fields['UF_CRM_ABRIKOSOFF_ALT_FIRST_NAME'] ?? null) === 'Герман'
                && ($fields['UF_CRM_ABRIKOSOFF_ALT_LAST_NAME'] ?? null) === 'Абрикосов';
        });

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'contact_sync_name_conflict_warning',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_automatic_name_source_allows_remote_name_overwrite(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
        ], channel: $channel);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'NAME' => 'Максим',
                    'LAST_NAME' => 'Петров',
                    'UF_CRM_64D7457E4DC07' => (string) config('bitrix24.values.name_source.automatic_information_id'),
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ($fields['NAME'] ?? null) === 'Герман'
                && ($fields['LAST_NAME'] ?? null) === 'Абрикосов'
                && ($fields['UF_CRM_64D7457E4DC07'] ?? null) === (int) config('bitrix24.values.name_source.self_reported_id');
        });
    }

    public function test_legacy_remote_name_source_id_is_migrated_to_current_profile_id(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'last_name' => 'Абрикосов',
        ], channel: $channel);

        Http::preventStrayRequests();
        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'NAME' => 'Герман',
                    'LAST_NAME' => 'Абрикосов',
                    'UF_CRM_64D7457E4DC07' => '7179',
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ($fields['UF_CRM_64D7457E4DC07'] ?? null) === (int) config('bitrix24.values.name_source.self_reported_id')
                && ! array_key_exists('UF_CRM_ABRIKOSOFF_ALT_FIRST_NAME', $fields)
                && ! array_key_exists('UF_CRM_ABRIKOSOFF_ALT_LAST_NAME', $fields);
        });

        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'operation' => 'contact_sync_name_conflict_warning',
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_trusted_remote_gender_is_preserved(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'gender' => 'female',
        ], channel: $channel);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'UF_CRM_5EEB7355C13B1' => (string) config('bitrix24.values.gender.male_id'),
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://client-endpoint.example/rest/crm.contact.update.json');

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'contact_sync_gender_preserved',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_unknown_remote_gender_can_be_updated(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'gender' => 'female',
        ], channel: $channel);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'UF_CRM_5EEB7355C13B1' => (string) config('bitrix24.values.gender.unknown_id'),
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ($fields['UF_CRM_5EEB7355C13B1'] ?? null) === (int) config('bitrix24.values.gender.female_id');
        });
    }

    public function test_legacy_remote_gender_id_is_migrated_to_current_profile_id(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'gender' => 'male',
        ], channel: $channel);

        Http::preventStrayRequests();
        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'UF_CRM_5EEB7355C13B1' => '4653',
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ($fields['UF_CRM_5EEB7355C13B1'] ?? null) === (int) config('bitrix24.values.gender.male_id');
        });

        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'operation' => 'contact_sync_gender_preserved',
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_update_normalizes_matching_local_phone_and_preserves_remote_only_phone(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ], channel: $channel);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'PHONE' => [
                        ['VALUE' => '+7 999 123 45 67', 'VALUE_TYPE' => 'MOBILE'],
                        ['VALUE' => '+7 900 000 00 00', 'VALUE_TYPE' => 'WORK'],
                    ],
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];
            $phones = $fields['PHONE'] ?? null;

            return is_array($phones)
                && count($phones) === 2
                && $phones[0]['VALUE'] === '+79991234567'
                && $phones[0]['VALUE_TYPE'] === 'WORK'
                && $phones[1]['VALUE'] === '+7 900 000 00 00'
                && $phones[1]['VALUE_TYPE'] === 'WORK';
        });
    }

    public function test_repeated_identical_sync_is_idempotent_and_skips_update(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_sync_fingerprint' => 'old-fingerprint',
        ], channel: $channel);

        $remoteSnapshot = $this->makeBitrix24ContactSnapshot($contact, $channel, [
            'ID' => '999',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $remoteSnapshot,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();
        $fingerprint = $contact->bitrix24_sync_fingerprint;

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $remoteSnapshot,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame($fingerprint, $contact->bitrix24_sync_fingerprint);
        Http::assertSentCount(1);
    }

    public function test_update_failure_marks_linked_contact_as_failed_but_preserves_link(): void
    {
        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ], channel: $channel);

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                    'ADDRESS_CITY' => 'Казань',
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'error' => 'UPDATE_FAILED',
                'error_description' => 'Bitrix update failed',
            ], 400),
        ]);

        $job = $this->runFinalSyncJob($contact);

        $contact->refresh();

        $this->assertSame('999', $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_FAILED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $job->assertFailed();
    }

    public function test_unique_match_is_linked_and_safely_updated_when_remote_diff_exists(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact(channel: $channel);

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => ['777']],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '777',
                    'ADDRESS_CITY' => 'Казань',
                ]),
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.update.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('777', $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_SYNCED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/crm.contact.update.json'
                && ($request['fields']['ADDRESS_CITY'] ?? null) === 'Москва';
        });
    }

    public function test_second_run_links_existing_remote_contact_instead_of_creating_duplicate(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $contact = $this->createSyncReadyContact();

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 901,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('901', $contact->bitrix24_contact_id);

        $contact->forceFill([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
            'bitrix24_sync_pending' => true,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => ['901']],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => ['ID' => '901', 'NAME' => 'Герман'],
            ], 200),
        ]);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('901', $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_SYNCED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        Http::assertSentCount(2);
    }

    public function test_job_marks_contact_failed_when_duplicate_search_cannot_run(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createSyncReadyContact([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'error' => 'DUPLICATE_SEARCH_FAILED',
                'error_description' => 'Duplicate search failed',
            ], 400),
        ]);

        $job = $this->runFinalSyncJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_FAILED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $job->assertFailed();
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'contact_sync_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_successful_job_calls_follow_up_actions_after_contact_becomes_linked(): void
    {
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
        ]);

        $readyAction = Mockery::mock(IsContactReadyForBitrix24SyncAction::class);
        $readyAction->shouldReceive('handle')
            ->once()
            ->andReturn(true);
        $this->app->instance(IsContactReadyForBitrix24SyncAction::class, $readyAction);

        $syncAction = Mockery::mock(SyncContactToBitrix24Action::class);
        $syncAction->shouldReceive('handle')
            ->once()
            ->withArgs(function (Contact $rootContact) use ($contact): bool {
                $rootContact->forceFill([
                    'bitrix24_contact_id' => '501',
                    'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
                    'bitrix24_linked_at' => now(),
                    'bitrix24_last_synced_at' => now(),
                ])->save();

                return $rootContact->id === $contact->id;
            });
        $this->app->instance(SyncContactToBitrix24Action::class, $syncAction);

        $rawSnapshotAction = Mockery::mock(LogBitrix24RawContactPhoneSnapshotAction::class);
        $rawSnapshotAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact, string $stage): bool => $rootContact->id === $contact->id && $stage === 'after_contact_sync');
        $this->app->instance(LogBitrix24RawContactPhoneSnapshotAction::class, $rawSnapshotAction);

        $retryAction = Mockery::mock(QueueMissedBitrix24OpenLinesRetryAction::class);
        $retryAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->id === $contact->id)
            ->andReturn(true);
        $this->app->instance(QueueMissedBitrix24OpenLinesRetryAction::class, $retryAction);

        $dealAction = Mockery::mock(QueueBitrix24DealSyncAction::class);
        $dealAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->id === $contact->id)
            ->andReturn(new Bitrix24DealSyncQueueResultData(
                queued: true,
                alreadyPending: false,
                ready: true,
                rootContactId: $contact->id,
            ));
        $this->app->instance(QueueBitrix24DealSyncAction::class, $dealAction);

        $historyAction = Mockery::mock(QueueBitrix24HistoryExportAction::class);
        $historyAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->id === $contact->id)
            ->andReturn(new Bitrix24HistoryExportQueueResultData(
                queued: true,
                alreadyPending: false,
                ready: true,
                rootContactId: $contact->id,
            ));
        $this->app->instance(QueueBitrix24HistoryExportAction::class, $historyAction);

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('501', $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_SYNCED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
    }

    public function test_job_logs_critical_and_skips_follow_up_actions_when_sync_throws(): void
    {
        Log::spy();

        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
        ]);

        $readyAction = Mockery::mock(IsContactReadyForBitrix24SyncAction::class);
        $readyAction->shouldReceive('handle')
            ->once()
            ->andReturn(true);
        $this->app->instance(IsContactReadyForBitrix24SyncAction::class, $readyAction);

        $syncAction = Mockery::mock(SyncContactToBitrix24Action::class);
        $syncAction->shouldReceive('handle')
            ->once()
            ->andThrow(new \RuntimeException('Bitrix sync exploded'));
        $this->app->instance(SyncContactToBitrix24Action::class, $syncAction);

        $rawSnapshotAction = Mockery::mock(LogBitrix24RawContactPhoneSnapshotAction::class);
        $rawSnapshotAction->shouldNotReceive('handle');
        $this->app->instance(LogBitrix24RawContactPhoneSnapshotAction::class, $rawSnapshotAction);

        $retryAction = Mockery::mock(QueueMissedBitrix24OpenLinesRetryAction::class);
        $retryAction->shouldNotReceive('handle');
        $this->app->instance(QueueMissedBitrix24OpenLinesRetryAction::class, $retryAction);

        $dealAction = Mockery::mock(QueueBitrix24DealSyncAction::class);
        $dealAction->shouldNotReceive('handle');
        $this->app->instance(QueueBitrix24DealSyncAction::class, $dealAction);

        $historyAction = Mockery::mock(QueueBitrix24HistoryExportAction::class);
        $historyAction->shouldNotReceive('handle');
        $this->app->instance(QueueBitrix24HistoryExportAction::class, $historyAction);

        try {
            $this->runSyncJob($contact);
            $this->fail('Expected contact sync job to bubble the retryable exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Bitrix sync exploded', $exception->getMessage());
        }

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_PENDING, $contact->bitrix24_sync_status);
        $this->assertTrue($contact->bitrix24_sync_pending);
        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'operation' => 'contact_sync_failed',
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);

        Log::shouldNotHaveReceived('critical');
    }

    public function test_job_logs_critical_and_skips_follow_up_actions_on_final_sync_attempt(): void
    {
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
        ]);

        $readyAction = Mockery::mock(IsContactReadyForBitrix24SyncAction::class);
        $readyAction->shouldReceive('handle')
            ->once()
            ->andReturn(true);
        $this->app->instance(IsContactReadyForBitrix24SyncAction::class, $readyAction);

        $syncAction = Mockery::mock(SyncContactToBitrix24Action::class);
        $syncAction->shouldReceive('handle')
            ->once()
            ->andThrow(new \RuntimeException('Bitrix sync exploded'));
        $this->app->instance(SyncContactToBitrix24Action::class, $syncAction);

        $rawSnapshotAction = Mockery::mock(LogBitrix24RawContactPhoneSnapshotAction::class);
        $rawSnapshotAction->shouldNotReceive('handle');
        $this->app->instance(LogBitrix24RawContactPhoneSnapshotAction::class, $rawSnapshotAction);

        $retryAction = Mockery::mock(QueueMissedBitrix24OpenLinesRetryAction::class);
        $retryAction->shouldNotReceive('handle');
        $this->app->instance(QueueMissedBitrix24OpenLinesRetryAction::class, $retryAction);

        $dealAction = Mockery::mock(QueueBitrix24DealSyncAction::class);
        $dealAction->shouldNotReceive('handle');
        $this->app->instance(QueueBitrix24DealSyncAction::class, $dealAction);

        $historyAction = Mockery::mock(QueueBitrix24HistoryExportAction::class);
        $historyAction->shouldNotReceive('handle');
        $this->app->instance(QueueBitrix24HistoryExportAction::class, $historyAction);

        Log::shouldReceive('critical')
            ->once()
            ->with('Bitrix24 contact sync job failed.', Mockery::on(function (array $context) use ($contact): bool {
                return $context['job'] === SyncContactToBitrix24Job::class
                    && $context['contact_id'] === $contact->id
                    && $context['root_contact_id'] === $contact->id
                    && $context['bitrix24_contact_id'] === null
                    && $context['exception_class'] === \RuntimeException::class
                    && $context['exception_message'] === 'Bitrix sync exploded';
            }));

        $job = (new SyncContactToBitrix24Job($contact->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;

        app()->call([$job, 'handle']);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_FAILED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $job->assertFailedWith(\RuntimeException::class);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'contact_sync_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $contactOverrides
     * @param  list<array{raw: string, normalized: string, is_primary: bool}>  $phones
     */
    private function createSyncReadyContact(
        array $contactOverrides = [],
        ?Channel $channel = null,
        array $phones = [
            ['raw' => '+7 999 123 45 67', 'normalized' => '+79991234567', 'is_primary' => true],
        ],
    ): Contact {
        $contact = Contact::factory()->create(array_merge([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'last_name' => 'Абрикосов',
            'gender' => 'male',
            'age_years' => 28,
            'age_range' => '24_29',
            'country' => 'Россия',
            'city' => 'Москва',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ], $contactOverrides));

        $channel ??= $this->makeTelegramChannel();

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-'.$contact->id,
        ]);

        foreach ($phones as $phone) {
            ContactPhoneNumber::factory()->create([
                'contact_id' => $contact->id,
                'phone_raw' => $phone['raw'],
                'phone_normalized' => $phone['normalized'],
                'is_primary' => $phone['is_primary'],
            ]);
        }

        return $contact->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeActiveConnection(array $overrides = []): Bitrix24Connection
    {
        return $this->makeProfileLinkedActiveBitrix24Connection($overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeBitrix24ContactSnapshot(Contact $contact, Channel $channel, array $overrides = []): array
    {
        return array_replace([
            'ID' => (string) ($contact->bitrix24_contact_id ?? '555'),
            'NAME' => (string) $contact->first_name,
            'LAST_NAME' => (string) $contact->last_name,
            'SOURCE_ID' => 'ABC_TELEGRAM',
            'ADDRESS_CITY' => (string) $contact->city,
            'ADDRESS_COUNTRY' => (string) $contact->country,
            'UF_CRM_64D7457E4DC07' => (string) $this->resolveExpectedBitrixNameSourceId($contact),
            'UF_CRM_1606901533' => (string) $contact->effective_age_years,
            'UF_CRM_ABRIKOSOFF_AGE_RANGE' => (string) $contact->age_range,
            'UF_CRM_5EEB7355C13B1' => (string) config('bitrix24.values.gender.male_id'),
            'UF_CRM_ABRIKOSOFF_CONTACT_ID' => (string) $contact->id,
            'UF_CRM_ABRIKOSOFF_CHANNEL_ID' => (string) $channel->id,
            'UF_CRM_ABRIKOSOFF_CHANNEL_NAME' => (string) $channel->name,
            'UF_CRM_ABRIKOSOFF_PLATFORM' => (string) $channel->platform,
            'UF_CRM_ABRIKOSOFF_BOT_CODE' => (string) ($channel->bot_username ?? 'abrikosoff_tg'),
            'UF_CRM_ABRIKOSOFF_BOT_NAME' => (string) ($channel->bot_name ?? $channel->name),
            'UF_CRM_ABRIKOSOFF_ALT_FIRST_NAME' => null,
            'UF_CRM_ABRIKOSOFF_ALT_LAST_NAME' => null,
            'PHONE' => $contact->phoneNumbers()
                ->get()
                ->map(fn (ContactPhoneNumber $phone): array => [
                    'VALUE' => $phone->phone_normalized,
                    'VALUE_TYPE' => $phone->is_primary ? 'WORK' : 'OTHER',
                ])
                ->values()
                ->all(),
        ], $overrides);
    }

    private function resolveExpectedBitrixNameSourceId(Contact $contact): int
    {
        return match ($contact->first_name_source) {
            Contact::FIRST_NAME_SOURCE_AUTO => (int) config('bitrix24.values.name_source.automatic_information_id'),
            Contact::FIRST_NAME_SOURCE_MANUAL => (int) config('bitrix24.values.name_source.training_verified_id'),
            default => (int) config('bitrix24.values.name_source.self_reported_id'),
        };
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTelegramChannel(array $overrides = []): Channel
    {
        return Channel::factory()->create(array_merge([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'bot_username' => 'abrikosoff_tg',
            'bot_name' => 'Abrikosoff TG',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDialog(Contact $contact, Channel $channel, array $overrides = []): Dialog
    {
        $identity = $contact->identities()
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $channel->platform.'-chat-'.$contact->id,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ], $overrides));
    }

    private function attachChannelIdentity(Contact $contact, Channel $channel): ContactIdentity
    {
        return ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $channel->platform.'-user-'.$contact->id.'-'.$channel->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMessage(Dialog $dialog, array $overrides = []): Message
    {
        $dialog->loadMissing(['contact', 'currentContactIdentity']);

        return Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => (string) fake()->numerify('######'),
            'received_at' => now(),
        ], $overrides));
    }

    private function runSyncJob(Contact $contact): void
    {
        $job = new SyncContactToBitrix24Job($contact->id);

        app()->call([$job, 'handle']);
    }

    private function runFinalSyncJob(Contact $contact): SyncContactToBitrix24Job
    {
        $job = (new SyncContactToBitrix24Job($contact->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;

        app()->call([$job, 'handle']);

        return $job;
    }
}
