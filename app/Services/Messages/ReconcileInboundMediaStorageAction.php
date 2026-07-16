<?php

namespace App\Services\Messages;

use App\Models\MediaDownloadStorageLedger;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use SplPriorityQueue;
use Throwable;

class ReconcileInboundMediaStorageAction
{
    public const REASON_LOCAL_FILE_MISSING = 'local_file_missing';

    public const REASON_INVALID_LOCAL_REFERENCE = 'invalid_local_reference';

    public const ATTACHMENT_CURSOR_CACHE_KEY = 'inbound-media:reconcile-storage:attachment-cursor';

    public const ORPHAN_CURSOR_CACHE_KEY = 'inbound-media:reconcile-storage:orphan-cursor';

    public function __construct(
        private readonly InboundMediaQuotaLedger $quotaLedger,
        private readonly ReconcileInboundMediaQuotaBudgetsAction $budgetReconciler,
    ) {}

    /**
     * @return array<string, int>
     */
    public function handle(
        bool $repair = false,
        int $attachmentLimit = 5000,
        int $orphanLimit = 5000,
    ): array {
        $stats = [
            'attachments_checked' => 0,
            'invalid_references' => 0,
            'invalid_references_quarantined' => 0,
            'missing_files' => 0,
            'attachments_marked_deleted' => 0,
            'storage_ledger_drift' => 0,
            'storage_ledgers_created' => 0,
            'storage_ledgers_corrected' => 0,
            'orphan_files' => 0,
            'orphan_files_deleted' => 0,
            'orphan_files_retained' => 0,
            'orphan_scan_truncated' => 0,
            'failures' => 0,
            'budget_drift_rows' => 0,
            'remaining_drift_rows' => 0,
        ];
        $attachmentLimit = min(max($attachmentLimit, 1), 50_000);
        $orphanLimit = min(max($orphanLimit, 1), 50_000);

        $attachments = $this->attachmentsForScan($attachmentLimit);

        $attachments->each(function (MessageAttachment $attachment) use ($repair, &$stats): void {
            $stats['attachments_checked']++;

            try {
                $this->reconcileAttachment($attachment, $repair, $stats);
            } catch (Throwable $exception) {
                report($exception);
                $stats['failures']++;
            }
        });

        if ($attachments->isEmpty()) {
            Cache::forget(self::ATTACHMENT_CURSOR_CACHE_KEY);
        } else {
            Cache::forever(
                self::ATTACHMENT_CURSOR_CACHE_KEY,
                (int) $attachments->last()->getKey(),
            );
        }

        $this->reconcileOrphans($repair, $orphanLimit, $stats);
        $budgetStats = $this->budgetReconciler->handle($repair);
        $stats['budget_drift_rows'] = $budgetStats['storage_drift_rows']
            + $budgetStats['traffic_drift_rows'];
        $stats['remaining_drift_rows'] = $budgetStats['remaining_drift_rows'];

        return $stats;
    }

    /**
     * @return EloquentCollection<int, MessageAttachment>
     */
    private function attachmentsForScan(int $limit): EloquentCollection
    {
        $cursor = max(0, (int) Cache::get(self::ATTACHMENT_CURSOR_CACHE_KEY, 0));
        $attachments = MessageAttachment::query()
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED)
            ->when($cursor > 0, fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $remaining = $limit - $attachments->count();

        if ($remaining < 1 || $cursor < 1) {
            return $attachments;
        }

        return $attachments
            ->concat(
                MessageAttachment::query()
                    ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED)
                    ->where('id', '<=', $cursor)
                    ->orderBy('id')
                    ->limit($remaining)
                    ->get(),
            )
            ->values();
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function reconcileAttachment(
        MessageAttachment $attachment,
        bool $repair,
        array &$stats,
    ): void {
        if (! $this->hasSafeStableReference($attachment)) {
            $stats['invalid_references']++;

            if ($repair) {
                $result = $this->repairInvalidStableReference((int) $attachment->getKey());

                if ($result === 'deleted') {
                    $stats['attachments_marked_deleted']++;
                } elseif ($result === 'quarantined') {
                    $stats['invalid_references_quarantined']++;
                }
            }

            return;
        }

        $disk = (string) $attachment->local_disk;
        $path = (string) $attachment->local_path;
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            $stats['missing_files']++;

            if ($repair && $this->markMissingAttachmentDeleted((int) $attachment->getKey(), $disk, $path)) {
                $stats['attachments_marked_deleted']++;
            }

            return;
        }

