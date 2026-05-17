<?php

namespace App\Jobs;

use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Message;
use App\Services\Bitrix24\ExportMessageToBitrix24OpenLinesAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExportMessageToBitrix24OpenLinesJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE_NAME = 'bitrix-live';

    public int $timeout = 60;

    public ?string $retryAfterSyncReason = null;

    public function __construct(
        public readonly int $messageId,
        public readonly bool $retryAfterSync = false,
        public readonly ?string $liveBatchUuid = null,
        ?string $retryAfterSyncReason = null,
    ) {
        $this->retryAfterSyncReason = $retryAfterSyncReason;
        $this->onQueue(self::queueName());
    }

    public function handle(
        ExportMessageToBitrix24OpenLinesAction $exportMessageToBitrix24OpenLinesAction,
        LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ): void {
        $message = Message::query()->find($this->messageId);

        if (! $message instanceof Message) {
            return;
        }

        $this->logJobStarted($message, $logBitrix24ApiCallAction);

        try {
            $exportMessageToBitrix24OpenLinesAction->handle(
                $message,
                $this->retryAfterSync,
                $this->liveBatchUuid,
                $this->retryAfterSyncReason,
            );
        } catch (Throwable $throwable) {
            Log::critical('Bitrix24 Open Lines live export job failed.', [
                'job' => self::class,
                'message_id' => $message->id,
                'dialog_id' => $message->dialog_id,
                'contact_id' => $message->contact_id,
                'retry_after_sync' => $this->retryAfterSync,
                'retry_after_sync_reason' => $this->retryAfterSyncReason,
                'live_batch_uuid' => $this->liveBatchUuid,
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
            ]);

            $logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_live_export_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'message_id' => $message->id,
                    'dialog_id' => $message->dialog_id,
                    'contact_id' => $message->contact_id,
                    'retry_after_sync' => $this->retryAfterSync,
                    'retry_after_sync_reason' => $this->retryAfterSyncReason,
                    'live_batch_uuid' => $this->liveBatchUuid,
                ],
                connection: null,
                errorMessage: $throwable->getMessage(),
                entityType: 'message',
                entityId: (string) $message->id,
            );
        }
    }

    public static function queueName(): string
    {
        $queueName = trim((string) config('bitrix24.openlines.live_export_queue', self::QUEUE_NAME));

        return $queueName === '' ? self::QUEUE_NAME : $queueName;
    }

    private function logJobStarted(Message $message, LogBitrix24ApiCallAction $logBitrix24ApiCallAction): void
    {
        $liveExport = Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->first();

        $logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'openlines_live_export_job_started',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'message_id' => $message->id,
                'dialog_id' => $message->dialog_id,
                'contact_id' => $message->contact_id,
                'channel_id' => $message->channel_id,
                'direction' => $message->direction,
                'message_kind' => $message->message_kind,
                'message_created_at' => $message->created_at?->toISOString(),
                'retry_after_sync' => $this->retryAfterSync,
                'retry_after_sync_reason' => $this->retryAfterSyncReason,
                'live_batch_uuid' => $this->liveBatchUuid,
                'live_export_id' => $liveExport?->id,
                'live_export_status' => $liveExport?->export_status,
                'live_export_created_at' => $liveExport?->created_at?->toISOString(),
                'live_export_updated_at' => $liveExport?->updated_at?->toISOString(),
                'job_started_at' => now()->toISOString(),
                'queue_connection' => config('queue.default'),
                'queue_name' => self::queueName(),
            ],
            entityType: 'message',
            entityId: (string) $message->id,
            fingerprint: 'openlines-live-export-job-started:'.($this->liveBatchUuid ?: 'message-'.$message->id),
        );
    }
}
