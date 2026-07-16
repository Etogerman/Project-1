<?php

namespace App\Jobs;

use App\Services\Messages\DeleteRolledBackInboundMediaFileAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteRolledBackInboundMediaFileJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public int $uniqueFor;

    public function __construct(
        public readonly int $attachmentId,
        public readonly string $disk,
        public readonly string $path,
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
        return 'inbound-media-rolled-back-file-cleanup:'
            .$this->attachmentId.':'
            .substr(hash('sha256', $this->disk."\0".$this->path), 0, 24);
    }

    public function handle(DeleteRolledBackInboundMediaFileAction $deleteFile): void
    {
        $deleteFile->deleteOrFail($this->attachmentId, $this->disk, $this->path);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('inbound_media.rolled_back_file_cleanup_dead_letter', [
            'attachment_id' => $this->attachmentId,
            'error_type' => $exception !== null
                ? $exception::class
                : 'unknown',
        ]);
    }
}
