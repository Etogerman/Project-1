<?php

namespace App\Jobs;

use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\ProcessBitrix24OpenLinesWebhookAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBitrix24WebhookEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $webhookEventId,
    ) {}

    public function handle(
        ProcessBitrix24OpenLinesWebhookAction $processBitrix24OpenLinesWebhookAction,
        LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ): void
    {
        $event = Bitrix24WebhookEvent::query()->find($this->webhookEventId);

        if (! $event instanceof Bitrix24WebhookEvent || $event->processing_status !== Bitrix24WebhookEvent::STATUS_PENDING) {
            return;
        }

        if ($event->callback_type !== Bitrix24WebhookEvent::TYPE_OPENLINES) {
            return;
        }

        try {
            $processBitrix24OpenLinesWebhookAction->handle($event);
        } catch (Throwable $throwable) {
            $event->refresh();
            $event->forceFill([
                'processing_status' => Bitrix24WebhookEvent::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $throwable->getMessage(),
                'attempts' => $event->attempts + 1,
            ])->save();

            $logBitrix24ApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'openlines_event_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'webhook_event_id' => $event->id,
                    'event_name' => $event->event_name,
                    'callback_type' => $event->callback_type,
                ],
                connection: $event->connection,
                errorMessage: $throwable->getMessage(),
                entityType: 'openlines_webhook_event',
                entityId: (string) $event->id,
            );
        }
    }
}
