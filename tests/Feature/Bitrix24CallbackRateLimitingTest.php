<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Bitrix24CallbackRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_callback_below_limit_still_stores_event_and_dispatches_job(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.events.max_per_minute', 5);

        $connection = $this->createActiveConnection('member-1', 'app-token-1');

        $response = $this->postJson('/callbacks/bitrix24/events', $this->eventsPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            eventName: 'ONCRMCONTACTUPDATE',
            entityId: 101,
        ));

        $response->assertOk()
            ->assertJsonPath('callback_type', 'events');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::TYPE_EVENTS, $event->callback_type);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_events_callback_over_limit_returns_429_without_storing_new_event_or_dispatching_job(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.events.max_per_minute', 1);

        $this->createActiveConnection('member-1', 'app-token-1');

        $this->postJson('/callbacks/bitrix24/events', $this->eventsPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            eventName: 'ONCRMCONTACTUPDATE',
            entityId: 101,
        ))->assertOk();

        $response = $this->postJson('/callbacks/bitrix24/events', $this->eventsPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            eventName: 'ONCRMCONTACTUPDATE',
            entityId: 102,
        ));

        $response->assertStatus(429);

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_openlines_callback_below_limit_still_stores_event_and_dispatches_job(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.openlines.max_per_minute', 5);

        $connection = $this->createActiveConnection('member-1', 'app-token-1');

        $response = $this->postJson('/callbacks/bitrix24/openlines', $this->openlinesPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            messageId: 'openlines-101',
        ));

        $response->assertOk()
            ->assertJsonPath('callback_type', 'openlines');

        $event = Bitrix24WebhookEvent::query()->firstOrFail();

        $this->assertSame(Bitrix24WebhookEvent::TYPE_OPENLINES, $event->callback_type);
        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($connection->id, $event->connection_id);
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_openlines_callback_over_limit_returns_429_without_storing_new_event_or_dispatching_job(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.openlines.max_per_minute', 1);

        $this->createActiveConnection('member-1', 'app-token-1');

        $this->postJson('/callbacks/bitrix24/openlines', $this->openlinesPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            messageId: 'openlines-101',
        ))->assertOk();

        $response = $this->postJson('/callbacks/bitrix24/openlines', $this->openlinesPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            messageId: 'openlines-102',
        ));

        $response->assertStatus(429);

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 1);
    }

    public function test_events_callbacks_use_independent_buckets_for_different_auth_contexts(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.events.max_per_minute', 1);

        $this->createActiveConnection('member-1', 'app-token-1');
        $this->createActiveConnection('member-2', 'app-token-2');

        $this->postJson('/callbacks/bitrix24/events', $this->eventsPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            eventName: 'ONCRMCONTACTUPDATE',
            entityId: 101,
        ))->assertOk();

        $this->postJson('/callbacks/bitrix24/events', $this->eventsPayload(
            memberId: 'member-2',
            applicationToken: 'app-token-2',
            eventName: 'ONCRMCONTACTUPDATE',
            entityId: 201,
        ))->assertOk();

        $this->postJson('/callbacks/bitrix24/events', $this->eventsPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            eventName: 'ONCRMCONTACTUPDATE',
            entityId: 102,
        ))->assertStatus(429);

        $this->assertSame(2, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 2);
    }

    public function test_openlines_callbacks_use_independent_buckets_for_different_auth_contexts(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.openlines.max_per_minute', 1);

        $this->createActiveConnection('member-1', 'app-token-1');
        $this->createActiveConnection('member-2', 'app-token-2');

        $this->postJson('/callbacks/bitrix24/openlines', $this->openlinesPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            messageId: 'openlines-101',
        ))->assertOk();

        $this->postJson('/callbacks/bitrix24/openlines', $this->openlinesPayload(
            memberId: 'member-2',
            applicationToken: 'app-token-2',
            messageId: 'openlines-201',
        ))->assertOk();

        $this->postJson('/callbacks/bitrix24/openlines', $this->openlinesPayload(
            memberId: 'member-1',
            applicationToken: 'app-token-1',
            messageId: 'openlines-102',
        ))->assertStatus(429);

        $this->assertSame(2, Bitrix24WebhookEvent::query()->count());
        Queue::assertPushed(ProcessBitrix24WebhookEventJob::class, 2);
    }

    public function test_events_callbacks_without_auth_fallback_to_ip_bucket(): void
    {
        Queue::fake();
        config()->set('bitrix24.rate_limits.events.max_per_minute', 1);

        $this->postJson('/callbacks/bitrix24/events', [
            'event' => 'ONCRMCONTACTUPDATE',
            'payload' => 'noise-1',
        ])->assertOk();

        $response = $this->postJson('/callbacks/bitrix24/events', [
            'event' => 'ONCRMCONTACTUPDATE',
            'payload' => 'noise-2',
        ]);

        $response->assertStatus(429);

        $this->assertSame(1, Bitrix24WebhookEvent::query()->count());
        Queue::assertNotPushed(ProcessBitrix24WebhookEventJob::class);
    }

    private function createActiveConnection(string $memberId, string $applicationToken): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate([
            'portal_domain' => $memberId.'.example.test',
            'member_id' => $memberId,
            'application_token' => $applicationToken,
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventsPayload(
        string $memberId,
        string $applicationToken,
        string $eventName,
        int $entityId,
        string $domain = 'runtime.example.test',
    ): array {
        return [
            'event' => $eventName,
            'data' => [
                'FIELDS' => [
                    'ID' => $entityId,
                ],
            ],
            'auth' => [
                'domain' => $domain,
                'member_id' => $memberId,
                'application_token' => $applicationToken,
                'client_endpoint' => 'https://client-endpoint.example/rest/',
                'server_endpoint' => 'https://server-endpoint.example/rest/',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function openlinesPayload(
        string $memberId,
        string $applicationToken,
        string $messageId,
        string $domain = 'runtime.example.test',
    ): array {
        return [
            'event' => 'OnImConnectorMessageAdd',
            'data' => [
                'CONNECTOR' => 'abrikosoff_telegram',
                'MESSAGES' => [
                    ['id' => $messageId],
                ],
            ],
            'auth' => [
                'domain' => $domain,
                'member_id' => $memberId,
                'application_token' => $applicationToken,
            ],
        ];
    }
}
