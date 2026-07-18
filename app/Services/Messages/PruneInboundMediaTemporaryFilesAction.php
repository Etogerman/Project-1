<?php

namespace App\Services\Messages;

class PruneInboundMediaTemporaryFilesAction
{
    private const FILE_PREFIX = 'inbound-media-';

    /**
     * @return array{inspected:int,deleted:int,failed:int,skipped:int,unavailable:int}
     */
    public function handle(int $limit = 100): array
    {
        $stats = [
            'inspected' => 0,
            'deleted' => 0,
            'failed' => 0,
            'skipped' => 0,
            'unavailable' => 0,
        ];
        $directory = $this->resolvedTemporaryDirectory();

        if ($directory === null) {
            $stats['unavailable'] = 1;

            return $stats;
        }

        $limit = min(max($limit, 1), 500);
        $cutoffTimestamp = time() - $this->staleAfterSeconds();
        $paths = glob($directory.DIRECTORY_SEPARATOR.self::FILE_PREFIX.'*') ?: [];
        $candidates = [];

        foreach ($paths as $path) {
            $statistics = @lstat($path);

            if (! is_array($statistics)) {
                continue;
            }

            $candidates[] = [
                'path' => $path,
                'modified_at' => is_int($statistics['mtime'] ?? null)
                    ? $statistics['mtime']
                    : PHP_INT_MAX,
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $left['modified_at'] <=> $right['modified_at'],
        );

        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $path = $candidate['path'];
            $stats['inspected']++;

            if (! $this->isSafeRegularFile($path, $directory) || ! $this->isStale($path, $cutoffTimestamp)) {
                $stats['skipped']++;

                continue;
            }

            $stream = @fopen($path, 'r+b');

            if ($stream === false) {
                $stats['failed']++;

                continue;
            }

            try {
                if (! flock($stream, LOCK_EX | LOCK_NB)) {
                    $stats['skipped']++;

                    continue;
                }

                // Re-check after taking the lock so a recently touched file
                // cannot be deleted based on stale metadata from the scan.
                clearstatcache(true, $path);

                if (
                    ! $this->isSafeRegularFile($path, $directory, $stream)
                    || ! $this->isStale($path, $cutoffTimestamp)
                ) {
                    $stats['skipped']++;

                    continue;
                }

                if (@unlink($path)) {
                    $stats['deleted']++;
                } else {
                    $stats['failed']++;
                }
            } finally {
                fclose($stream);
            }
        }

        return $stats;
    }

    private function resolvedTemporaryDirectory(): ?string
    {
        $configuredDirectory = config(
            'inbound_media.temporary_directory',
            storage_path('app/inbound-media-tmp'),
        );
        $directory = is_string($configuredDirectory) ? trim($configuredDirectory) : '';

        if ($directory === '' || ! is_dir($directory)) {
            return null;
        }

        $resolvedDirectory = realpath($directory);

        return is_string($resolvedDirectory) && is_readable($resolvedDirectory)
            ? $resolvedDirectory
            : null;
    }

    private function staleAfterSeconds(): int
    {
        return max(
            60,
            (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60)
                + (int) config('inbound_media.lease_stale_seconds', 120),
        );
    }

    private function isStale(string $path, int $cutoffTimestamp): bool
    {
        $modifiedAt = @filemtime($path);

        return is_int($modifiedAt) && $modifiedAt <= $cutoffTimestamp;
    }

    /**
     * @param  resource|null  $stream
     */
    private function isSafeRegularFile(string $path, string $directory, mixed $stream = null): bool
    {
        $pathStatistics = @lstat($path);

        if (
            ! is_array($pathStatistics)
            || (($pathStatistics['mode'] ?? 0) & 0170000) !== 0100000
            || is_link($path)
            || realpath(dirname($path)) !== $directory
            || ! str_starts_with(basename($path), self::FILE_PREFIX)
        ) {
            return false;
        }

        if (! is_resource($stream)) {
            return true;
        }

        $streamStatistics = fstat($stream);

        return is_array($streamStatistics)
            && ($pathStatistics['dev'] ?? null) === ($streamStatistics['dev'] ?? null)
            && ($pathStatistics['ino'] ?? null) === ($streamStatistics['ino'] ?? null);
    }
}
