<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrix24WebhookEventJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\ProcessBitrix24OpenLinesWebhookAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class Bitrix24WebhookEventJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_returns_quietly_when_event_is_missing(): void
    {
        $action = Mockery::mock(ProcessBitrix24OpenLinesWebhookAction::class);
        $action->shouldNotReceive('handle');

        $job = new ProcessBitrix24WebhookEventJob(999_999);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        $this->assertDatabaseCount('bitrix24_sync_logs', 0);
    }

    public function test_job_returns_quietly_when_event_is_not_pending(): void
    {
        $event = $this->makeWebhookEvent([
            'processing_status' => Bitrix24WebhookEvent::STATUS_PROCESSED,
        ]);

        $action = Mockery::mock(ProcessBitrix24OpenLinesWebhookAction::class);
        $action->shouldNotReceive('handle');

        $job = new ProcessBitrix24WebhookEventJob($event->id);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertDatabaseCount('bitrix24_sync_logs', 0);
    }

    public function test_job_returns_quietly_when_callback_type_is_not_openlines(): void
    {
        $event = $this->makeWebhookEvent([
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
        ]);

        $action = Mockery::mock(ProcessBitrix24OpenLinesWebhookAction::class);
        $action->shouldNotReceive('handle');

        $job = new ProcessBitrix24WebhookEventJob($event->id);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertDatabaseCount('bitrix24_sync_logs', 0);
    }

    public function test_job_calls_openlines_action_for_pending_openlines_event(): void
    {
        $event = $this->makeWebhookEvent();

        $action = Mockery::mock(ProcessBitrix24OpenLinesWebhookAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Bitrix24WebhookEvent $handledEvent): bool => $handledEvent->is($event));

        $job = new ProcessBitrix24WebhookEventJob($event->id);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        $this->assertDatabaseCount('bitrix24_sync_logs', 0);
    }

    public function test_job_marks_event_as_failed_and_logs_when_openlines_action_throws(): void
    {
        $event = $this->makeWebhookEvent([
            'event_name' => 'OnSendMessageCustom',
            'attempts' => 2,
        ]);

        $action = Mockery::mock(ProcessBitrix24OpenLinesWebhookAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Bitrix24WebhookEvent $handledEvent): bool => $handledEvent->is($event))
            ->andThrow(new \RuntimeException('Webhook processing failed.'));

        $job = new ProcessBitrix24WebhookEventJob($event->id);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        $event->refresh();

        $this->assertSame(Bitrix24WebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertNotNull($event->failed_at);
        $this->assertSame('Webhook processing failed.', $event->failure_reason);
        $this->assertSame(3, $event->attempts);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'connection_id' => $event->connection_id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_event_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'error_message' => 'Webhook processing failed.',
        ]);

        $syncLog = Bitrix24SyncLog::query()->latest('id')->firstOrFail();

        $this->assertSame([
            'webhook_event_id' => $event->id,
            'event_name' => 'OnSendMessageCustom',
            'callback_type' => Bitrix24WebhookEvent::TYPE_OPENLINES,
        ], $syncLog->request_payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeWebhookEvent(array $overrides = []): Bitrix24WebhookEvent
    {
        $connection = Bitrix24Connection::query()->create([
            'portal_domain' => 'example.bitrix24.ru',
            'application_name' => 'Local App',
            'client_id' => 'local.app',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'installed_at' => now(),
        ]);

        return Bitrix24WebhookEvent::query()->create(array_merge([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_OPENLINES,
            'event_name' => 'OnSessionStart',
            'member_id' => 'member-1',
            'application_token' => 'app-token',
            'portal_domain' => 'example.bitrix24.ru',
            'payload_hash' => sha1((string) str()->uuid()),
            'payload' => ['event' => 'payload'],
            'headers' => ['x-test' => ['1']],
            'query' => ['auth' => ['domain' => 'example.bitrix24.ru']],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
            'processed_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'attempts' => 0,
        ], $overrides));
    }
}
