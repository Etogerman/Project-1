<?php

namespace App\Services\Messages;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InboundMediaStorageCapacity
{
    public function availableBytes(): ?int
    {
        try {
            $diskName = MessageAttachment::storageDiskName();
            $driver = config("filesystems.disks.{$diskName}.driver");

            if (! is_string($driver) || trim($driver) === '') {
                return null;
            }

            $driver = strtolower(trim($driver));

            if ($driver === 's3') {
                return PHP_INT_MAX;
            }

            if ($driver !== 'local') {
                return null;
            }

            $path = Storage::disk($diskName)->path('');
        } catch (Throwable) {
            return null;
        }

        return $this->availableBytesForPath($path);
    }

    public function availableBytesForPath(string $path): ?int
    {
        $minimumFreeBytes = max(0, (int) config('inbound_media.storage.minimum_free_bytes', 0));
        $minimumFreePercent = min(
            100,
            max(0, (int) config('inbound_media.storage.minimum_free_percent', 0)),
        );

        if ($minimumFreeBytes === 0 && $minimumFreePercent === 0) {
            return PHP_INT_MAX;
        }

        try {
            $freeBytes = @disk_free_space($path);
            $totalBytes = @disk_total_space($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_numeric($freeBytes) || ! is_numeric($totalBytes) || (float) $totalBytes <= 0) {
            return null;
        }

        $requiredFreeBytes = max(
            $minimumFreeBytes,
            (int) ceil((float) $totalBytes * ($minimumFreePercent / 100)),
        );

        return (int) floor((float) $freeBytes) - $requiredFreeBytes;
    }
}
