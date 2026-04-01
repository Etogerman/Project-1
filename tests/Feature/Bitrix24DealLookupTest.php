<?php

namespace Tests\Feature;

use App\Jobs\EnsureBitrix24DealJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Services\Bitrix24\LinkBitrix24DealAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class Bitrix24DealLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.features.deals_sync_enabled', true);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_no_active_deals_create_new_deal_and_link_it_locally(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_id' => '999',
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/crm.deal.add.json' => Http::response([
                'result' => 601,
            ], 200),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame('601', $contact->bitrix24_deal_id);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED, $contact->bitrix24_deal_sync_status);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertNotNull($contact->bitrix24_deal_last_synced_at);
        $this->assertNotNull($contact->bitrix24_deal_linked_at);

        Http::assertSent(function ($request) use ($contact): bool {
            return $request->url() === 'https://client-endpoint.example/rest/crm.deal.list.json'
                && $request['filter']['CONTACT_ID'] === $contact->bitrix24_contact_id
                && $request['filter']['CLOSED'] === 'N';
        });
        Http::assertSent(function ($request) use ($contact): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.deal.add.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ($fields['TITLE'] ?? null) === 'Abrikosoff / Герман'
                && ($fields['CATEGORY_ID'] ?? null) === 22
                && ($fields['STAGE_ID'] ?? null) === 'C22:NEW'
                && ($fields['ASSIGNED_BY_ID'] ?? null) === 1
                && ($fields['CONTACT_ID'] ?? null) === $contact->bitrix24_contact_id
                && ($fields['SOURCE_ID'] ?? null) === 'ABRIKOSOFF_TELEGRAM';
        });

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'deal_sync_created',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_single_active_deal_is_linked_locally(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [
                    [
                        'ID' => '777',
                        'TITLE' => 'Abrikosoff / Герман',
                        'CATEGORY_ID' => '22',
                        'STAGE_ID' => 'C22:NEW',
                        'CLOSED' => 'N',
                        'ASSIGNED_BY_ID' => '1',
                        'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
                    ],
                ],
            ], 200),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame('777', $contact->bitrix24_deal_id);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED, $contact->bitrix24_deal_sync_status);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertNotNull($contact->bitrix24_deal_last_synced_at);
        $this->assertNotNull($contact->bitrix24_deal_linked_at);
    }

    public function test_multiple_active_deals_select_smallest_id_and_mark_pending_review(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [
                    [
                        'ID' => '15',
                        'TITLE' => 'Deal 15',
                        'CATEGORY_ID' => '22',
                        'STAGE_ID' => 'C22:NEW',
                        'CLOSED' => 'N',
                        'ASSIGNED_BY_ID' => '1',
                        'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
                    ],
                    [
                        'ID' => '2',
                        'TITLE' => 'Deal 2',
                        'CATEGORY_ID' => '22',
                        'STAGE_ID' => 'C22:NEW',
                        'CLOSED' => 'N',
                        'ASSIGNED_BY_ID' => '1',
                        'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
                    ],
                    [
                        'ID' => '9',
                        'TITLE' => 'Deal 9',
                        'CATEGORY_ID' => '22',
                        'STAGE_ID' => 'C22:NEW',
                        'CLOSED' => 'N',
                        'ASSIGNED_BY_ID' => '1',
                        'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
                    ],
                ],
            ], 200),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame('2', $contact->bitrix24_deal_id);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW, $contact->bitrix24_deal_sync_status);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertNotNull($contact->bitrix24_deal_last_synced_at);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'deal_sync_multiple_active',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_active_deal_lookup_traverses_pagination(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::sequence()
                ->push([
                    'result' => [
                        [
                            'ID' => '9',
                            'TITLE' => 'Deal 9',
                            'CATEGORY_ID' => '22',
                            'STAGE_ID' => 'C22:NEW',
                            'CLOSED' => 'N',
                            'ASSIGNED_BY_ID' => '1',
                            'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
                        ],
                    ],
                    'next' => 50,
                ], 200)
                ->push([
                    'result' => [
                        [
                            'ID' => '3',
                            'TITLE' => 'Deal 3',
                            'CATEGORY_ID' => '22',
                            'STAGE_ID' => 'C22:NEW',
                            'CLOSED' => 'N',
                            'ASSIGNED_BY_ID' => '1',
                            'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
                        ],
                    ],
                ], 200),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame('3', $contact->bitrix24_deal_id);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW, $contact->bitrix24_deal_sync_status);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/crm.deal.list.json'
                && $request['start'] === 50;
        });

        $lookupLog = Bitrix24SyncLog::query()
            ->where('operation', 'deal_sync_active_lookup')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(2, $lookupLog->response_payload['pages_fetched'] ?? null);
    }

    public function test_malformed_deal_payload_marks_status_failed(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [
                    [
                        'ID' => null,
                        'CLOSED' => 'N',
                    ],
                ],
            ], 200),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED, $contact->bitrix24_deal_sync_status);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'deal_sync_lookup_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_lookup_replaces_stale_local_deal_id_with_current_active_deal(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_id' => '999',
            'bitrix24_deal_linked_at' => now()->subDay(),
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [
                    [
                        'ID' => '101',
                        'TITLE' => 'Deal 101',
                        'CATEGORY_ID' => '22',
                        'STAGE_ID' => 'C22:NEW',
                        'CLOSED' => 'N',
                        'ASSIGNED_BY_ID' => '1',
                        'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
                    ],
                ],
            ], 200),
        ]);

        $originalLinkedAt = $contact->bitrix24_deal_linked_at;

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame('101', $contact->bitrix24_deal_id);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED, $contact->bitrix24_deal_sync_status);
        $this->assertEquals($originalLinkedAt, $contact->bitrix24_deal_linked_at);
    }

    public function test_missing_source_mapping_marks_deal_sync_failed_without_creating_deal(): void
    {
        config()->set('bitrix24.sources.telegram_id', null);

        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [],
            ], 200),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED, $contact->bitrix24_deal_sync_status);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertNull($contact->bitrix24_deal_id);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'deal_sync_config_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_deal_add_failure_marks_status_failed(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/crm.deal.add.json' => Http::response([
                'error' => 'DEAL_ADD_FAILED',
                'error_description' => 'Deal create failed',
            ], 400),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED, $contact->bitrix24_deal_sync_status);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertNull($contact->bitrix24_deal_id);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'deal_sync_create_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_retry_after_remote_create_and_local_link_failure_is_safe(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createDealSyncReadyContact([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ]);

        $this->mock(LinkBitrix24DealAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new \RuntimeException('Local link failed after remote deal create.'));
        });

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [],
            ], 200),
            'https://client-endpoint.example/rest/crm.deal.add.json' => Http::response([
                'result' => 601,
            ], 200),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED, $contact->bitrix24_deal_sync_status);
        $this->assertNull($contact->bitrix24_deal_id);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);

        $contact->forceFill([
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.deal.list.json' => Http::response([
                'result' => [
                    [
                        'ID' => '601',
                        'TITLE' => 'Abrikosoff / Герман',
                        'CATEGORY_ID' => '22',
                        'STAGE_ID' => 'C22:NEW',
                        'CLOSED' => 'N',
                        'ASSIGNED_BY_ID' => '1',
                        'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
                    ],
                ],
            ], 200),
        ]);

        $this->runDealEnsureJob($contact);

        $contact->refresh();

        $this->assertSame('601', $contact->bitrix24_deal_id);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED, $contact->bitrix24_deal_sync_status);
        $this->assertFalse($contact->bitrix24_deal_sync_pending);

        Http::assertSentCount(1);
    }

    private function runDealEnsureJob(Contact $contact): void
    {
        $job = new EnsureBitrix24DealJob($contact->id);

        app()->call([$job, 'handle']);
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
            'installed_at' => now()->subHour(),
            'last_install_callback_at' => now()->subHour(),
        ], $overrides));
    }
}
