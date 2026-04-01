<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24HistoryExportChunkData;
use App\Models\Bitrix24MessageExport;
use App\Models\Contact;

class MarkBitrix24MessageExportsPendingAction
{
    public function handle(Contact $contact, Bitrix24HistoryExportChunkData $chunk, string $batchUuid): void
    {
        $timestamp = now();

        Bitrix24MessageExport::query()->upsert(
            array_map(
                fn (int $messageId): array => [
                    'message_id' => $messageId,
                    'contact_id' => $contact->id,
                    'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                    'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
                    'export_status' => Bitrix24MessageExport::STATUS_PENDING,
                    'batch_uuid' => $batchUuid,
                    'bitrix24_timeline_entry_id' => null,
                    'exported_at' => null,
                    'failed_at' => null,
                    'failure_reason' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                $chunk->messageIds(),
            ),
            ['message_id', 'export_mode'],
            [
                'contact_id',
                'bitrix24_contact_id',
                'export_status',
                'batch_uuid',
                'bitrix24_timeline_entry_id',
                'exported_at',
                'failed_at',
                'failure_reason',
                'updated_at',
            ],
        );
    }
}
