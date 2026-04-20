<?php

namespace App\Jobs;

use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\ProcessBitrix24OpenLinesWebhookAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProcessBitrix24WebhookEventJob implements ShouldQueue
{
    use Queueable;

    private const LEGACY_RECHECK_DRAINED_OPERATION = 'openlines_legacy_recheck_dropped';

    public int $timeout = 60;

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

        if ($this->drainLegacyRecheckEvent($event, $logBitrix24ApiCallAction)) {
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

    private function drainLegacyRecheckEvent(
        Bitrix24WebhookEvent $event,
        LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ): bool {
        if (
            ! Schema::hasColumn('bitrix24_webhook_events', 'recheck_scheduled_at')
            || ! Schema::hasColumn('bitrix24_webhook_events', 'recheck_attempted_at')
        ) {
            return false;
        }

        $scheduledAt = $event->getAttribute('recheck_scheduled_at');
        $attemptedAt = $event->getAttribute('recheck_attempted_at');

        if ($scheduledAt === null && $attemptedAt === null) {
            return false;
        }

        $event->forceFill([
            'processing_status' => Bitrix24WebhookEvent::STATUS_IGNORED,
            'processed_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
            'recheck_attempted_at' => $attemptedAt ?? now(),
        ])->save();

        $logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: self::LEGACY_RECHECK_DRAINED_OPERATION,
            status: Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: [
                'webhook_event_id' => $event->id,
                'event_name' => $event->event_name,
                'callback_type' => $event->callback_type,
            ],
            responsePayload: [
                'recheck_scheduled_at' => $scheduledAt,
                'recheck_attempted_at' => $attemptedAt,
            ],
            connection: $event->connection,
            entityType: 'openlines_webhook_event',
            entityId: (string) $event->id,
        );

        return true;
    }
}
