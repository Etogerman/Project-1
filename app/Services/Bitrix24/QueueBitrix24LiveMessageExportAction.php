<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24LiveMessageExportQueueResultData;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Support\Str;

class QueueBitrix24LiveMessageExportAction
{
    public const UNCLAIMED_PENDING_RECOVERY_SECONDS = 120;

    private const DELAYED_PENDING_RECOVERY_BUFFER_SECONDS = 5;

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly IsMessageReadyForBitrix24LiveExportAction $isMessageReadyForBitrix24LiveExportAction,
        private readonly LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ) {}

    public function handle(Message|int $message, bool $retryAfterSync = false): Bitrix24LiveMessageExportQueueResultData
    {
        $message = $message instanceof Message
            ? $message
            : Message::query()->with(['contact', 'dialog'])->findOrFail($message);

        $existingExport = Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->first();

        if ($existingExport?->export_status === Bitrix24MessageExport::STATUS_EXPORTED) {
            $rootContact = $this->resolveRootContactAction->handle($message->contact()->firstOrFail());

            return new Bitrix24LiveMessageExportQueueResultData(
                queued: false,
                alreadyPending: false,
                ready: true,
                messageId: $message->id,
                rootContactId: $rootContact->id,
            );
        }

        if (! $this->isMessageReadyForBitrix24LiveExportAction->handle($message)) {
            return new Bitrix24LiveMessageExportQueueResultData(
                queued: false,
                alreadyPending: false,
                ready: false,
                messageId: $message->id,
                rootContactId: $message->contact_id ?: null,
            );
        }

        $rootContact = $this->resolveRootContactAction->handle($message->contact()->firstOrFail());

        if (
            $existingExport?->export_status === Bitrix24MessageExport::STATUS_PENDING
            && ! $this->shouldRecoverPendingLiveExport($existingExport)
        ) {
            return new Bitrix24LiveMessageExportQueueResultData(
                queued: false,
                alreadyPending: true,
                ready: true,
                messageId: $message->id,
                rootContactId: $rootContact->id,
            );
        }

        if (
            $existingExport?->export_status === Bitrix24MessageExport::STATUS_FAILED
            && $existingExport->failure_uncertain
        ) {
            return new Bitrix24LiveMessageExportQueueResultData(
                queued: false,
                alreadyPending: false,
                ready: true,
                messageId: $message->id,
                rootContactId: $rootContact->id,
            );
        }

        $liveBatchUuid = (string) Str::uuid();

        Bitrix24MessageExport::query()->updateOrCreate(
            [
                'message_id' => $message->id,
                'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            ],
            [
                'contact_id' => $rootContact->id,
                'bitrix24_contact_id' => $rootContact->bitrix24_contact_id,
                'export_status' => Bitrix24MessageExport::STATUS_PENDING,
                'live_batch_uuid' => $liveBatchUuid,
                'live_claim_uuid' => null,
                'live_claimed_at' => null,
                'live_claim_expires_at' => null,
                'batch_uuid' => null,
                'bitrix24_timeline_entry_id' => null,
                'exported_at' => null,
                'failed_at' => null,
                'failure_code' => null,
                'failure_uncertain' => false,
                'failure_reason' => null,
            ],
        );

        $this->logLiveExportQueued($message, $rootContact->id, $retryAfterSync, $liveBatchUuid, $existingExport);

        ExportMessageToBitrix24OpenLinesJob::dispatch($message->id, $retryAfterSync, $liveBatchUuid)
            ->onQueue(ExportMessageToBitrix24OpenLinesJob::queueName())
            ->afterCommit();
        // The delayed recovery path must survive a failed afterCommit handoff of the immediate job.
        ExportMessageToBitrix24OpenLinesJob::dispatch($message->id, $retryAfterSync, $liveBatchUuid)
            ->onQueue(ExportMessageToBitrix24OpenLinesJob::queueName())
            ->delay(now()->addSeconds(
                self::UNCLAIMED_PENDING_RECOVERY_SECONDS + self::DELAYED_PENDING_RECOVERY_BUFFER_SECONDS
            ))
            ->beforeCommit();

        return new Bitrix24LiveMessageExportQueueResultData(
            queued: true,
            alreadyPending: false,
            ready: true,
            messageId: $message->id,
            rootContactId: $rootContact->id,
        );
    }

    private function hasExpiredLiveClaim(Bitrix24MessageExport $export): bool
    {
        return filled($export->live_claim_uuid)
            && $export->live_claim_expires_at !== null
            && $export->live_claim_expires_at->isPast();
    }

    private function shouldRecoverPendingLiveExport(Bitrix24MessageExport $export): bool
    {
        if (blank($export->live_batch_uuid)) {
            return true;
        }

        if ($this->hasStaleUnclaimedLiveExport($export)) {
            return true;
        }

        return $this->hasExpiredLiveClaim($export);
    }

    private function hasStaleUnclaimedLiveExport(Bitrix24MessageExport $export): bool
    {
        return filled($export->live_batch_uuid)
            && blank($export->live_claim_uuid)
            && $export->updated_at !== null
            && $export->updated_at->lte(now()->subSeconds(self::UNCLAIMED_PENDING_RECOVERY_SECONDS));
    }

    private function logLiveExportQueued(
        Message $message,
        int $rootContactId,
        bool $retryAfterSync,
        string $liveBatchUuid,
        ?Bitrix24MessageExport $existingExport,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'openlines_live_export_queued',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'message_id' => $message->id,
                'dialog_id' => $message->dialog_id,
                'contact_id' => $message->contact_id,
                'root_contact_id' => $rootContactId,
                'channel_id' => $message->channel_id,
                'direction' => $message->direction,
                'message_kind' => $message->message_kind,
                'message_created_at' => $message->created_at?->toISOString(),
                'retry_after_sync' => $retryAfterSync,
                'live_batch_uuid' => $liveBatchUuid,
                'previous_export_status' => $existingExport?->export_status,
                'previous_live_batch_uuid' => $existingExport?->live_batch_uuid,
                'queued_at' => now()->toISOString(),
                'queue_connection' => config('queue.default'),
                'queue_name' => ExportMessageToBitrix24OpenLinesJob::queueName(),
            ],
            entityType: 'message',
            entityId: (string) $message->id,
            fingerprint: 'openlines-live-export-queued:'.$liveBatchUuid,
        );
    }
}
