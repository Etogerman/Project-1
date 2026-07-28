<?php

namespace Tests\Feature;

use App\Jobs\RefreshBitrix24TokenJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Services\Bitrix24\Bitrix24ApiClient;
use App\Services\Bitrix24\Bitrix24AuthRefreshException;
use App\Services\Bitrix24\Bitrix24ConnectionStateException;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use App\Services\Bitrix24\NoActiveBitrix24ConnectionException;
use App\Services\Bitrix24\RefreshBitrix24AccessTokenAction;
use App\Services\Bitrix24\ResolveCurrentBitrix24ConnectionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24ApiClientTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.http.timeout_seconds', 15);
        config()->set('bitrix24.http.connect_timeout_seconds', 5);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
        config()->set('bitrix24.callbacks.install_url', 'https://project.example.com/callbacks/bitrix24/install');
        config()->set('bitrix24.callbacks.events_url', 'https://project.example.com/callbacks/bitrix24/events');
        config()->set('bitrix24.callbacks.openlines_url', 'https://project.example.com/callbacks/bitrix24/openlines');
    }

    public function test_api_client_performs_successful_low_level_rest_call_and_logs_sanitized_payload(): void
    {
        $connection = $this->makeActiveConnection([
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'access_token_encrypted' => 'access-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/profile.json' => Http::response([
                'result' => ['ID' => 42],
            ]),
        ]);

        $response = app(Bitrix24ApiClient::class)->call('profile', ['scope' => 'full'], $connection);

        $this->assertTrue($response->successful);
        $this->assertSame(200, $response->httpStatus);
        $this->assertSame(['ID' => 42], $response->result);
        $this->assertSame('POST', $response->requestMethod);
        $this->assertSame('profile', $response->restMethod);
        $this->assertFalse($response->attemptedRefresh);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/profile.json'
                && $request['scope'] === 'full'
                && $request['auth'] === 'access-token';
        });

        $log = Bitrix24SyncLog::query()->latest('id')->firstOrFail();

        $this->assertSame(Bitrix24SyncLog::DIRECTION_OUTBOUND, $log->direction);
        $this->assertSame('rest_call', $log->operation);
        $this->assertSame(Bitrix24SyncLog::STATUS_SUCCESS, $log->status);
        $this->assertSame('profile', $log->entity_id);
        $this->assertArrayHasKey('params', $log->request_payload);
        $this->assertArrayNotHasKey('auth', $log->request_payload['params']);
    }

    public function test_api_client_throws_when_no_active_connection_exists(): void
    {
        $this->expectException(NoActiveBitrix24ConnectionException::class);

        app(Bitrix24ApiClient::class)->call('profile');
    }

    public function test_api_client_throws_when_multiple_active_connections_exist(): void
    {
        $this->makeActiveConnection();
        $this->makeActiveConnection([
            'portal_domain' => 'second.example',
            'member_id' => 'member-2',
            'application_token' => 'app-token-2',
        ]);

        $this->expectException(Bitrix24ConnectionStateException::class);

        app(Bitrix24ApiClient::class)->call('profile');
    }

    public function test_expired_token_triggers_refresh_before_rest_call(): void
    {
        config()->set('bitrix24.application.client_id', 'stale-global-client');
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'client_id' => 'connection-client',
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'access_token_encrypted' => 'expired-access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => function ($request) {
                $this->assertSame('connection-client', $request['client_id']);

                return Http::response([
                    'access_token' => 'new-access-token',
                    'refresh_token' => 'new-refresh-token',
                    'expires_in' => 3600,
                ]);
            },
            'https://client-endpoint.example/rest/profile.json' => Http::response([
                'result' => ['ID' => 7],
            ]),
        ]);

        $response = app(Bitrix24ApiClient::class)->call('profile', ['scope' => 'full'], $connection);

        $this->assertTrue($response->successful);
        $this->assertTrue($response->attemptedRefresh);

        $connection->refresh();

        $this->assertSame('new-access-token', $connection->access_token_encrypted);
        $this->assertSame('new-refresh-token', $connection->refresh_token_encrypted);
        $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
        $this->assertNotNull($connection->last_refreshed_at);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://client-endpoint.example/rest/profile.json'
                && $request['auth'] === 'new-access-token';
        });

        $this->assertSame(2, Bitrix24SyncLog::query()->count());
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'token_refresh',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'rest_call_after_refresh',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
        ]);
    }

    public function test_rest_preflight_runs_after_token_refresh_and_can_block_the_mutation(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');
        $connection = $this->makeActiveConnection([
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'access_token_encrypted' => 'expired-access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);
        $preflightCalls = 0;

        Http::fake([
            'https://oauth.example/oauth/token/' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
            ]),
            'https://client-endpoint.example/rest/profile.json' => Http::response([
                'result' => ['ID' => 7],
            ]),
        ]);

        try {
            app(Bitrix24ApiClient::class)->call(
                'profile',
                connection: $connection,
                beforeRestAttempt: function () use ($connection, &$preflightCalls): void {
                    $preflightCalls++;
                    $this->assertSame(
                        'fresh-access-token',
                        $connection->fresh()->access_token_encrypted,
                    );

                    throw new Bitrix24OpenLinesRouteRegistryException(
                        'route_registry_line_lease_expiring',
                        'Lease expired during token refresh.',
                    );
                },
            );
            $this->fail('REST preflight должен остановить мутацию после долгого token refresh.');
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->assertSame('route_registry_line_lease_expiring', $exception->errorCode);
        }

        $this->assertSame(1, $preflightCalls);
        Http::assertSentCount(1);
        Http::assertNotSent(
            fn ($request): bool => $request->url() === 'https://client-endpoint.example/rest/profile.json',
        );
    }

    public function test_auth_failure_response_triggers_refresh_and_retries_rest_call_once(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'access_token_encrypted' => 'stale-access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
            ]),
            'https://client-endpoint.example/rest/profile.json' => Http::sequence()
                ->push([
                    'error' => 'expired_token',
                    'error_description' => 'Expired token',
                ], 401)
                ->push([
                    'result' => ['ID' => 99],
                ], 200),
        ]);

        $response = app(Bitrix24ApiClient::class)->call('profile', ['scope' => 'full'], $connection);

        $this->assertTrue($response->successful);
        $this->assertTrue($response->attemptedRefresh);
        Http::assertSentCount(3);

        $connection->refresh();

        $this->assertSame('fresh-access-token', $connection->access_token_encrypted);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'rest_call',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'rest_call_after_refresh',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
        ]);
    }

    public function test_failed_refresh_marks_connection_invalid_and_raises_exception(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Refresh token expired',
            ], 400),
        ]);

        try {
            app(Bitrix24ApiClient::class)->call('profile', [], $connection);
            $this->fail('Expected refresh failure exception was not thrown.');
        } catch (Bitrix24AuthRefreshException) {
            $connection->refresh();

            $this->assertSame(Bitrix24Connection::STATUS_INVALID, $connection->status);
            $this->assertSame('Refresh token expired', $connection->last_error_message);
        }

        $log = Bitrix24SyncLog::query()
            ->where('operation', 'token_refresh_failed')
            ->latest('id')
            ->firstOrFail();

        $this->assertArrayNotHasKey('refresh_token', $log->request_payload);
        $this->assertArrayNotHasKey('client_secret', $log->request_payload);
    }

    public function test_transient_refresh_http_failure_keeps_connection_active(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => Http::response([
                'error' => 'temporarily_unavailable',
                'error_description' => 'Bitrix24 OAuth is temporarily unavailable',
            ], 500),
        ]);

        try {
            app(RefreshBitrix24AccessTokenAction::class)->handle($connection);
            $this->fail('Expected refresh failure exception was not thrown.');
        } catch (Bitrix24AuthRefreshException) {
            $connection->refresh();

            $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
            $this->assertSame('Bitrix24 OAuth is temporarily unavailable', $connection->last_error_message);
        }

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'token_refresh_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'http_status' => 500,
            'error_code' => 'temporarily_unavailable',
        ]);
    }

    public function test_transient_refresh_connection_exception_keeps_connection_active(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => fn () => throw new ConnectionException('Connection timed out.'),
        ]);

        try {
            app(RefreshBitrix24AccessTokenAction::class)->handle($connection);
            $this->fail('Expected refresh failure exception was not thrown.');
        } catch (Bitrix24AuthRefreshException) {
            $connection->refresh();

            $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
            $this->assertSame('Connection timed out.', $connection->last_error_message);
        }

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'token_refresh_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'error_message' => 'Connection timed out.',
        ]);
    }

    public function test_unknown_refresh_forbidden_response_keeps_connection_active(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => Http::response([
                'error_description' => 'Temporary edge denial',
            ], 403),
        ]);

        try {
            app(RefreshBitrix24AccessTokenAction::class)->handle($connection);
            $this->fail('Expected refresh failure exception was not thrown.');
        } catch (Bitrix24AuthRefreshException) {
            $connection->refresh();

            $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
            $this->assertSame('Temporary edge denial', $connection->last_error_message);
        }
    }

    public function test_temporarily_unavailable_refresh_bad_request_keeps_connection_active(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => Http::response([
                'error' => 'temporarily_unavailable',
                'error_description' => 'OAuth endpoint is temporarily unavailable',
            ], 400),
        ]);

        try {
            app(RefreshBitrix24AccessTokenAction::class)->handle($connection);
            $this->fail('Expected refresh failure exception was not thrown.');
        } catch (Bitrix24AuthRefreshException) {
            $connection->refresh();

            $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
            $this->assertSame('OAuth endpoint is temporarily unavailable', $connection->last_error_message);
        }
    }

    public function test_missing_install_critical_metadata_marks_connection_needs_reinstall(): void
    {
        config()->set('bitrix24.oauth.server_url', null);

        $connection = $this->makeActiveConnection([
            'client_endpoint' => null,
            'server_endpoint' => null,
            'refresh_token_encrypted' => null,
            'access_token_expires_at' => now()->subMinute(),
        ]);

        try {
            app(RefreshBitrix24AccessTokenAction::class)->handle($connection);
            $this->fail('Expected reinstall exception was not thrown.');
        } catch (Bitrix24AuthRefreshException) {
            $connection->refresh();

            $this->assertSame(Bitrix24Connection::STATUS_NEEDS_REINSTALL, $connection->status);
        }
    }

    public function test_refresh_does_not_fallback_to_connection_server_endpoint_when_oauth_server_url_is_missing(): void
    {
        config()->set('bitrix24.oauth.server_url', null);

        $connection = $this->makeActiveConnection([
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://attacker.example/rest/',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        try {
            app(RefreshBitrix24AccessTokenAction::class)->handle($connection);
            $this->fail('Expected refresh failure exception was not thrown.');
        } catch (Bitrix24AuthRefreshException) {
            $connection->refresh();

            $this->assertSame(Bitrix24Connection::STATUS_NEEDS_REINSTALL, $connection->status);
        }

        Http::assertNothingSent();
    }

    public function test_non_auth_4xx_response_does_not_trigger_refresh(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'access_token_encrypted' => 'access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/profile.json' => Http::response([
                'error' => 'WRONG_AUTH_TYPE',
                'error_description' => 'Application context required',
            ], 403),
        ]);

        $response = app(Bitrix24ApiClient::class)->call('profile', [], $connection);

        $this->assertFalse($response->successful);
        $this->assertFalse($response->attemptedRefresh);
        $this->assertSame('WRONG_AUTH_TYPE', $response->errorCode);
        Http::assertSentCount(1);
        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'operation' => 'token_refresh',
        ]);
    }

    public function test_server_error_retries_once_before_returning_success(): void
    {
        $connection = $this->makeActiveConnection([
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'access_token_encrypted' => 'access-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/profile.json' => Http::sequence()
                ->push(['error' => 'temporary'], 500)
                ->push(['result' => ['ID' => 101]], 200),
        ]);

        $preflightCalls = 0;
        $response = app(Bitrix24ApiClient::class)->call(
            'profile',
            [],
            $connection,
            beforeRestAttempt: function () use (&$preflightCalls): void {
                $preflightCalls++;
            },
        );

        $this->assertTrue($response->successful);
        $this->assertSame(2, $preflightCalls);
        Http::assertSentCount(2);
    }

    public function test_refresh_job_refreshes_specific_connection(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => Http::response([
                'access_token' => 'job-access-token',
                'refresh_token' => 'job-refresh-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $job = new RefreshBitrix24TokenJob($connection->id);
        $job->handle(
            app(ResolveCurrentBitrix24ConnectionAction::class),
            app(RefreshBitrix24AccessTokenAction::class),
        );

        $connection->refresh();

        $this->assertSame('job-access-token', $connection->access_token_encrypted);
        $this->assertSame('job-refresh-token', $connection->refresh_token_encrypted);
    }

    public function test_refresh_job_without_connection_id_uses_current_runtime_profile_connection(): void
    {
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $selectedConnection = $this->makeActiveConnection([
            'client_endpoint' => 'https://selected-client.example/rest/',
            'server_endpoint' => 'https://selected-server.example/rest/',
            'refresh_token_encrypted' => 'selected-refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);
        $otherProfile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'dev-alex',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Dev Alex',
            'client_id' => 'local.app',
            'application_code' => 'local.app.code.dev',
            'callback_base_url' => 'https://other.example.com',
        ]);
        $ignoredConnection = Bitrix24Connection::query()->forceCreate([
            'profile_id' => $otherProfile->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-2',
            'application_token' => 'app-token-2',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'client_endpoint' => 'https://ignored-client.example/rest/',
            'server_endpoint' => 'https://ignored-server.example/rest/',
            'access_token_encrypted' => 'ignored-access-token',
            'refresh_token_encrypted' => 'ignored-refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => function ($request) {
                $this->assertSame('selected-refresh-token', $request['refresh_token']);

                return Http::response([
                    'access_token' => 'selected-job-access-token',
                    'refresh_token' => 'selected-job-refresh-token',
                    'expires_in' => 3600,
                ], 200);
            },
        ]);

        $job = new RefreshBitrix24TokenJob;
        $job->handle(
            app(ResolveCurrentBitrix24ConnectionAction::class),
            app(RefreshBitrix24AccessTokenAction::class),
        );

        $selectedConnection->refresh();
        $ignoredConnection->refresh();

        $this->assertSame('selected-job-access-token', $selectedConnection->access_token_encrypted);
        $this->assertSame('selected-job-refresh-token', $selectedConnection->refresh_token_encrypted);
        $this->assertSame('ignored-access-token', $ignoredConnection->access_token_encrypted);
        $this->assertSame('ignored-refresh-token', $ignoredConnection->refresh_token_encrypted);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeActiveConnection(array $overrides = []): Bitrix24Connection
    {
        $portalDomain = (string) ($overrides['portal_domain'] ?? 'crm.alexlesley.biz');
        $profileKey = (string) ($overrides['profile_key'] ?? (
            $portalDomain === 'crm.alexlesley.biz'
                ? Bitrix24Profile::PROFILE_KEY_STAGING
                : 'profile-'.preg_replace('/[^a-z0-9]+/i', '-', $portalDomain)
        ));
        $callbackBaseUrl = (string) ($overrides['callback_base_url'] ?? (
            $profileKey === Bitrix24Profile::PROFILE_KEY_STAGING
                ? 'https://project.example.com'
                : 'https://'.$profileKey.'.example.com'
        ));

        return $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: $overrides,
            profileOverrides: [
                'portal_domain' => $portalDomain,
                'profile_key' => $profileKey,
                'display_name' => ucfirst(str_replace('-', ' ', $profileKey)),
                'application_code' => 'local.app.code'.($profileKey === Bitrix24Profile::PROFILE_KEY_STAGING ? '' : '.'.$profileKey),
                'callback_base_url' => $callbackBaseUrl,
            ],
            useForCurrentRuntime: $callbackBaseUrl === 'https://project.example.com',
        );
    }
}
