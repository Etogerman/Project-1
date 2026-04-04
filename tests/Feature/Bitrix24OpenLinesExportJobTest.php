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

    public function test_job_calls_live_export_action_with_message_and_retry_flag(): void
    {
        $message = $this->makeMessage();

        $action = Mockery::mock(ExportMessageToBitrix24OpenLinesAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Message $exportedMessage, bool $retryAfterSync): bool => $exportedMessage->is($message)
                && $retryAfterSync === true);

        $job = new ExportMessageToBitrix24OpenLinesJob(messageId: $message->id, retryAfterSync: true);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        $this->assertDatabaseCount('bitrix24_sync_logs', 0);
    }

    public function test_job_logs_critical_and_persists_domain_failure_when_export_throws(): void
    {
        Log::spy();

        $message = $this->makeMessage();
        $action = Mockery::mock(ExportMessageToBitrix24OpenLinesAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Message $exportedMessage, bool $retryAfterSync): bool => $exportedMessage->is($message)
                && $retryAfterSync === true)
            ->andThrow(new \RuntimeException('Bitrix export failed.'));

        $job = new ExportMessageToBitrix24OpenLinesJob(messageId: $message->id, retryAfterSync: true);
        $job->handle($action, app(LogBitrix24ApiCallAction::class));

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $messageText, array $context) use ($message): bool {
                return $messageText === 'Bitrix24 Open Lines live export job failed.'
                    && $context['job'] === ExportMessageToBitrix24OpenLinesJob::class
                    && $context['message_id'] === $message->id
                    && $context['dialog_id'] === $message->dialog_id
                    && $context['contact_id'] === $message->contact_id
                    && $context['retry_after_sync'] === true
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

        $this->assertSame([
            'message_id' => $message->id,
            'dialog_id' => $message->dialog_id,
            'contact_id' => $message->contact_id,
            'retry_after_sync' => true,
        ], $syncLog->request_payload);
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
