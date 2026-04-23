<?php

namespace App\Jobs;

use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\ProcessBitrix24OpenLinesWebhookAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ProcessBitrix24WebhookEventJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 60;
    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly int $webhookEventId,
    ) {}

    public function handle(
        ProcessBitrix24OpenLinesWebhookAction $processBitrix24OpenLinesWebhookAction,
        LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ): void
    {
        $event = $this->resolveProcessableEvent();

        if (! $event instanceof Bitrix24WebhookEvent || $event->processing_status !== Bitrix24WebhookEvent::STATUS_PENDING) {
            return;
        }

        if ($event->callback_type !== Bitrix24WebhookEvent::TYPE_OPENLINES) {
            return;
        }

        try {
            $processBitrix24OpenLinesWebhookAction->handle($event);
        } catch (Throwable $throwable) {
            if (! $this->isFinalAttempt()) {
                $this->releaseDelayedRecheckClaimIfPending($event);

                throw $throwable;
            }

            $event->refresh();
            $event->forceFill([
                'processing_status' => Bitrix24WebhookEvent::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $throwable->getMessage(),
                'attempts' => $this->attempts(),
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

            $this->fail($throwable);
        }
    }

    private function isFinalAttempt(): bool
    {
        return $this->attempts() >= $this->tries;
    }

    private function releaseDelayedRecheckClaimIfPending(Bitrix24WebhookEvent $event): void
    {
        if ($event->recheck_scheduled_at === null || $event->recheck_attempted_at === null) {
            return;
        }

        Bitrix24WebhookEvent::query()
            ->whereKey($event->id)
            ->where('processing_status', Bitrix24WebhookEvent::STATUS_PENDING)
            ->whereNotNull('recheck_scheduled_at')
            ->update([
                'recheck_attempted_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function resolveProcessableEvent(): ?Bitrix24WebhookEvent
    {
        $event = Bitrix24WebhookEvent::query()->find($this->webhookEventId);

        if (! $event instanceof Bitrix24WebhookEvent) {
            return null;
        }

        if ($event->processing_status !== Bitrix24WebhookEvent::STATUS_PENDING) {
            return null;
        }

        if ($event->recheck_scheduled_at === null) {
            return $event;
        }

        if ($event->recheck_attempted_at !== null || $event->recheck_scheduled_at->isFuture()) {
            return null;
        }

        $claimed = Bitrix24WebhookEvent::query()
            ->whereKey($event->id)
            ->where('processing_status', Bitrix24WebhookEvent::STATUS_PENDING)
            ->whereNotNull('recheck_scheduled_at')
            ->whereNull('recheck_attempted_at')
            ->update([
                'recheck_attempted_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        return Bitrix24WebhookEvent::query()->find($event->id);
    }
}
