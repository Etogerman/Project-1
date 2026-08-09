<?php

namespace App\Jobs;

use App\Services\Bitrix24\MarkBitrix24OpenLineRouteMisconfiguredAction;
use DateTime;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MarkBitrix24OpenLineRouteMisconfiguredJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public function __construct(
        public readonly int $routeId,
        public readonly int $expectedStateVersion,
        public readonly int $expectedProfileId,
        public readonly int $expectedChannelId,
        public readonly int $expectedCallbackOwnerId,
        public readonly string $expectedPortalDomain,
        public readonly string $expectedConnectorCode,
        public readonly string $expectedLineId,
        public readonly string $expectedSourceId,
        public readonly string $expectedStatus,
        public readonly ?string $message,
    ) {
        $this->onQueue(ExportMessageToBitrix24OpenLinesJob::queueName());
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(10);
    }

    public function handle(
        MarkBitrix24OpenLineRouteMisconfiguredAction $markRouteMisconfiguredAction,
    ): void {
        try {
            $markRouteMisconfiguredAction->handleDeferredExpected(
                $this->routeId,
                $this->expectedStateVersion,
                $this->expectedProfileId,
                $this->expectedChannelId,
                $this->expectedCallbackOwnerId,
                $this->expectedPortalDomain,
                $this->expectedConnectorCode,
                $this->expectedLineId,
                $this->expectedSourceId,
                $this->expectedStatus,
                $this->message,
            );
        } catch (LockTimeoutException) {
            $this->release(5);
        }
    }
}
