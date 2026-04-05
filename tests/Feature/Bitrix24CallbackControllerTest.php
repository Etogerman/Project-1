<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrix24InstallCallbackJob;
use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Bitrix24CallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_callback_upserts_connection_stores_inbox_event_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/callbacks/bitrix24/install', [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'application_token' => 'test-token',
                'client_endpoint' => 'https://client-endpoint.example/rest/',
                'server_endpoint' => 'https://server-endpoint.example/rest/',
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

        Queue::assertPushed(ProcessBitrix24InstallCallbackJob::class, 1);
    }

    public function test_events_callback_with_valid_auth_creates_pending_inbox_event_and_dispatches_job(): void
    {
        Queue::fake();

        $connection = Bitrix24Connection::query()->create([
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

        Bitrix24Connection::query()->create([
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

        Bitrix24Connection::query()->create([
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

    public function test_events_callback_with_invalid_application_token_is_saved_as_failed_and_not_dispatched(): void
    {
        Queue::fake();

        Bitrix24Connection::query()->create([
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

        $connection = Bitrix24Connection::query()->create([
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
