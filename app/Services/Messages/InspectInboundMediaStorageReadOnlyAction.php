<?php

namespace App\Services\Messages;

use App\Models\MediaDownloadStorageLedger;
use App\Models\MessageAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InspectInboundMediaStorageReadOnlyAction
{
    /**
     * @return array{
     *     storage_ledger_drift:int,
     *     storage_scan_complete:bool,
     *     orphan_count:int|null,
     *     orphan_observed:bool,
     *     orphan_scan_truncated:bool,
     *     incomplete_reasons:list<string>
     * }
     */
    public function handle(CarbonImmutable $snapshotAt, int $orphanScanLimit): array
    {
        $attachmentScanLimit = $this->scanLimit(
            (int) config('inbound_media.observability.attachment_scan_limit', 5000),
        );
        $objectScanLimit = $this->scanLimit($orphanScanLimit);
        $attachments = $this->attachmentSnapshot($attachmentScanLimit);
        $inventory = $this->storageInventory($objectScanLimit);
        $storageScanComplete = $attachments['complete'] && $inventory['complete'];
        $observedOrphanCount = $attachments['complete']
            ? $this->orphanCount($snapshotAt, $attachments['references'], $inventory['objects'])
            : 0;
        $orphanCount = $storageScanComplete ? $observedOrphanCount : null;

        return [
            'storage_ledger_drift' => $this->storageLedgerDrift(
                $attachments['downloaded'],
                $inventory['objects'],
                $inventory['complete'],
            ),
            'storage_scan_complete' => $storageScanComplete,
            'orphan_count' => $orphanCount,
            'orphan_observed' => $observedOrphanCount > 0,
            'orphan_scan_truncated' => $inventory['truncated'],
            'incomplete_reasons' => array_values(array_unique([
                ...$attachments['incomplete_reasons'],
                ...$inventory['incomplete_reasons'],
            ])),
        ];
    }

    /**
     * @return array{
     *     downloaded:list<object>,
     *     references:array<string,true>,
     *     complete:bool,
     *     incomplete_reasons:list<string>
     * }
     */
    private function attachmentSnapshot(int $limit): array
    {
        $rows = DB::table('message_attachments as attachments')
            ->leftJoin('media_download_storage_ledgers as ledgers', function (JoinClause $join): void {
                $join
                    ->on('ledgers.message_attachment_id', '=', 'attachments.id')
                    ->on('ledgers.generation', '=', 'attachments.media_download_generation');
            })
            ->where(function ($query): void {
                $query
                    ->where('attachments.download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED)
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNotNull('attachments.local_disk')
                            ->whereNotNull('attachments.local_path');
                    });
            })
            ->orderBy('attachments.id')
            ->limit($limit + 1)
            ->get([
                'attachments.id',
                'attachments.download_status',
                'attachments.local_disk',
                'attachments.local_path',
                'ledgers.status as ledger_status',
                'ledgers.used_bytes as ledger_used_bytes',
            ]);
        $truncated = $rows->count() > $limit;
        $references = [];
        $downloaded = [];

        foreach ($rows->take($limit) as $row) {
            $disk = is_string($row->local_disk) ? trim($row->local_disk) : '';
            $path = is_string($row->local_path) ? trim($row->local_path) : '';

            if ($this->hasSafeStableReference($disk, $path)) {
                $references[$this->storageKey($disk, $path)] = true;
            }

            if ($row->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                $row->local_disk = $disk;
                $row->local_path = $path;
                $downloaded[] = $row;
            }
        }

        return [
            'downloaded' => $downloaded,
            'references' => $references,
            'complete' => ! $truncated,
            'incomplete_reasons' => $truncated ? ['attachment_scan_truncated'] : [],
        ];
    }

    /**
     * @return array{
     *     objects:array<string,array{size:int,last_modified:int}>,
     *     complete:bool,
     *     truncated:bool,
     *     incomplete_reasons:list<string>
     * }
     */
    private function storageInventory(int $limit): array
    {
        $objects = [];
        $inspected = 0;
        $truncated = false;
        $failed = false;

        try {
            foreach (MessageAttachment::readableStorageDiskNames() as $disk) {
                $storage = Storage::disk($disk);

                foreach ($storage->getDriver()->listContents(MessageAttachment::LOCAL_PATH_PREFIX, true) as $attributes) {
                    if (! $attributes->isFile()) {
                        continue;
                    }

                    if ($inspected >= $limit) {
                        $truncated = true;

                        break 2;
                    }

                    $inspected++;
                    $path = $attributes->path();

                    if (! MessageAttachment::isSafeLocalPath($path)) {
                        continue;
                    }

                    $size = $attributes->fileSize();
                    $lastModified = $attributes->lastModified();

                    if ($size === null || $lastModified === null) {
                        $failed = true;

                        break 2;
                    }

                    $objects[$this->storageKey($disk, $path)] = [
                        'size' => max(0, $size),
                        'last_modified' => $lastModified,
                    ];
                }
            }
        } catch (Throwable) {
            $failed = true;
        }

        return [
            'objects' => $objects,
            'complete' => ! $truncated && ! $failed,
            'truncated' => $truncated,
            'incomplete_reasons' => array_values(array_filter([
                $truncated ? 'orphan_scan_truncated' : null,
                $failed ? 'storage_scan_failed' : null,
            ])),
        ];
    }

    /**
     * @param  list<object>  $attachments
     * @param  array<string,array{size:int,last_modified:int}>  $objects
     */
    private function storageLedgerDrift(
        array $attachments,
        array $objects,
        bool $inventoryComplete,
    ): int {
        $drift = 0;

        foreach ($attachments as $attachment) {
            $disk = $attachment->local_disk;
            $path = $attachment->local_path;

            if (! $this->hasSafeStableReference($disk, $path)) {
                $drift++;

                continue;
            }

            $object = $objects[$this->storageKey($disk, $path)] ?? null;

            if ($object === null) {
                if ($inventoryComplete) {
                    $drift++;
                }

                continue;
            }

            if (
                $attachment->ledger_status !== MediaDownloadStorageLedger::STATUS_USED
                || (int) $attachment->ledger_used_bytes !== $object['size']
            ) {
                $drift++;
            }
        }

        return $drift;
    }

    /**
     * @param  array<string,true>  $references
     * @param  array<string,array{size:int,last_modified:int}>  $objects
     */
    private function orphanCount(
        CarbonImmutable $snapshotAt,
        array $references,
        array $objects,
    ): int {
        $staleBefore = $snapshotAt->subSeconds($this->orphanSafetyWindowSeconds())->timestamp;
        $count = 0;

        foreach ($objects as $key => $object) {
            if (! isset($references[$key]) && $object['last_modified'] <= $staleBefore) {
                $count++;
            }
        }

        return $count;
    }

    private function hasSafeStableReference(string $disk, string $path): bool
    {
        return in_array($disk, MessageAttachment::readableStorageDiskNames(), true)
            && MessageAttachment::isSafeLocalPath($path);
    }

    private function orphanSafetyWindowSeconds(): int
    {
        return max(
            0,
            (int) config('inbound_media.orphan_grace_seconds', 0),
            (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60)
                + (int) config('inbound_media.reservation_ttl_buffer_seconds', 15 * 60),
        );
    }

    private function scanLimit(int $configured): int
    {
        return max($configured, 1);
    }

    private function storageKey(string $disk, string $path): string
    {
        return $disk."\0".$path;
    }
}
