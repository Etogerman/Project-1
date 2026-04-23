<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24LiveMessageExportQueueResultData;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Models\Bitrix24MessageExport;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Support\Str;

class QueueBitrix24LiveMessageExportAction
{
    public const UNCLAIMED_PENDING_RECOVERY_SECONDS = 120;

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly IsMessageReadyForBitrix24LiveExportAction $isMessageReadyForBitrix24LiveExportAction,
    ) {}

    public function handle(Message|int $message, bool $retryAfterSync = false): Bitrix24LiveMessageExportQueueResultData
    {
        $message = $message instanceof Message
            ? $message
            : Message::query()->with(['contact', 'dialog'])->findOrFail($message);

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
        $existingExport = Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->first();

        if ($existingExport?->export_status === Bitrix24MessageExport::STATUS_EXPORTED) {
            return new Bitrix24LiveMessageExportQueueResultData(
                queued: false,
                alreadyPending: false,
                ready: true,
                messageId: $message->id,
                rootContactId: $rootContact->id,
            );
        }

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

        ExportMessageToBitrix24OpenLinesJob::dispatch($message->id, $retryAfterSync, $liveBatchUuid)->afterCommit();

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
}
