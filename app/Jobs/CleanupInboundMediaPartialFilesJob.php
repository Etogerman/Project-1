<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use App\Services\Messages\DeleteInboundMediaPartialFilesAction;
use App\Services\Messages\InboundMediaQuotaLedger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CleanupInboundMediaPartialFilesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public int $uniqueFor;

    public function __construct(
        public readonly int $attachmentId,
        public readonly ?int $generation = null,
        public readonly ?string $claimToken = null,
    ) {
        $this->uniqueFor = max(
            60,
            (int) config('inbound_media.cleanup.unique_for_seconds', (6 * 60 * 60) + (15 * 60)),
        );
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_values(array_map(
            static fn (mixed $seconds): int => max(1, (int) $seconds),
            (array) config('inbound_media.cleanup.retry_delays_seconds', [60, 300, 900, 3600]),
        ));
    }

    public function uniqueId(): string
    {
        $scope = $this->generation !== null
            ? 'g'.max(1, $this->generation).':'.substr(hash('sha256', (string) $this->claimToken), 0, 16)
            : 'legacy';

        return 'inbound-media-partial-cleanup:'.$this->attachmentId.':'.$scope;
    }

    public function handle(
        DeleteInboundMediaPartialFilesAction $deletePartialFiles,
        InboundMediaQuotaLedger $quotaLedger,
    ): void {
        $attachment = MessageAttachment::query()->find($this->attachmentId);

        if (! $attachment instanceof MessageAttachment) {
            return;
        }

        $transferredBytes = $deletePartialFiles->scopedBytes(
            $attachment,
            $this->claimToken,
            $this->generation,
        );

        if ($transferredBytes === null) {
            throw new RuntimeException('Inbound media partial inspection failed.');
        }

        if ($transferredBytes > 0 && $this->isCurrentRevokedClaim($attachment)) {
            $quotaLedger->checkpointTraffic(
                $attachment,
                $attachment->mediaDownloadLedgerAttemptNumber(),
                $transferredBytes,
            );
        }

        if (! $deletePartialFiles->handle($attachment, $this->claimToken, $this->generation)) {
            throw new RuntimeException('Inbound media partial cleanup failed.');
        }
    }

    private function isCurrentRevokedClaim(MessageAttachment $attachment): bool
    {
        if ($this->generation === null || $this->claimToken === null) {
            return false;
        }

        return (int) $attachment->media_download_generation === max(1, $this->generation)
            && (string) $attachment->media_download_claim_token === 'revoked-'.$this->claimToken;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('inbound_media.partial_cleanup_dead_letter', [
            'attachment_id' => $this->attachmentId,
            'error_type' => $exception !== null
                ? $exception::class
                : 'unknown',
        ]);
    }
}
