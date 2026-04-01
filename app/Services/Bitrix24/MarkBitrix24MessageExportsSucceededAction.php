<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24HistoryExportChunkData;
use App\Models\Bitrix24MessageExport;
use App\Models\Contact;

class MarkBitrix24MessageExportsSucceededAction
{
    public function handle(
        Contact $contact,
        Bitrix24HistoryExportChunkData $chunk,
        string $batchUuid,
        ?string $timelineEntryId,
    ): void {
        Bitrix24MessageExport::query()
            ->whereIn('message_id', $chunk->messageIds())
            ->where('export_mode', Bitrix24MessageExport::MODE_HISTORY)
            ->update([
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $contact->bitrix24_contact_id,
                'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
                'batch_uuid' => $batchUuid,
                'bitrix24_timeline_entry_id' => $timelineEntryId,
                'exported_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'updated_at' => now(),
            ]);
    }
}
