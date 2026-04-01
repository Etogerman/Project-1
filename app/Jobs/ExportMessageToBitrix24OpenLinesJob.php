<?php

namespace App\Jobs;

use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Message;
use App\Services\Bitrix24\ExportMessageToBitrix24OpenLinesAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExportMessageToBitrix24OpenLinesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $messageId,
    ) {}

    public function handle(
        ExportMessageToBitrix24OpenLinesAction $exportMessageToBitrix24OpenLinesAction,
        LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ): void {
        $message = Message::query()->find($this->messageId);

        if (! $message instanceof Message) {
            return;
        }

        try {
            $exportMessageToBitrix24OpenLinesAction->handle($message);
        } catch (Throwable $throwable) {
            $logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_live_export_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'message_id' => $message->id,
                    'dialog_id' => $message->dialog_id,
                    'contact_id' => $message->contact_id,
                ],
                connection: null,
                errorMessage: $throwable->getMessage(),
                entityType: 'message',
                entityId: (string) $message->id,
            );
        }
    }
}
