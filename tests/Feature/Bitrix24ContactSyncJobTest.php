<?php

namespace Tests\Feature;

use App\Jobs\SyncContactToBitrix24Job;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Bitrix24ContactSyncJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.sources.telegram_id', 'ABRIKOSOFF_TELEGRAM');
        config()->set('bitrix24.sources.max_id', 'ABRIKOSOFF_MAX');
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_job_creates_bitrix24_contact_when_duplicate_search_returns_no_matches(): void
    {
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
                && $fields['SOURCE_ID'] === 'ABRIKOSOFF_TELEGRAM'
                && $fields['UF_CRM_ABRIKOSOFF_CONTACT_ID'] === (string) $contact->id
                && $fields['UF_CRM_ABRIKOSOFF_CHANNEL_ID'] === (string) $channel->id
                && $fields['UF_CRM_ABRIKOSOFF_PLATFORM'] === Channel::PLATFORM_TELEGRAM
                && $fields['UF_CRM_ABRIKOSOFF_BOT_CODE'] === 'abrikosoff_tg'
                && $fields['UF_CRM_ABRIKOSOFF_BOT_NAME'] === 'Abrikosoff TG'
                && $fields['PHONE'][0]['VALUE'] === '+79991234567'
                && $fields['PHONE'][0]['VALUE_TYPE'] === 'MOBILE'
                && $fields['PHONE'][1]['VALUE'] === '+79995555555'
                && $fields['PHONE'][1]['VALUE_TYPE'] === 'OTHER';
        });
    }

    public function test_job_links_existing_unique_match_without_creating_new_contact(): void
    {
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
        config()->set('bitrix24.sources.telegram_id', null);

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

    public function test_linked_contact_with_matching_remote_snapshot_is_a_noop_sync(): void
    {
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
                && ($fields['SOURCE_ID'] ?? null) === 'ABRIKOSOFF_TELEGRAM'
                && ($fields['UF_CRM_ABRIKOSOFF_CHANNEL_NAME'] ?? null) === 'Telegram Sales';
        });
    }

    public function test_training_verified_name_preserves_remote_name_and_updates_alt_fields(): void
    {
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

    public function test_trusted_remote_gender_is_preserved(): void
    {
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

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.contact.update.json') {
                return false;
            }

            $fields = $request['fields'];

            return is_array($fields)
                && ! array_key_exists('UF_CRM_5EEB7355C13B1', $fields);
        });

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'contact_sync_gender_preserved',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_unknown_remote_gender_can_be_updated(): void
    {
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

    public function test_remote_only_phone_is_preserved_during_update(): void
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
                && $phones[1]['VALUE'] === '+7 900 000 00 00'
                && $phones[1]['VALUE_TYPE'] === 'WORK';
        });
    }

    public function test_repeated_identical_sync_is_idempotent_and_skips_update(): void
    {
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

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame('999', $contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_FAILED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
    }

    public function test_unique_match_is_linked_and_safely_updated_when_remote_diff_exists(): void
    {
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
        $contact = $this->createSyncReadyContact([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ]);

        Http::fake();

        $this->runSyncJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_FAILED, $contact->bitrix24_sync_status);
        $this->assertFalse($contact->bitrix24_sync_pending);
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
     * @return array<string, mixed>
     */
    private function makeBitrix24ContactSnapshot(Contact $contact, Channel $channel, array $overrides = []): array
    {
        return array_replace([
            'ID' => (string) ($contact->bitrix24_contact_id ?? '555'),
            'NAME' => (string) $contact->first_name,
            'LAST_NAME' => (string) $contact->last_name,
            'SOURCE_ID' => 'ABRIKOSOFF_TELEGRAM',
            'ADDRESS_CITY' => (string) $contact->city,
            'ADDRESS_COUNTRY' => (string) $contact->country,
            'UF_CRM_64D7457E4DC07' => (string) config('bitrix24.values.name_source.self_reported_id'),
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
                    'VALUE_TYPE' => $phone->is_primary ? 'MOBILE' : 'OTHER',
                ])
                ->values()
                ->all(),
        ], $overrides);
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

    private function runSyncJob(Contact $contact): void
    {
        $job = new SyncContactToBitrix24Job($contact->id);

        app()->call([$job, 'handle']);
    }
}
