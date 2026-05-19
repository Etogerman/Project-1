<?php

namespace Tests\Feature;

use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\ExportMessageToBitrix24OpenLinesAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class Bitrix24OpenLinesExportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_export_job_uses_bitrix_live_queue(): void
    {
        config()->set('bitrix24.openlines.live_export_queue', 'bitrix-live');

        $this->assertSame('bitrix-live', ExportMessageToBitrix24OpenLinesJob::queueName());
        $this->assertSame('bitrix-live', (new ExportMessageToBitrix24OpenLinesJob(messageId: 1))->queue);
    }

    public function test_job_returns_quietly_when_message_is_missing(): void
    {
        Log::spy();

        $action = Mockery::mock(ExportMessageToBitrix24OpenLinesAction::class);
        $action->shouldNotReceive('handle');

        $job = new ExportMessageToBitrix24OpenLinesJob(messageId: 999_999, retryAfterSync: true);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        $this->assertDatabaseCount('bitrix24_sync_logs', 0);
        Log::shouldNotHaveReceived('critical');
    }

    public function test_job_calls_live_export_action_with_message_retry_flag_and_live_batch_uuid(): void
    {
        $message = $this->makeMessage();
        $liveBatchUuid = (string) fake()->uuid();

        $action = Mockery::mock(ExportMessageToBitrix24OpenLinesAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Message $exportedMessage, bool $retryAfterSync, ?string $passedLiveBatchUuid): bool => $exportedMessage->is($message)
                && $retryAfterSync === true
                && $passedLiveBatchUuid === $liveBatchUuid);

        $job = new ExportMessageToBitrix24OpenLinesJob(messageId: $message->id, retryAfterSync: true, liveBatchUuid: $liveBatchUuid);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_live_export_job_started',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'entity_type' => 'message',
            'entity_id' => (string) $message->id,
            'fingerprint' => 'openlines-live-export-job-started:'.$liveBatchUuid,
        ]);

        $syncLog = Bitrix24SyncLog::query()
            ->where('operation', 'openlines_live_export_job_started')
            ->firstOrFail();

        $this->assertSame($message->id, $syncLog->request_payload['message_id'] ?? null);
        $this->assertSame($message->dialog_id, $syncLog->request_payload['dialog_id'] ?? null);
        $this->assertSame($message->contact_id, $syncLog->request_payload['contact_id'] ?? null);
        $this->assertTrue($syncLog->request_payload['retry_after_sync'] ?? false);
        $this->assertSame($liveBatchUuid, $syncLog->request_payload['live_batch_uuid'] ?? null);
        $this->assertSame(config('queue.default'), $syncLog->request_payload['queue_connection'] ?? null);
        $this->assertSame(ExportMessageToBitrix24OpenLinesJob::queueName(), $syncLog->request_payload['queue_name'] ?? null);
    }

    public function test_job_logs_critical_and_persists_domain_failure_when_export_throws(): void
    {
        Log::spy();

        $message = $this->makeMessage();
        $liveBatchUuid = (string) fake()->uuid();
        $action = Mockery::mock(ExportMessageToBitrix24OpenLinesAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Message $exportedMessage, bool $retryAfterSync, ?string $passedLiveBatchUuid): bool => $exportedMessage->is($message)
                && $retryAfterSync === true
                && $passedLiveBatchUuid === $liveBatchUuid)
            ->andThrow(new \RuntimeException('Bitrix export failed.'));

        $job = new ExportMessageToBitrix24OpenLinesJob(messageId: $message->id, retryAfterSync: true, liveBatchUuid: $liveBatchUuid);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $messageText, array $context) use ($message, $liveBatchUuid): bool {
                return $messageText === 'Bitrix24 Open Lines live export job failed.'
                    && $context['job'] === ExportMessageToBitrix24OpenLinesJob::class
                    && $context['message_id'] === $message->id
                    && $context['dialog_id'] === $message->dialog_id
                    && $context['contact_id'] === $message->contact_id
                    && $context['retry_after_sync'] === true
                    && $context['live_batch_uuid'] === $liveBatchUuid
                    && $context['exception_class'] === \RuntimeException::class
                    && $context['exception_message'] === 'Bitrix export failed.';
            });

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_live_export_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'message',
            'entity_id' => (string) $message->id,
            'error_message' => 'Bitrix export failed.',
        ]);

        $syncLog = Bitrix24SyncLog::query()->latest('id')->firstOrFail();

        $this->assertSame($message->id, $syncLog->request_payload['message_id'] ?? null);
        $this->assertSame($message->dialog_id, $syncLog->request_payload['dialog_id'] ?? null);
        $this->assertSame($message->contact_id, $syncLog->request_payload['contact_id'] ?? null);
        $this->assertTrue($syncLog->request_payload['retry_after_sync'] ?? false);
        $this->assertSame($liveBatchUuid, $syncLog->request_payload['live_batch_uuid'] ?? null);
    }

    private function makeMessage(): Message
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
        ]);

        return Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Тестовая выгрузка в открытую линию',
        ]);
    }
}
