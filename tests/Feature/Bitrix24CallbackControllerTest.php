<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrix24InstallCallbackJob;
use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Bitrix24CallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.portal_domain', 'crm.alexlesley.biz');
        config()->set('bitrix24.application.code', 'local.app.code');
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        Http::preventStrayRequests();
        Http::fake([
            'https://crm.alexlesley.biz/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => 'local.app.code',
                    'INSTALLED' => true,
                ],
            ]),
        ]);
    }

    public function test_install_callback_upserts_connection_stores_inbox_event_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/callbacks/bitrix24/install', [
                'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'test-token',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('received', true)
            ->assertJsonPath('callback_type', 'install')
            ->assertJsonPath('method', 'POST');

        $connection = Bitrix24Connection::query()->firstOrFail();

        $this->assertSame('crm.alexlesley.biz', $connection->portal_domain);
        $this->assertSame('member-1', $connection->member_id);
        $this->assertSame('test-token', $connection->application_token);
        $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
        $this->assertSame('secret-access-token', $connection->access_token_encrypted);
        $this->assertSame('secret-refresh-token', $connection->refresh_token_encrypted);
        $this->assertIsArray($connection->install_payload);
        $this->assertArrayNotHasKey('access_token', $connection->install_payload['auth']);
        $this->assertArrayNotHasKey('refresh_token', $connection->install_payload['auth']);

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::TYPE_INSTALL, $event->callback_type);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertArrayNotHasKey('access_token', $event->payload['auth']);
        $this->assertArrayNotHasKey('refresh_token', $event->payload['auth']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://crm.alexlesley.biz/rest/app.info.json'
                && ($request['auth'] ?? null) === 'secret-access-token';
        });

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_accepts_oauth_style_server_endpoint_when_probe_succeeds(): void
    {
        Queue::fake();

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-2',
                'application_token' => 'test-token-2',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token-2',
                'refresh_token' => 'secret-refresh-token-2',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $connection = Bitrix24Connection::query()->firstOrFail();
        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame('member-2', $connection->member_id);
        $this->assertSame('test-token-2', $connection->application_token);
        $this->assertSame('https://oauth.example', $connection->server_endpoint);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_without_oauth_server_configuration_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();
        config()->set('bitrix24.oauth.server_url', null);

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-3',
                'application_token' => 'test-token-3',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token-3',
                'refresh_token' => 'secret-refresh-token-3',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_events_callback_with_valid_auth_creates_pending_inbox_event_and_dispatches_job(): void
    {
        Queue::fake();

        $connection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/callbacks/bitrix24/events', [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => [
                'FIELDS' => [
                    'ID' => 123,
                ],
            ],
            'auth' => [
                'domain' => '5crm-plus.ru',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://client-endpoint.example/rest/',
                'server_endpoint' => 'https://server-endpoint.example/rest/',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'events')
            ->assertJsonPath('method', 'POST');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::TYPE_EVENTS, $event->callback_type);
        $this->assertSame('ONCRMCONTACTUPDATE', $event->event_name);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);

        $connection->refresh();

        $this->assertNotNull($connection->last_events_callback_at);

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_openlines_callback_duplicate_is_deduped_and_job_is_not_redispatched(): void
    {
        Queue::fake();

        Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $payload = [
            'event' => 'OnImConnectorMessageAdd',
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'MESSAGES' => [
                    ['id' => 'm-1'],
                ],
            ],
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
            ],
        ];

        $firstResponse = $this->postJson('/callbacks/bitrix24/openlines', $payload);
        $secondResponse = $this->postJson('/callbacks/bitrix24/openlines', $payload);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_openlines_callback_payload_key_case_is_deduped_by_fingerprint(): void
    {
        Queue::fake();

        Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $payloadWithLowercaseData = [
            'event' => 'OnImConnectorMessageAdd',
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'MESSAGES' => [
                    ['id' => 'm-1'],
                ],
            ],
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
            ],
        ];

        $payloadWithUppercaseData = [
            'event' => 'OnImConnectorMessageAdd',
            'DATA' => [
                'connector' => 'abrikosoff_telegram',
                'MESSAGES' => [
                    ['ID' => 'm-1'],
                ],
            ],
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
            ],
        ];

        $firstResponse = $this->postJson('/callbacks/bitrix24/openlines', $payloadWithLowercaseData);
        $secondResponse = $this->postJson('/callbacks/bitrix24/openlines', $payloadWithUppercaseData);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_openlines_callback_payload_with_conflicting_case_keys_is_not_deduped_against_single_key_payload(): void
    {
        Queue::fake();

        Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $payloadWithConflictingCaseKeys = [
            'event' => 'OnImConnectorMessageAdd',
            'data' => [
                'id' => 'm-1',
                'ID' => 'm-2',
            ],
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
            ],
        ];

        $payloadWithSingleLowercaseKey = [
            'event' => 'OnImConnectorMessageAdd',
            'data' => [
                'id' => 'm-2',
            ],
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
            ],
        ];

        $firstResponse = $this->postJson('/callbacks/bitrix24/openlines', $payloadWithConflictingCaseKeys);
        $secondResponse = $this->postJson('/callbacks/bitrix24/openlines', $payloadWithSingleLowercaseKey);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame(2, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 2);
    }

    public function test_openlines_callback_with_case_insensitive_event_and_auth_keys_is_accepted(): void
    {
        Queue::fake();

        Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/callbacks/bitrix24/openlines', [
            'EVENT' => 'OnImConnectorMessageAdd',
            'AUTH' => [
                'DOMAIN' => 'crm.alexlesley.biz',
                'MEMBER_ID' => 'member-1',
                'APPLICATION_TOKEN' => 'app-token',
            ],
            'DATA' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'MESSAGES' => [
                    ['ID' => 'm-1'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'openlines')
            ->assertJsonPath('method', 'POST');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $connection = Bitrix24Connection::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::TYPE_OPENLINES, $event->callback_type);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame('OnImConnectorMessageAdd', $event->event_name);
        $this->assertSame($connection->id, $event->connection_id);

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_install_callback_with_case_insensitive_keys_is_accepted_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'EVENT' => 'ONAPPINSTALL',
            'AUTH' => [
                'DOMAIN' => 'crm.alexlesley.biz',
                'MEMBER_ID' => 'member-1',
                'APPLICATION_TOKEN' => 'app-token',
                'CLIENT_ENDPOINT' => 'https://crm.alexlesley.biz/rest/',
                'SERVER_ENDPOINT' => 'https://crm.alexlesley.biz/rest/',
                'SCOPE' => ['crm', 'tasks'],
                'ACCESS_TOKEN' => 'secret-access-token',
                'REFRESH_TOKEN' => 'secret-refresh-token',
                'EXPIRES' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install')
            ->assertJsonPath('method', 'POST');

        $connection = Bitrix24Connection::query()->firstOrFail();
        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame('crm.alexlesley.biz', $connection->portal_domain);
        $this->assertSame('member-1', $connection->member_id);
        $this->assertSame('app-token', $connection->application_token);
        $this->assertSame(Bitrix24WebhookEvent::TYPE_INSTALL, $event->callback_type);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertNull($event->failure_reason);

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_with_foreign_domain_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'foreign.example.test',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://foreign.example.test/rest/',
                'server_endpoint' => 'https://foreign.example.test/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_install_callback_with_access_denied_probe_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        Http::fake([
            'https://crm.alexlesley.biz/rest/app.info.json' => Http::response([
                'error' => 'ACCESS_DENIED',
                'error_description' => 'Application context is not available.',
            ], 401),
        ]);

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_install_callback_with_probe_reporting_not_installed_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        Http::fake([
            'https://crm.alexlesley.biz/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => 'local.app.code',
                    'INSTALLED' => false,
                ],
            ]),
        ]);

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_install_callback_with_unexpected_app_code_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        Http::fake([
            'https://crm.alexlesley.biz/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => 'foreign.app.code',
                    'INSTALLED' => true,
                ],
            ]),
        ]);

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_install_callback_without_expected_app_code_configuration_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();
        config()->set('bitrix24.application.code', null);

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_install_callback_with_untrusted_endpoint_host_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://foreign.example.test/rest/',
                'server_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_events_callback_with_invalid_application_token_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'expected-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/callbacks/bitrix24/events', [
            'event' => 'ONCRMCONTACTUPDATE',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'wrong-token',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'events')
            ->assertJsonPath('method', 'POST');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertNotNull($event->failure_reason);

        Queue::assertNotPushed(ProcessBitrix24WebhookEventJob::class);
    }

    public function test_events_callback_with_invalid_application_token_does_not_attach_error_to_foreign_connection(): void
    {
        Queue::fake();

        $foreignConnection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.foreign.biz',
            'member_id' => 'member-foreign',
            'application_token' => 'wrong-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $expectedConnection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'expected-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/callbacks/bitrix24/events', [
            'event' => 'ONCRMCONTACTUPDATE',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'wrong-token',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'events')
            ->assertJsonPath('method', 'POST');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame($expectedConnection->id, $event->connection_id);

        $expectedConnection->refresh();
        $foreignConnection->refresh();

        $this->assertNotNull($expectedConnection->last_error_message);
        $this->assertNull($foreignConnection->last_error_message);

        Queue::assertNotPushed(ProcessBitrix24WebhookEventJob::class);
    }

    public function test_openlines_callback_without_bitrix_auth_is_saved_as_ignored(): void
    {
        Queue::fake();

        $response = $this->postJson('/callbacks/bitrix24/openlines', [
            'payload' => 'noise',
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'openlines');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertSame('', $event->event_name);

        Queue::assertNotPushed(ProcessBitrix24WebhookEventJob::class);
    }

    public function test_events_callback_accepts_different_runtime_domain_when_member_and_application_token_match(): void
    {
        Queue::fake();

        $connection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/callbacks/bitrix24/events', [
            'event' => 'ONCRMCONTACTUPDATE',
            'auth' => [
                'domain' => '5crm-plus.ru',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
            ],
        ]);

        $response->assertOk();

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }
}
