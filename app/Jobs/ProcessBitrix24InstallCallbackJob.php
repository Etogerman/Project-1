<?php

namespace App\Jobs;

use App\Models\Bitrix24WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessBitrix24InstallCallbackJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $webhookEventId,
    ) {}

    public function handle(): void
    {
        $event = Bitrix24WebhookEvent::query()->find($this->webhookEventId);

        if (! $event || $event->processing_status !== Bitrix24WebhookEvent::STATUS_PENDING) {
            return;
        }

        $event->forceFill([
            'processing_status' => Bitrix24WebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ])->save();
    }
}
