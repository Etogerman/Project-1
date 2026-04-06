<?php

namespace Tests\Feature;

use App\Jobs\EnsureBitrix24DealJob;
use App\Jobs\SyncChatHistoryToBitrix24Job;
use App\Jobs\SyncContactToBitrix24Job;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Services\Bitrix24\QueueBitrix24HistoryExportAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24HistoryExportTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.sources.telegram_id', 'ABRIKOSOFF_TELEGRAM');
        config()->set('bitrix24.features.deals_sync_enabled', false);
        config()->set('bitrix24.features.timeline_history_import_enabled', true);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_contacts_table_has_bitrix24_history_sync_fields_with_expected_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('contacts', [
            'bitrix24_history_sync_status',
            'bitrix24_history_last_synced_at',
            'bitrix24_history_sync_pending',
        ]));
        $this->assertTrue(Schema::hasTable('bitrix24_message_exports'));

        $contact = Contact::factory()->create();
        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED, $contact->bitrix24_history_sync_status);
        $this->assertNull($contact->bitrix24_history_last_synced_at);
        $this->assertFalse($contact->bitrix24_history_sync_pending);
        $this->assertFalse($contact->isBitrix24HistorySyncPending());
    }

    public function test_message_exports_table_enforces_unique_message_and_mode_and_casts_datetimes(): void
    {
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
        ]);
        $message = Message::factory()->create([
            'contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $identity->channel_id,
        ]);

        $export = Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => '777',
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'batch_uuid' => (string) fake()->uuid(),
            'bitrix24_timeline_entry_id' => 'timeline-1',
            'exported_at' => now(),
            'failed_at' => now()->subMinute(),
        ]);

        $export->refresh();

        $this->assertInstanceOf(Carbon::class, $export->exported_at);
        $this->assertInstanceOf(Carbon::class, $export->failed_at);

        $this->expectException(QueryException::class);

        Bitrix24MessageExport::query()->create([
            'message_id' => $message->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => '777',
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_queue_action_queues_ready_synced_contact_with_bitrix24_contact_id(): void
    {
        Queue::fake();

        $contact = $this->createHistoryExportReadyContact();

        $result = app(QueueBitrix24HistoryExportAction::class)->handle($contact);

        $contact->refresh();

        $this->assertTrue($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertTrue($result->ready);
        $this->assertSame($contact->id, $result->rootContactId);
        $this->assertTrue($contact->bitrix24_history_sync_pending);
        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING, $contact->bitrix24_history_sync_status);

        Queue::assertPushed(SyncChatHistoryToBitrix24Job::class, function (SyncChatHistoryToBitrix24Job $job) use ($contact): bool {
            return $job->contactId === $contact->id;
        });
    }

    public function test_queue_action_skips_contact_without_bitrix24_contact_link(): void
    {
        Queue::fake();

        $contact = $this->createHistoryExportReadyContact([
            'bitrix24_contact_id' => null,
        ]);

        $result = app(QueueBitrix24HistoryExportAction::class)->handle($contact);

        $contact->refresh();

        $this->assertFalse($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertFalse($result->ready);
        $this->assertFalse($contact->bitrix24_history_sync_pending);
        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED, $contact->bitrix24_history_sync_status);

        Queue::assertNotPushed(SyncChatHistoryToBitrix24Job::class);
    }

    public function test_queue_action_skips_contact_when_contact_sync_is_not_successful(): void
    {
        Queue::fake();

        $contact = $this->createHistoryExportReadyContact([
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_FAILED,
        ]);

        $result = app(QueueBitrix24HistoryExportAction::class)->handle($contact);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->ready);

        Queue::assertNotPushed(SyncChatHistoryToBitrix24Job::class);
    }

    public function test_queue_action_skips_when_history_feature_flag_is_disabled(): void
    {
        Queue::fake();

        config()->set('bitrix24.features.timeline_history_import_enabled', false);
        $contact = $this->createHistoryExportReadyContact();

        $result = app(QueueBitrix24HistoryExportAction::class)->handle($contact);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->ready);

        Queue::assertNotPushed(SyncChatHistoryToBitrix24Job::class);
    }

    public function test_queue_action_routes_merged_child_to_root_contact(): void
    {
        Queue::fake();

        $root = $this->createHistoryExportReadyContact();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $result = app(QueueBitrix24HistoryExportAction::class)->handle($merged);

        $root->refresh();
        $merged->refresh();

        $this->assertTrue($result->queued);
        $this->assertSame($root->id, $result->rootContactId);
        $this->assertTrue($root->bitrix24_history_sync_pending);
        $this->assertFalse($merged->bitrix24_history_sync_pending);

        Queue::assertPushed(SyncChatHistoryToBitrix24Job::class, function (SyncChatHistoryToBitrix24Job $job) use ($root): bool {
            return $job->contactId === $root->id;
        });
    }

    public function test_queue_action_does_not_enqueue_duplicate_history_job_for_pending_contact(): void
    {
        Queue::fake();

        $contact = $this->createHistoryExportReadyContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);

        $result = app(QueueBitrix24HistoryExportAction::class)->handle($contact);

        $this->assertFalse($result->queued);
        $this->assertTrue($result->alreadyPending);
        $this->assertTrue($result->ready);

        Queue::assertNotPushed(SyncChatHistoryToBitrix24Job::class);
    }

    public function test_queue_action_preserves_synced_status_for_reexport(): void
    {
        Queue::fake();

        $contact = $this->createHistoryExportReadyContact([
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED,
        ]);

        app(QueueBitrix24HistoryExportAction::class)->handle($contact);

        $contact->refresh();

        $this->assertTrue($contact->bitrix24_history_sync_pending);
        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED, $contact->bitrix24_history_sync_status);
    }

    public function test_successful_contact_sync_job_queues_history_export(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createContactReadyForInitialBitrixSync($channel);
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

        $job = new SyncContactToBitrix24Job($contact->id);
        app()->call([$job, 'handle']);

        $contact->refresh();

        $this->assertSame('501', $contact->bitrix24_contact_id);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertTrue($contact->bitrix24_history_sync_pending);
        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING, $contact->bitrix24_history_sync_status);

        Queue::assertPushed(SyncChatHistoryToBitrix24Job::class, function (SyncChatHistoryToBitrix24Job $queuedJob) use ($contact): bool {
            return $queuedJob->contactId === $contact->id;
        });
        Queue::assertNotPushed(EnsureBitrix24DealJob::class);
    }

    public function test_stub_history_job_clears_pending_flag_for_contact_that_is_no_longer_eligible(): void
    {
        $contact = $this->createHistoryExportReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);

        $job = new SyncChatHistoryToBitrix24Job($contact->id);
        app()->call([$job, 'handle']);

        $contact->refresh();

        $this->assertFalse($contact->bitrix24_history_sync_pending);
        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING, $contact->bitrix24_history_sync_status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createHistoryExportReadyContact(array $overrides = []): Contact
    {
        $contact = Contact::factory()->create(array_merge([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '24_29',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => '777',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ], $overrides));

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-'.$contact->id,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        return $contact->fresh();
    }

    private function createContactReadyForInitialBitrixSync(Channel $channel): Contact
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
            'gender' => 'male',
            'age_years' => 28,
            'age_range' => '24_29',
            'country' => 'Россия',
            'city' => 'Москва',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-'.$contact->id,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        return $contact->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeActiveConnection(array $overrides = []): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate(array_merge([
            'portal_domain' => 'crm.alexlesley.biz',
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.app',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm'],
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'installed_at' => now()->subHour(),
            'last_install_callback_at' => now()->subHour(),
        ], $overrides));
    }

    private function makeTelegramChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'name' => 'Telegram Sales',
            'bot_name' => 'Abrikosoff TG',
            'bot_code' => 'abrikosoff_tg',
        ]);
    }
}
