<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrix24InstallCallbackJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Bitrix24InstallCallbackRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.portal_domain', 'install.example.test');
        config()->set('bitrix24.application.code', 'local.app.code');
        config()->set('bitrix24.oauth.server_url', 'https://oauth.example');

        Http::preventStrayRequests();
        Http::fake([
            'https://install.example.test/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => 'local.app.code',
                    'INSTALLED' => true,
                ],
            ]),
        ]);
    }

    public function test_install_callback_below_limit_still_upserts_connection_stores_event_and_dispatches_job(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.install.max_per_minute', 5);

        $response = $this->postJson('/callbacks/bitrix24/install', $this->installPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expires: (string) now()->addHour()->timestamp,
            scope: ['crm', 'tasks'],
        ));

        $response->assertOk()
            ->assertJsonPath('callback_type', 'install');

        $connection = Bitrix24Connection::query()->firstOrFail();
        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame('member-1', $connection->member_id);
        $this->assertNull($connection->application_token);
        $this->assertSame(
            hash('sha256', 'app-token-1'),
            $connection->application_token_hash,
        );
        $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
        $this->assertSame(Bitrix24WebhookEvent::TYPE_INSTALL, $event->callback_type);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);
        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callback_over_limit_returns_429_without_creating_new_event_or_dispatching_job(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.install.max_per_minute', 1);

        $this->postJson('/callbacks/bitrix24/install', $this->installPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expires: (string) now()->addHour()->timestamp,
            scope: ['crm', 'tasks'],
        ))->assertOk();

        $response = $this->postJson('/callbacks/bitrix24/install', $this->installPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            accessToken: 'access-token-2',
            refreshToken: 'refresh-token-2',
            expires: (string) now()->addHours(2)->timestamp,
            scope: ['crm', 'task'],
        ));

        $response->assertStatus(429);

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_throttled_install_callback_does_not_mutate_existing_connection(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.install.max_per_minute', 1);

        $this->postJson('/callbacks/bitrix24/install', $this->installPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expires: (string) now()->addHour()->timestamp,
            scope: ['crm', 'tasks'],
        ))->assertOk();

        $connection = Bitrix24Connection::query()->firstOrFail();
        $snapshot = [
            'access_token_encrypted' => $connection->access_token_encrypted,
            'refresh_token_encrypted' => $connection->refresh_token_encrypted,
            'install_payload' => $connection->install_payload,
            'installed_at' => $connection->installed_at?->toIso8601String(),
            'last_install_callback_at' => $connection->last_install_callback_at?->toIso8601String(),
        ];

        $this->postJson('/callbacks/bitrix24/install', $this->installPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            accessToken: 'access-token-2',
            refreshToken: 'refresh-token-2',
            expires: (string) now()->addHours(2)->timestamp,
            scope: ['crm', 'task'],
        ))->assertStatus(429);

        $connection->refresh();

        $this->assertSame($snapshot['access_token_encrypted'], $connection->access_token_encrypted);
        $this->assertSame($snapshot['refresh_token_encrypted'], $connection->refresh_token_encrypted);
        $this->assertSame($snapshot['install_payload'], $connection->install_payload);
        $this->assertSame($snapshot['installed_at'], $connection->installed_at?->toIso8601String());
        $this->assertSame($snapshot['last_install_callback_at'], $connection->last_install_callback_at?->toIso8601String());
        $this->assertSame(1, Bitrix24Connection::query()->count());
        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_install_callbacks_use_independent_buckets_for_different_auth_contexts(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.install.max_per_minute', 1);

        $this->postJson('/callbacks/bitrix24/install', $this->installPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            accessToken: 'access-token-1',
            refreshToken: 'refresh-token-1',
            expires: (string) now()->addHour()->timestamp,
            scope: ['crm', 'tasks'],
        ))->assertOk();

        $this->postJson('/callbacks/bitrix24/install', $this->installPayload(
            memberId: 'member-2',
            applicationToken: 'app-token-2',
            accessToken: 'access-token-2',
            refreshToken: 'refresh-token-2',
            expires: (string) now()->addHours(2)->timestamp,
            scope: ['crm', 'task'],
        ))->assertOk();

        $this->postJson('/callbacks/bitrix24/install', $this->installPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            accessToken: 'access-token-3',
            refreshToken: 'refresh-token-3',
            expires: (string) now()->addHours(3)->timestamp,
            scope: ['crm'],
        ))->assertStatus(429);

        $this->assertSame(2, Bitrix24Connection::query()->count());
        $this->assertSame(2, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 2);
    }

    public function test_install_callbacks_without_auth_fallback_to_ip_bucket(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.install.max_per_minute', 1);

        $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'payload' => 'noise-1',
        ])->assertOk();

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'payload' => 'noise-2',
        ]);

        $response->assertStatus(429);

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());
        $this->assertSame(0, Bitrix24Connection::query()->count());
        Queue::assertNotPushed(ProcessBitrix24InstallCallbackJob::class);
    }

    /**
     * @param  list<string>  $scope
     * @return array<string, mixed>
     */
    private function installPayload(
        string $memberId,
        string $applicationToken,
        string $accessToken,
        string $refreshToken,
        string $expires,
        array $scope,
        string $domain = 'install.example.test',
    ): array {
        return [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => $domain,
                'member_id' => $memberId,
                'application_token' => $applicationToken,
                'client_endpoint' => 'https://'.$domain.'/rest/',
                'server_endpoint' => 'https://'.$domain.'/rest/',
                'scope' => $scope,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires' => $expires,
            ],
        ];
    }
}
