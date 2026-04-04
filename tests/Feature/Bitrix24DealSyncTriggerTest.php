<?php

namespace Tests\Feature;

use App\Jobs\EnsureBitrix24DealJob;
use App\Jobs\SyncContactToBitrix24Job;
use App\Models\Bitrix24Connection;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Services\Bitrix24\QueueBitrix24DealSyncAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24DealSyncTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.sources.telegram_id', 'ABRIKOSOFF_TELEGRAM');
        config()->set('bitrix24.features.deals_sync_enabled', true);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_contacts_table_has_bitrix24_deal_sync_fields_with_expected_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('contacts', [
            'bitrix24_deal_id',
            'bitrix24_deal_sync_status',
            'bitrix24_deal_last_synced_at',
            'bitrix24_deal_linked_at',
            'bitrix24_deal_sync_pending',
        ]));

        $contact = Contact::factory()->create();
        $contact->refresh();

        $this->assertNull($contact->bitrix24_deal_id);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED, $contact->bitrix24_deal_sync_status);
        $this->assertNull($contact->bitrix24_deal_last_synced_at);
        $this->assertNull($contact->bitrix24_deal_linked_at);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);
    }

    public function test_queue_action_queues_ready_synced_contact_with_bitrix24_contact_id(): void
    {
        Queue::fake();

        $contact = $this->createDealSyncReadyContact();

        $result = app(QueueBitrix24DealSyncAction::class)->handle($contact);

        $contact->refresh();

        $this->assertTrue($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertTrue($result->ready);
        $this->assertSame($contact->id, $result->rootContactId);
        $this->assertTrue($contact->bitrix24_deal_sync_pending);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING, $contact->bitrix24_deal_sync_status);

        Queue::assertPushed(EnsureBitrix24DealJob::class, function (EnsureBitrix24DealJob $job) use ($contact): bool {
            return $job->contactId === $contact->id;
        });
    }

    public function test_queue_action_skips_contact_without_bitrix24_contact_link(): void
    {
        Queue::fake();

        $contact = $this->createDealSyncReadyContact([
            'bitrix24_contact_id' => null,
        ]);

        $result = app(QueueBitrix24DealSyncAction::class)->handle($contact);

        $contact->refresh();

        $this->assertFalse($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertFalse($result->ready);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED, $contact->bitrix24_deal_sync_status);

        Queue::assertNotPushed(EnsureBitrix24DealJob::class);
    }

    public function test_queue_action_skips_contact_when_contact_sync_is_not_successful(): void
    {
        Queue::fake();

        $contact = $this->createDealSyncReadyContact([
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_FAILED,
        ]);

        $result = app(QueueBitrix24DealSyncAction::class)->handle($contact);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->ready);

        Queue::assertNotPushed(EnsureBitrix24DealJob::class);
    }

    public function test_queue_action_skips_when_deals_feature_flag_is_disabled(): void
    {
        Queue::fake();

        config()->set('bitrix24.features.deals_sync_enabled', false);
        $contact = $this->createDealSyncReadyContact();

        $result = app(QueueBitrix24DealSyncAction::class)->handle($contact);

        $this->assertFalse($result->queued);
        $this->assertFalse($result->ready);

        Queue::assertNotPushed(EnsureBitrix24DealJob::class);
    }

    public function test_queue_action_routes_merged_child_to_root_contact(): void
    {
        Queue::fake();

        $root = $this->createDealSyncReadyContact();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $result = app(QueueBitrix24DealSyncAction::class)->handle($merged);

        $root->refresh();
        $merged->refresh();

        $this->assertTrue($result->queued);
        $this->assertSame($root->id, $result->rootContactId);
        $this->assertTrue($root->bitrix24_deal_sync_pending);
        $this->assertFalse($merged->bitrix24_deal_sync_pending);

        Queue::assertPushed(EnsureBitrix24DealJob::class, function (EnsureBitrix24DealJob $job) use ($root): bool {
            return $job->contactId === $root->id;
        });
    }

    public function test_queue_action_does_not_enqueue_duplicate_deal_job_for_pending_contact(): void
    {
        Queue::fake();

        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        $result = app(QueueBitrix24DealSyncAction::class)->handle($contact);

        $this->assertFalse($result->queued);
        $this->assertTrue($result->alreadyPending);
        $this->assertTrue($result->ready);

        Queue::assertNotPushed(EnsureBitrix24DealJob::class);
    }

    public function test_ensure_deal_job_uses_without_overlapping_middleware_per_contact(): void
    {
        $job = new EnsureBitrix24DealJob(321);

        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('bitrix24:deal-sync:321', $middleware[0]->key);
        $this->assertSame(10, $middleware[0]->releaseAfter);
        $this->assertSame(180, $middleware[0]->expiresAfter);
    }

    public function test_queue_action_preserves_pending_review_status(): void
    {
        Queue::fake();

        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW,
        ]);

        app(QueueBitrix24DealSyncAction::class)->handle($contact);

        $contact->refresh();

        $this->assertTrue($contact->bitrix24_deal_sync_pending);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW, $contact->bitrix24_deal_sync_status);
    }

    public function test_successful_contact_sync_job_queues_deal_sync(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createContactReadyForInitialBitrixSync(channel: $channel);
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
        $this->assertTrue($contact->bitrix24_deal_sync_pending);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING, $contact->bitrix24_deal_sync_status);

        Queue::assertPushed(EnsureBitrix24DealJob::class, function (EnsureBitrix24DealJob $queuedJob) use ($contact): bool {
            return $queuedJob->contactId === $contact->id;
        });
    }

    public function test_stub_deal_job_clears_pending_flag_for_contact_that_is_no_longer_eligible(): void
    {
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        $job = new EnsureBitrix24DealJob($contact->id);
        app()->call([$job, 'handle']);

        $contact->refresh();

        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING, $contact->bitrix24_deal_sync_status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDealSyncReadyContact(array $overrides = []): Contact
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

    private function createContactReadyForInitialBitrixSync(?Channel $channel = null): Contact
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

        $channel ??= $this->makeTelegramChannel();

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
        return Bitrix24Connection::query()->create(array_merge([
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
            'installed_at' => now(),
        ], $overrides));
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
}
