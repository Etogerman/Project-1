<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrix24InstallCallbackJob;
use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class Bitrix24CallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    private Bitrix24Profile $defaultProfile;

    /**
     * @var array<string, mixed>
     */
    private array $appInfoProbeResponse = [];

    private int $appInfoProbeStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.portal_domain', 'crm.alexlesley.biz');
        config()->set('bitrix24.application.code', 'local.app.code');
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');
        config()->set('bitrix24.install_validation.allow_uninstalled_app_probe', false);

        $this->defaultProfile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'http://localhost',
        ]);

        Http::preventStrayRequests();
        $this->fakeBitrixAppInfoResponse([
            'result' => [
                'CODE' => 'local.app.code',
                'INSTALLED' => true,
                'NAME' => 'Герман-4',
            ],
        ]);
        Http::fake([
            'https://crm.alexlesley.biz/rest/app.info.json' => fn () => Http::response(
                $this->appInfoProbeResponse,
                $this->appInfoProbeStatus,
            ),
        ]);
    }

    public function test_install_callback_upserts_connection_stores_inbox_event_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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
                'client_secret' => 'secret-client-secret',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('received', true)
            ->assertJsonPath('callback_type', 'install')
            ->assertJsonPath('method', 'POST');

        $connection = Bitrix24Connection::query()->firstOrFail();
        $expectedTokenHash = hash('sha256', 'test-token');

        $this->assertSame('crm.alexlesley.biz', $connection->portal_domain);
        $this->assertSame('member-1', $connection->member_id);
        $this->assertSame('Герман-4', $connection->application_name);
        $this->assertNull($connection->application_token);
        $this->assertSame($expectedTokenHash, $connection->application_token_hash);
        $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
        $this->assertSame('secret-access-token', $connection->access_token_encrypted);
        $this->assertSame('secret-refresh-token', $connection->refresh_token_encrypted);
        $this->assertIsArray($connection->install_payload);
        $this->assertArrayNotHasKey('application_token', $connection->install_payload['auth']);
        $this->assertSame($expectedTokenHash, $connection->install_payload['auth']['application_token_hash']);
        $this->assertArrayNotHasKey('access_token', $connection->install_payload['auth']);
        $this->assertArrayNotHasKey('refresh_token', $connection->install_payload['auth']);

        $event = Bitrix24WebhookEvent::query()->firstOrFail();
        $syncLog = Bitrix24SyncLog::query()->latest('id')->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::TYPE_INSTALL, $event->callback_type);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertArrayNotHasKey('application_token', $event->payload['auth']);
        $this->assertSame($expectedTokenHash, $event->payload['auth']['application_token_hash']);
        $this->assertArrayNotHasKey('access_token', $event->payload['auth']);
        $this->assertArrayNotHasKey('refresh_token', $event->payload['auth']);
        $this->assertSame('install_callback_stored', $syncLog->operation);
        $this->assertSame(Bitrix24SyncLog::STATUS_SUCCESS, $syncLog->status);
        $this->assertArrayNotHasKey('application_token', $syncLog->request_payload['auth']);
        $this->assertSame($expectedTokenHash, $syncLog->request_payload['auth']['application_token_hash']);
        $this->assertArrayNotHasKey('access_token', $syncLog->request_payload['auth']);
        $this->assertArrayNotHasKey('refresh_token', $syncLog->request_payload['auth']);
        $this->assertArrayNotHasKey('client_secret', $syncLog->request_payload['auth']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://crm.alexlesley.biz/rest/app.info.json'
                && ($request['auth'] ?? null) === 'secret-access-token';
        });

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_accepts_oauth_style_server_endpoint_when_probe_succeeds(): void
    {
        Queue::fake();

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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
        $expectedTokenHash = hash('sha256', 'test-token-2');

        $this->assertSame('member-2', $connection->member_id);
        $this->assertNull($connection->application_token);
        $this->assertSame($expectedTokenHash, $connection->application_token_hash);
        $this->assertSame('https://oauth.example', $connection->server_endpoint);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_without_oauth_server_configuration_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();
        config()->set('bitrix24.oauth.server_url', null);

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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

        $connection = $this->createActiveConnection('member-1', 'app-token');

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/events', [
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
                'access_token' => 'runtime-secret-access-token',
                'refresh_token' => 'runtime-secret-refresh-token',
                'client_secret' => 'runtime-secret-client-secret',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'events')
            ->assertJsonPath('method', 'POST');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();
        $syncLog = Bitrix24SyncLog::query()->latest('id')->firstOrFail();
        $expectedTokenHash = hash('sha256', 'app-token');

        $this->assertSame(Bitrix24WebhookEvent::TYPE_EVENTS, $event->callback_type);
        $this->assertSame('ONCRMCONTACTUPDATE', $event->event_name);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertSame('events_callback_stored', $syncLog->operation);
        $this->assertSame(Bitrix24SyncLog::STATUS_SUCCESS, $syncLog->status);
        $this->assertArrayNotHasKey('application_token', $syncLog->request_payload['auth']);
        $this->assertSame($expectedTokenHash, $syncLog->request_payload['auth']['application_token_hash']);
        $this->assertArrayNotHasKey('access_token', $syncLog->request_payload['auth']);
        $this->assertArrayNotHasKey('refresh_token', $syncLog->request_payload['auth']);
        $this->assertArrayNotHasKey('client_secret', $syncLog->request_payload['auth']);

        $connection->refresh();

        $this->assertNotNull($connection->last_events_callback_at);

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_openlines_callback_duplicate_is_deduped_and_job_is_not_redispatched(): void
    {
        Queue::fake();

        $this->createActiveConnection('member-1', 'app-token');

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

        $firstResponse = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', $payload);
        $secondResponse = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', $payload);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_openlines_callback_payload_key_case_is_deduped_by_fingerprint(): void
    {
        Queue::fake();

        $this->createActiveConnection('member-1', 'app-token');

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

        $firstResponse = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', $payloadWithLowercaseData);
        $secondResponse = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', $payloadWithUppercaseData);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_openlines_callback_payload_with_conflicting_case_keys_is_not_deduped_against_single_key_payload(): void
    {
        Queue::fake();

        $this->createActiveConnection('member-1', 'app-token');

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

        $firstResponse = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', $payloadWithConflictingCaseKeys);
        $secondResponse = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', $payloadWithSingleLowercaseKey);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame(2, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 2);
    }

    public function test_openlines_callback_with_case_insensitive_event_and_auth_keys_is_accepted(): void
    {
        Queue::fake();

        $this->createActiveConnection('member-1', 'app-token');

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', [
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

    public function test_openlines_callback_accepts_configured_runtime_token_hash(): void
    {
        Queue::fake();

        config()->set('bitrix24.openlines.runtime_application_token_hashes', [
            hash('sha256', 'box-runtime-token'),
        ]);

        $connection = $this->createActiveConnection('member-1', 'install-token');

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', [
            'event' => 'OnImConnectorMessageAdd',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'box-runtime-token',
            ],
            'data' => [
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

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status, (string) $event->failure_reason);
        $this->assertSame($connection->id, $event->connection_id);

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_install_callback_with_case_insensitive_keys_is_accepted_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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
        $expectedTokenHash = hash('sha256', 'app-token');

        $this->assertSame('crm.alexlesley.biz', $connection->portal_domain);
        $this->assertSame('member-1', $connection->member_id);
        $this->assertNull($connection->application_token);
        $this->assertSame($expectedTokenHash, $connection->application_token_hash);
        $this->assertSame(Bitrix24WebhookEvent::TYPE_INSTALL, $event->callback_type);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertNull($event->failure_reason);

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_accepts_flat_bitrix24_local_app_payload(): void
    {
        Queue::fake();

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install?APP_SID=query-app-token', [
            'DOMAIN' => 'crm.alexlesley.biz',
            'status' => 'L',
            'APP_SID' => 'flat-app-token',
            'AUTH_ID' => 'flat-access-token',
            'PROTOCOL' => '1',
            'PLACEMENT' => 'DEFAULT',
            'member_id' => 'member-flat',
            'REFRESH_ID' => 'flat-refresh-token',
            'AUTH_EXPIRES' => '3600',
            'SERVER_ENDPOINT' => 'https://oauth.bitrix24.tech/rest/',
            'APPLICATION_SCOPE' => 'crm,task,imopenlines,imbot,im,tasks,imconnector',
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install')
            ->assertJsonPath('method', 'POST');

        $connection = Bitrix24Connection::query()->firstOrFail();
        $event = Bitrix24WebhookEvent::query()->firstOrFail();
        $expectedTokenHash = hash('sha256', 'flat-app-token');
        $expectedQueryTokenHash = hash('sha256', 'query-app-token');

        $this->assertSame('crm.alexlesley.biz', $connection->portal_domain);
        $this->assertSame('member-flat', $connection->member_id);
        $this->assertSame('https://crm.alexlesley.biz/rest/', $connection->client_endpoint);
        $this->assertSame('flat-access-token', $connection->access_token_encrypted);
        $this->assertSame('flat-refresh-token', $connection->refresh_token_encrypted);
        $this->assertSame(['crm', 'task', 'imopenlines', 'imbot', 'im', 'tasks', 'imconnector'], $connection->scope);
        $this->assertSame($expectedTokenHash, $connection->application_token_hash);
        $this->assertTrue($connection->access_token_expires_at->isFuture());
        $this->assertArrayNotHasKey('AUTH_ID', $connection->install_payload);
        $this->assertArrayNotHasKey('REFRESH_ID', $connection->install_payload);
        $this->assertSame($expectedTokenHash, $connection->install_payload['application_token_hash']);
        $this->assertArrayNotHasKey('APP_SID', $event->query);
        $this->assertSame($expectedQueryTokenHash, $event->query['application_token_hash']);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertNull($event->failure_reason);

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_accepts_nested_auth_scope_string(): void
    {
        Queue::fake();

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-auth-scope',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'scope' => 'crm,task,tasks,im,imopenlines,imconnector,imbot',
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install')
            ->assertJsonPath('method', 'POST');

        $connection = Bitrix24Connection::query()->firstOrFail();

        $this->assertSame([
            'crm',
            'task',
            'tasks',
            'im',
            'imopenlines',
            'imconnector',
            'imbot',
        ], $connection->scope);

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_syncs_stale_configured_profile_callback_base_url(): void
    {
        Queue::fake();

        config()->set('bitrix24.callbacks.install_url', 'https://fresh-ngrok.example.test/callbacks/bitrix24/install');
        config()->set('bitrix24.callbacks.events_url', 'https://fresh-ngrok.example.test/callbacks/bitrix24/events');
        config()->set('bitrix24.callbacks.openlines_url', 'https://fresh-ngrok.example.test/callbacks/bitrix24/openlines');
        config()->set('bitrix24.application.client_id', 'stale-config-client-id');
        config()->set('bitrix24.application.code', 'stale.config.app.code');

        $this->defaultProfile->forceFill([
            'client_id' => 'existing-client-id',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://stale-ngrok.example.test',
        ])->save();

        $response = $this->postJson('https://fresh-ngrok.example.test/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
                'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
                'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
                'scope' => ['crm', 'tasks'],
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires' => (string) now()->addHour()->timestamp,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $connection = Bitrix24Connection::query()->firstOrFail();
        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame('https://fresh-ngrok.example.test', $this->defaultProfile->fresh()->callback_base_url);
        $this->assertSame('existing-client-id', $this->defaultProfile->fresh()->client_id);
        $this->assertSame('local.app.code', $this->defaultProfile->fresh()->application_code);
        $this->assertSame('existing-client-id', $connection->client_id);
        $this->assertSame($this->defaultProfile->id, $connection->profile_id);
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_with_foreign_domain_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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

        $this->fakeBitrixAppInfoResponse([
            'error' => 'ACCESS_DENIED',
            'error_description' => 'Application context is not available.',
        ], 401);

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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

    public function test_install_callback_with_probe_reporting_not_installed_is_saved_as_failed_by_default(): void
    {
        Queue::fake();

        $this->fakeBitrixAppInfoResponse([
            'result' => [
                'CODE' => 'local.app.code',
                'INSTALLED' => false,
            ],
        ]);

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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
        $this->assertSame('Bitrix24 install probe reported application as not installed.', $event->failure_reason);
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_install_callback_with_probe_reporting_not_installed_can_be_allowed_for_local_install_flow(): void
    {
        Queue::fake();
        config()->set('bitrix24.install_validation.allow_uninstalled_app_probe', true);

        $this->fakeBitrixAppInfoResponse([
            'result' => [
                'CODE' => 'local.app.code',
                'INSTALLED' => false,
            ],
        ]);

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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
        $connection = Bitrix24Connection::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);
        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_with_unexpected_app_code_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        $this->fakeBitrixAppInfoResponse([
            'result' => [
                'CODE' => 'foreign.app.code',
                'INSTALLED' => true,
            ],
        ]);

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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

    public function test_install_callback_without_profile_application_code_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();
        $this->defaultProfile->forceFill([
            'application_code' => null,
        ])->save();

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/install', [
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

    public function test_install_callback_without_matching_callback_base_url_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        $response = $this->postJson('http://unknown-callback.example.test/callbacks/bitrix24/install', [
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

    public function test_install_callback_without_matching_callback_base_url_does_not_attach_error_to_existing_profile_connection(): void
    {
        Queue::fake();

        $connection = $this->createActiveConnection('member-1', 'app-token');

        $response = $this->postJson('http://unknown-callback.example.test/callbacks/bitrix24/install', [
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

        $connection->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertNull($event->connection_id);
        $this->assertNull($connection->last_error_message);

        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    public function test_events_callback_with_invalid_application_token_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        $this->createActiveConnection('member-1', 'expected-token');

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/events', [
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

        $foreignConnection = $this->createActiveConnection('member-foreign', 'wrong-token', 'crm.foreign.biz');
        $expectedConnection = $this->createActiveConnection('member-1', 'expected-token');

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/events', [
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

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/openlines', [
            'payload' => 'noise',
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'openlines');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertSame('', $event->event_name);

        Queue::assertNotPushed(ProcessBitrix24WebhookEventJob::class);
    }

    public function test_openlines_callback_for_crm_only_profile_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        $crmOnlyProfile = $this->createProfile(
            profileKey: 'dev-crm-only',
            profileType: Bitrix24Profile::TYPE_CRM_ONLY,
            callbackBaseUrl: 'http://crm-only.example.test',
        );

        Bitrix24Connection::query()->forceCreate([
            'profile_id' => $crmOnlyProfile->id,
            'portal_domain' => $crmOnlyProfile->portal_domain,
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('http://crm-only.example.test/callbacks/bitrix24/openlines', [
            'event' => 'OnImConnectorMessageAdd',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
            ],
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'MESSAGES' => [
                    ['id' => 'm-1'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('callback_type', 'openlines');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);

        Queue::assertNotPushed(ProcessBitrix24WebhookEventJob::class);
    }

    public function test_events_callback_accepts_different_runtime_domain_when_member_and_application_token_match(): void
    {
        Queue::fake();

        $connection = $this->createActiveConnection('member-1', 'app-token');

        $response = $this->postLocalBitrixCallback('/callbacks/bitrix24/events', [
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

    public function test_events_callback_same_payload_is_not_deduped_across_profiles(): void
    {
        Queue::fake();

        $firstConnection = $this->createActiveConnection('member-1', 'app-token');
        $secondProfile = $this->createProfile(
            profileKey: 'dev-second',
            profileType: Bitrix24Profile::TYPE_FULL_LIVE,
            callbackBaseUrl: 'http://second.example.test',
        );

        $secondConnection = Bitrix24Connection::query()->forceCreate([
            'profile_id' => $secondProfile->id,
            'portal_domain' => $secondProfile->portal_domain,
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $payload = [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => [
                'FIELDS' => [
                    'ID' => 123,
                ],
            ],
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'app-token',
            ],
        ];

        $this->postLocalBitrixCallback('/callbacks/bitrix24/events', $payload)->assertOk();
        $this->postJson('http://second.example.test/callbacks/bitrix24/events', $payload)->assertOk();

        $events = Bitrix24WebhookEvent::query()->orderBy('id')->get();

        $this->assertCount(2, $events);
        $this->assertSame([$firstConnection->id, $secondConnection->id], $events->pluck('connection_id')->all());
        $this->assertSame(
            ['http://localhost', 'http://second.example.test'],
            $events->pluck('callback_base_url')->all(),
        );

        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 2);
    }

    private function createActiveConnection(
        string $memberId,
        string $applicationToken,
        string $portalDomain = 'crm.alexlesley.biz',
    ): Bitrix24Connection {
        $profile = $this->defaultProfile;

        if ($portalDomain !== $this->defaultProfile->portal_domain) {
            $profile = $this->createProfile(
                profileKey: 'dev-'.str_replace('.', '-', $portalDomain),
                profileType: Bitrix24Profile::TYPE_FULL_LIVE,
                callbackBaseUrl: 'http://'.$portalDomain,
                portalDomain: $portalDomain,
            );
        }

        return Bitrix24Connection::query()->forceCreate([
            'profile_id' => $profile->id,
            'portal_domain' => $portalDomain,
            'member_id' => $memberId,
            'application_token' => $applicationToken,
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);
    }

    private function createProfile(
        string $profileKey,
        string $profileType,
        string $callbackBaseUrl,
        string $portalDomain = 'crm.alexlesley.biz',
    ): Bitrix24Profile {
        return Bitrix24Profile::query()->create([
            'portal_domain' => $portalDomain,
            'profile_key' => $profileKey,
            'profile_type' => $profileType,
            'display_name' => $profileKey,
            'client_id' => 'client-id-'.$profileKey,
            'application_code' => 'local.app.code',
            'callback_base_url' => $callbackBaseUrl,
        ]);
    }

    private function postLocalBitrixCallback(string $path, array $payload): TestResponse
    {
        return $this->postJson('http://localhost'.$path, $payload);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fakeBitrixAppInfoResponse(array $response, int $status = 200): void
    {
        $this->appInfoProbeResponse = $response;
        $this->appInfoProbeStatus = $status;
    }
}
