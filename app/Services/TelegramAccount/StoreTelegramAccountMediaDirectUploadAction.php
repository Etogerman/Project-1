<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaQuotaExceededException;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class StoreTelegramAccountMediaDirectUploadAction
{
    public function __construct(
        private readonly StoreMessageAttachmentLocalFileAction $storeMessageAttachmentLocalFileAction,
        private readonly InboundMediaQuotaLedger $quotaLedger,
    ) {}

    /**
     * @param  resource  $stream
     */
    public function handle(
        Channel $channel,
        MessageAttachment $attachment,
        string $claimToken,
        mixed $stream,
        ?string $contentRange = null,
        ?int $contentLength = null,
    ): int {
        if ((int) $attachment->channel_id !== (int) $channel->id) {
            throw new InvalidArgumentException('Direct media upload is not expected for this attachment.');
        }

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Direct media upload must provide a readable stream.');
        }

        $disk = MessageAttachment::storageDiskName();

        if ((string) config("filesystems.disks.{$disk}.driver", '') !== 'local') {
            throw new InvalidArgumentException('Laravel direct media upload is only available for a local private disk.');
        }

        $range = $contentRange === null
            ? null
            : $this->parseContentRange($contentRange);

        return DB::transaction(function () use ($attachment, $claimToken, $disk, $stream, $range, $contentLength): int {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $locked->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
                || $locked->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                || ! hash_equals((string) $locked->media_download_claim_token, $claimToken)
            ) {
                throw new InvalidArgumentException('Direct media upload claim token is no longer current.');
            }

            if (
                $locked->media_download_attempt_deadline_at === null
                || $locked->media_download_attempt_deadline_at->lte(now())
            ) {
                throw new InvalidArgumentException('Direct media upload claim has expired.');
            }

            $path = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($locked, $claimToken);

            if ($range !== null) {
                [$start, $end, $total] = $range;
                $expectedTotal = $locked->media_download_upload_size_bytes;

                if ($expectedTotal !== null && (int) $expectedTotal !== $total) {
                    throw new InvalidArgumentException('Direct media upload declared total size has changed.');
                }

                try {
                    $this->quotaLedger->assertCanCompleteAttempt(
                        $locked,
                        $locked->mediaDownloadLedgerAttemptNumber(),
                        $total,
                    );
                } catch (InboundMediaQuotaExceededException $exception) {
                    throw new InboundMediaQuotaExceededException(
                        $exception->reason,
                        $this->storedUploadBytes($disk, $path),
                    );
                }

                $storedSize = $this->appendChunk($disk, $path, $stream, $start, $end, $total);

                $locked->forceFill([
                    'media_download_upload_size_bytes' => $total,
                    'media_download_heartbeat_at' => now(),
                    'updated_at' => now(),
                ])->save();

                $this->quotaLedger->checkpointTraffic(
                    $locked,
                    $locked->mediaDownloadLedgerAttemptNumber(),
                    $storedSize,
                );

                return $storedSize;
            }

            if ($contentLength === null) {
                throw new InvalidArgumentException('Direct media upload requires Content-Length or Content-Range.');
            }

            if ($contentLength > CreateTelegramAccountMediaUploadTargetAction::LOCAL_UPLOAD_CHUNK_BYTES) {
                throw new InvalidArgumentException('Direct media upload body exceeds the allowed single-chunk size.');
            }

            $this->quotaLedger->assertCanCompleteAttempt(
                $locked,
                $locked->mediaDownloadLedgerAttemptNumber(),
                $contentLength,
            );

            $storedSize = $this->replaceSingleChunk($disk, $path, $stream, $contentLength);

            $locked->forceFill([
                'media_download_upload_size_bytes' => $storedSize,
                'media_download_heartbeat_at' => now(),
                'updated_at' => now(),
            ])->save();

            $this->quotaLedger->checkpointTraffic(
                $locked,
                $locked->mediaDownloadLedgerAttemptNumber(),
                $storedSize,
            );

            return $storedSize;
        });
    }

    /**
     * @param  resource  $stream
     */
    private function replaceSingleChunk(
        string $disk,
        string $path,
        mixed $stream,
        int $contentLength,
    ): int {
        $absolutePath = Storage::disk($disk)->path($path);
        File::ensureDirectoryExists(dirname($absolutePath));
        $target = fopen($absolutePath, 'w+b');

        if ($target === false) {
            throw new RuntimeException('Failed to open direct Telegram Account media upload.');
        }

        try {
            if (! flock($target, LOCK_EX)) {
                throw new RuntimeException('Failed to lock direct Telegram Account media upload.');
            }

            $copied = $contentLength === 0
                ? 0
                : stream_copy_to_stream($stream, $target, $contentLength);
            $extraByte = fread($stream, 1);

            if ($copied !== $contentLength || $extraByte === false || $extraByte !== '') {
                throw new InvalidArgumentException('Direct media upload body size does not match Content-Length.');
            }

            if (! fflush($target)) {
                throw new RuntimeException('Failed to flush direct Telegram Account media upload.');
            }

            return $contentLength;
        } catch (\Throwable $exception) {
            ftruncate($target, 0);

            throw $exception;
        } finally {
            flock($target, LOCK_UN);
            fclose($target);

            if (isset($exception)) {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    /**
     * @param  resource  $stream
     */
    private function appendChunk(
        string $disk,
        string $path,
        mixed $stream,
        int $start,
        int $end,
        int $total,
    ): int {
        $chunkBytes = $end - $start + 1;

        if ($chunkBytes > CreateTelegramAccountMediaUploadTargetAction::LOCAL_UPLOAD_CHUNK_BYTES) {
            throw new InvalidArgumentException('Direct media upload chunk exceeds the allowed size.');
        }

        $absolutePath = Storage::disk($disk)->path($path);
        File::ensureDirectoryExists(dirname($absolutePath));
        $target = fopen($absolutePath, 'c+b');

        if ($target === false) {
            throw new RuntimeException('Failed to open direct Telegram Account media upload.');
        }

        try {
            if (! flock($target, LOCK_EX)) {
                throw new RuntimeException('Failed to lock direct Telegram Account media upload.');
            }

            $currentSize = fstat($target)['size'] ?? null;

            if (! is_int($currentSize)) {
                throw new RuntimeException('Failed to inspect direct Telegram Account media upload.');
            }

            if ($currentSize === $total) {
                return $currentSize;
            }

            if ($currentSize === $end + 1) {
                return $currentSize;
            }

            if ($currentSize !== $start || fseek($target, $start) !== 0) {
                throw new InvalidArgumentException('Direct media upload chunk offset is no longer current.');
            }

            $copied = stream_copy_to_stream($stream, $target, $chunkBytes);
            $extraByte = fread($stream, 1);

            if ($copied !== $chunkBytes || $extraByte === false || $extraByte !== '') {
                ftruncate($target, $start);

                throw new InvalidArgumentException('Direct media upload chunk size does not match Content-Range.');
            }

            if (! fflush($target)) {
                ftruncate($target, $start);

                throw new RuntimeException('Failed to flush direct Telegram Account media upload chunk.');
            }

            $storedSize = $end + 1;

            if ($storedSize > $total) {
                ftruncate($target, $start);

                throw new InvalidArgumentException('Direct media upload chunk exceeds declared total size.');
            }

            return $storedSize;
        } finally {
            flock($target, LOCK_UN);
            fclose($target);
        }
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function parseContentRange(string $contentRange): array
    {
        if (preg_match('/\Abytes (\d+)-(\d+)\/(\d+)\z/', trim($contentRange), $matches) !== 1) {
            throw new InvalidArgumentException('Direct media upload requires a valid Content-Range header.');
        }

        $start = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $end = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $total = filter_var($matches[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($start) || ! is_int($end) || ! is_int($total) || $end < $start || $end >= $total) {
            throw new InvalidArgumentException('Direct media upload Content-Range is inconsistent.');
        }

        return [$start, $end, $total];
    }

    private function storedUploadBytes(string $disk, string $path): int
    {
        try {
            $storage = Storage::disk($disk);

            return $storage->exists($path) ? max(0, (int) $storage->size($path)) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
