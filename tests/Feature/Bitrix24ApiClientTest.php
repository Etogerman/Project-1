<?php

namespace Tests\Feature;

use App\Jobs\RefreshBitrix24TokenJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Services\Bitrix24\Bitrix24ApiClient;
use App\Services\Bitrix24\Bitrix24AuthRefreshException;
use App\Services\Bitrix24\Bitrix24ConnectionStateException;
use App\Services\Bitrix24\NoActiveBitrix24ConnectionException;
use App\Services\Bitrix24\RefreshBitrix24AccessTokenAction;
use App\Services\Bitrix24\ResolveActiveBitrix24ConnectionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Bitrix24ApiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.http.timeout_seconds', 15);
        config()->set('bitrix24.http.connect_timeout_seconds', 5);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
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
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        $connection = $this->makeActiveConnection([
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'access_token_encrypted' => 'expired-access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth.example/oauth/token/' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ]),
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

        $response = app(Bitrix24ApiClient::class)->call('profile', [], $connection);

        $this->assertTrue($response->successful);
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
            app(ResolveActiveBitrix24ConnectionAction::class),
            app(RefreshBitrix24AccessTokenAction::class),
        );

        $connection->refresh();

        $this->assertSame('job-access-token', $connection->access_token_encrypted);
        $this->assertSame('job-refresh-token', $connection->refresh_token_encrypted);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeActiveConnection(array $overrides = []): Bitrix24Connection
    {
        /** @var array<string, mixed> $attributes */
        $attributes = array_merge([
            'portal_domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'access_token_encrypted' => 'access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
        ], $overrides);

        return Bitrix24Connection::query()->forceCreate($attributes);
    }
}