        $actualBytes = max(0, (int) $storage->size($path));
        $generation = max(1, (int) $attachment->media_download_generation);
        $ledger = MediaDownloadStorageLedger::query()
            ->where('message_attachment_id', $attachment->getKey())
            ->where('generation', $generation)
            ->first();

        if (
            $ledger instanceof MediaDownloadStorageLedger
            && $ledger->status === MediaDownloadStorageLedger::STATUS_USED
            && (int) $ledger->used_bytes === $actualBytes
        ) {
            return;
        }

        $stats['storage_ledger_drift']++;

        if (! $repair) {
            return;
        }

        $result = $this->repairPresentAttachment(
            (int) $attachment->getKey(),
            $disk,
            $path,
            $actualBytes,
        );

        if ($result === 'created') {
            $stats['storage_ledgers_created']++;
        } elseif ($result === 'corrected') {
            $stats['storage_ledgers_corrected']++;
        }
    }

    private function repairInvalidStableReference(int $attachmentId): string
    {
        return DB::transaction(function () use ($attachmentId): string {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (
                ! $attachment instanceof MessageAttachment
                || $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                || $this->hasSafeStableReference($attachment)
            ) {
                return 'unchanged';
            }

            if (! is_string($attachment->local_path) || trim($attachment->local_path) === '') {
                $attachment->forceFill([
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
                    'local_disk' => null,
                    'local_path' => null,
                    'safe_error_code' => self::REASON_LOCAL_FILE_MISSING,
                    'safe_error_message' => 'Ссылка на локальную копию файла отсутствует.',
                ])->save();

                $this->quotaLedger->releaseUsedStorageAfterDeletion(
                    $attachment,
                    self::REASON_LOCAL_FILE_MISSING,
                );

                return 'deleted';
            }

            $attachment->forceFill([
                'safe_error_code' => self::REASON_INVALID_LOCAL_REFERENCE,
                'safe_error_message' => 'Ссылка на локальную копию требует безопасной ручной проверки.',
            ])->save();

            return 'quarantined';
        }, 3);
    }

    private function repairPresentAttachment(
        int $attachmentId,
        string $expectedDisk,
        string $expectedPath,
        int $actualBytes,
    ): string {
        return DB::transaction(function () use ($attachmentId, $expectedDisk, $expectedPath, $actualBytes): string {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (
                ! $attachment instanceof MessageAttachment
                || $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                || $attachment->local_disk !== $expectedDisk
                || $attachment->local_path !== $expectedPath
                || ! $this->hasSafeStableReference($attachment)
            ) {
                return 'unchanged';
            }

            return $this->quotaLedger->reconcileUsedStorage(
                $attachment,
                max(0, $actualBytes),
            );
        }, 3);
    }

    private function markMissingAttachmentDeleted(
        int $attachmentId,
        string $expectedDisk,
        string $expectedPath,
    ): bool {
        return DB::transaction(function () use ($attachmentId, $expectedDisk, $expectedPath): bool {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (
                ! $attachment instanceof MessageAttachment
                || $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                || $attachment->local_disk !== $expectedDisk
                || $attachment->local_path !== $expectedPath
                || ! $this->hasSafeStableReference($attachment)
            ) {
                return false;
            }

            $attachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
                'local_disk' => null,
                'local_path' => null,
                'safe_error_code' => self::REASON_LOCAL_FILE_MISSING,
                'safe_error_message' => 'Локальная копия файла отсутствует.',
            ])->save();

            $this->quotaLedger->releaseUsedStorageAfterDeletion(
                $attachment,
                self::REASON_LOCAL_FILE_MISSING,
            );

            return true;
        }, 3);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function reconcileOrphans(bool $repair, int $limit, array &$stats): void
    {
        $window = $this->orphanScanWindow($limit, $stats);
        $referenced = $this->referencedStorageKeys($window);
        $graceSeconds = max(
            (int) config('inbound_media.orphan_grace_seconds', 0),
            (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60)
                + (int) config('inbound_media.reservation_ttl_buffer_seconds', 15 * 60),
        );

        foreach ($window as [$disk, $path]) {
            try {
                if (
                    ! MessageAttachment::isSafeLocalPath($path)
                    || isset($referenced[$this->storageKey($disk, $path)])
                ) {
                    continue;
                }

                $stats['orphan_files']++;

                try {
                    $storage = Storage::disk($disk);
                    $ageSeconds = max(0, now()->timestamp - $storage->lastModified($path));

                    if ($ageSeconds < $graceSeconds) {
                        $stats['orphan_files_retained']++;

                        continue;
                    }

                    if (! $repair) {
                        continue;
                    }

                    $storage->delete($path);

                    if ($storage->exists($path)) {
                        $stats['failures']++;
                    } else {
                        $stats['orphan_files_deleted']++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $stats['failures']++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $stats['failures']++;
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $window
     * @return array<string, true>
     */
    private function referencedStorageKeys(array $window): array
    {
        $candidates = [];

        foreach ($window as [$disk, $path]) {
            if (MessageAttachment::isSafeLocalPath($path)) {
                $candidates[$this->storageKey($disk, $path)] = true;
            }
        }

        if ($candidates === []) {
            return [];
        }

        $referenced = [];
        $attachments = MessageAttachment::query()
            ->whereNotNull('local_disk')
            ->whereNotNull('local_path')
            ->select(['id', 'local_disk', 'local_path'])
            ->lazyById(500);

        foreach ($attachments as $attachment) {
            if (! $this->hasSafeStableReference($attachment)) {
                continue;
            }

            $key = $this->storageKey(
                (string) $attachment->local_disk,
                (string) $attachment->local_path,
            );

            if (! isset($candidates[$key])) {
                continue;
            }

            $referenced[$key] = true;

            if (count($referenced) === count($candidates)) {
                break;
            }
        }

        return $referenced;
    }

    /**
     * @param  array<string, int>  $stats
     * @return list<array{0: string, 1: string}>
     */
    private function orphanScanWindow(int $limit, array &$stats): array
    {
        $cursor = Cache::get(self::ORPHAN_CURSOR_CACHE_KEY);
        $cursor = is_string($cursor) ? $cursor : '';
        $keys = new SplPriorityQueue;
        $keys->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $total = 0;

        foreach (MessageAttachment::readableStorageDiskNames() as $disk) {
            try {
                $listing = Storage::disk($disk)
                    ->getDriver()
                    ->listContents(MessageAttachment::LOCAL_PATH_PREFIX, true);

                foreach ($listing as $attributes) {
                    if (! $attributes instanceof StorageAttributes || ! $attributes->isFile()) {
                        continue;
                    }

                    $key = $this->storageKey($disk, $attributes->path());
                    $segment = $cursor !== '' && strcmp($key, $cursor) <= 0 ? 1 : 0;
                    $keys->insert($key, $segment."\0".$key);
                    $total++;

                    if ($keys->count() > $limit) {
                        $keys->extract();
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                $stats['failures']++;
            }
        }

        if ($total === 0) {
            Cache::forget(self::ORPHAN_CURSOR_CACHE_KEY);

            return [];
        }

        $window = [];

        while (! $keys->isEmpty()) {
            $entry = $keys->extract();
            $window[] = $entry['data'];
        }

        usort(
            $window,
            fn (string $left, string $right): int => $this->compareOrphanStorageKeys($left, $right, $cursor),
        );

        if ($total > count($window)) {
            $stats['orphan_scan_truncated'] = 1;
        }

        Cache::forever(self::ORPHAN_CURSOR_CACHE_KEY, $window[array_key_last($window)]);

        return array_map(function (string $key): array {
            [$disk, $path] = explode("\0", $key, 2);

            return [$disk, $path];
        }, $window);
    }

    private function compareOrphanStorageKeys(string $left, string $right, string $cursor): int
    {
        $leftSegment = $cursor !== '' && strcmp($left, $cursor) <= 0 ? 1 : 0;
        $rightSegment = $cursor !== '' && strcmp($right, $cursor) <= 0 ? 1 : 0;

        return ($leftSegment <=> $rightSegment) ?: strcmp($left, $right);
    }

    private function hasSafeStableReference(MessageAttachment $attachment): bool
    {
        return is_string($attachment->local_disk)
            && in_array($attachment->local_disk, MessageAttachment::readableStorageDiskNames(), true)
            && MessageAttachment::isSafeLocalPath($attachment->local_path);
    }

    private function storageKey(string $disk, string $path): string
    {
        return $disk."\0".$path;
    }
}
