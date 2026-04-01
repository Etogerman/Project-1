<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use Illuminate\Support\Str;
use Throwable;

class SyncChatHistoryToBitrix24Action
{
    public function __construct(
        private readonly ResolveActiveBitrix24ConnectionAction $resolveActiveConnectionAction,
        private readonly CollectBitrix24HistoryContactIdsAction $collectHistoryContactIdsAction,
        private readonly CollectBitrix24HistoryMessagesAction $collectHistoryMessagesAction,
        private readonly BuildBitrix24HistoryExportChunksAction $buildHistoryExportChunksAction,
        private readonly ExportBitrix24HistoryChunkAction $exportHistoryChunkAction,
        private readonly MarkBitrix24MessageExportsPendingAction $markMessageExportsPendingAction,
        private readonly MarkBitrix24MessageExportsSucceededAction $markMessageExportsSucceededAction,
        private readonly MarkBitrix24MessageExportsFailedAction $markMessageExportsFailedAction,
        private readonly LogBitrix24ApiCallAction $logApiCallAction,
    ) {}

    public function handle(Contact $contact): Contact
    {
        $connection = $this->resolveActiveConnectionAction->handle();
        $clusterContactIds = $this->collectHistoryContactIdsAction->handle($contact);
        $messages = $this->collectHistoryMessagesAction->handle($contact);

        if ($messages->isEmpty()) {
            $contact->forceFill([
                'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED,
                'bitrix24_history_last_synced_at' => now(),
            ])->save();

            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'history_export_noop',
                status: Bitrix24SyncLog::STATUS_SUCCESS,
                requestPayload: [
                    'contact_id' => $contact->id,
                    'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                    'cluster_contact_ids' => $clusterContactIds,
                ],
                responsePayload: [
                    'candidate_count' => 0,
                ],
                connection: $connection,
                entityType: 'contact',
                entityId: (string) $contact->id,
            );

            return $contact->fresh();
        }

        $chunks = $this->buildHistoryExportChunksAction->handle($messages);
        $batchUuid = (string) Str::uuid();

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'history_export_started',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                'cluster_contact_ids' => $clusterContactIds,
            ],
            responsePayload: [
                'batch_uuid' => $batchUuid,
                'candidate_count' => $messages->count(),
                'chunk_count' => count($chunks),
                'message_ids' => $messages->pluck('id')->all(),
            ],
            connection: $connection,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );

        foreach ($chunks as $chunk) {
            $this->markMessageExportsPendingAction->handle($contact, $chunk, $batchUuid);

            try {
                $timelineEntryId = $this->exportHistoryChunkAction->handle($contact, $chunk);
            } catch (Throwable $throwable) {
                $this->markMessageExportsFailedAction->handle(
                    $contact,
                    $chunk,
                    $batchUuid,
                    $throwable->getMessage(),
                );

                $contact->forceFill([
                    'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED,
                ])->save();

                $this->logApiCallAction->handle(
                    direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                    operation: 'history_export_chunk_failed',
                    status: Bitrix24SyncLog::STATUS_FAILED,
                    requestPayload: [
                        'contact_id' => $contact->id,
                        'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                        'batch_uuid' => $batchUuid,
                        'chunk_sequence' => $chunk->sequence,
                        'chunk_total' => $chunk->total,
                        'message_ids' => $chunk->messageIds(),
                    ],
                    connection: $connection,
                    errorMessage: $throwable->getMessage(),
                    entityType: 'contact',
                    entityId: (string) $contact->id,
                );

                return $contact->fresh();
            }

            $this->markMessageExportsSucceededAction->handle(
                $contact,
                $chunk,
                $batchUuid,
                $timelineEntryId,
            );

            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'history_export_chunk_sent',
                status: Bitrix24SyncLog::STATUS_SUCCESS,
                requestPayload: [
                    'contact_id' => $contact->id,
                    'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                    'batch_uuid' => $batchUuid,
                    'chunk_sequence' => $chunk->sequence,
                    'chunk_total' => $chunk->total,
                    'message_ids' => $chunk->messageIds(),
                ],
                responsePayload: [
                    'bitrix24_timeline_entry_id' => $timelineEntryId,
                ],
                connection: $connection,
                entityType: 'contact',
                entityId: (string) $contact->id,
            );
        }

        $contact->forceFill([
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED,
            'bitrix24_history_last_synced_at' => now(),
        ])->save();

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'history_export_completed',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                'batch_uuid' => $batchUuid,
            ],
            responsePayload: [
                'chunk_count' => count($chunks),
                'message_count' => $messages->count(),
            ],
            connection: $connection,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );

        return $contact->fresh();
    }
}
