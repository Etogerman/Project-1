<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24HistoryExportChunkData;
use App\Models\Bitrix24MessageExport;
use App\Models\Contact;

class MarkBitrix24MessageExportsFailedAction
{
    public function handle(
        Contact $contact,
        Bitrix24HistoryExportChunkData $chunk,
        string $batchUuid,
        string $failureReason,
    ): void {
        Bitrix24MessageExport::query()
            ->whereIn('message_id', $chunk->messageIds())
            ->where('export_mode', Bitrix24MessageExport::MODE_HISTORY)
            ->update([
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                'export_status' => Bitrix24MessageExport::STATUS_FAILED,
                'batch_uuid' => $batchUuid,
                'bitrix24_timeline_entry_id' => null,
                'failed_at' => now(),
                'failure_reason' => $failureReason,
                'updated_at' => now(),
            ]);
    }
}
