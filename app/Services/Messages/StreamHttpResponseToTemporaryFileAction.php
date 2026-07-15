<?php

namespace App\Services\Messages;

use App\Data\Messages\DownloadedMediaStreamData;
use Closure;
use Illuminate\Http\Client\Response as HttpResponse;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

class StreamHttpResponseToTemporaryFileAction
{
    private const READ_CHUNK_BYTES = 64 * 1024;

    private const PROGRESS_CHECKPOINT_BYTES = 1024 * 1024;

    private const PROGRESS_CHECKPOINT_SECONDS = 30;

    public function __construct(
        private readonly InboundMediaStorageCapacity $storageCapacity,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  Closure(int): void|null  $onProgress
     */
    public function handle(
        HttpResponse $response,
        int $maxBytes,
        ?string $filenameHint = null,
        array $metadata = [],
        ?Closure $onProgress = null,
        string $tooLargeMessage = 'Downloaded media is larger than the configured limit.',
        string $emptyMessage = 'Downloaded media response is empty.',
    ): DownloadedMediaStreamData {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Downloaded media limit must be positive.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Media download response must have a successful HTTP status.');
        }

        $expectedLength = $this->contentLength($response);

        if ($expectedLength !== null && $expectedLength > $maxBytes) {
            throw new InvalidArgumentException($tooLargeMessage);
        }

        return $this->handleStream(
            source: $response->toPsrResponse()->getBody(),
            maxBytes: $maxBytes,
            expectedLength: $expectedLength,
            contentType: $response->header('Content-Type'),
            filenameHint: $filenameHint,
            metadata: $metadata,
            onProgress: $onProgress,
            tooLargeMessage: $tooLargeMessage,
            emptyMessage: $emptyMessage,
        );
    }

    /**
     * @param  resource|StreamInterface  $source
     * @param  array<string, mixed>  $metadata
     * @param  Closure(int): void|null  $onProgress
     */
    public function handleStream(
        mixed $source,
        int $maxBytes,
        ?int $expectedLength = null,
        ?string $contentType = null,
        ?string $filenameHint = null,
        array $metadata = [],
        ?Closure $onProgress = null,
        string $tooLargeMessage = 'Downloaded media is larger than the configured limit.',
        string $emptyMessage = 'Downloaded media response is empty.',
    ): DownloadedMediaStreamData {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Downloaded media limit must be positive.');
        }

        if ($expectedLength !== null && $expectedLength > $maxBytes) {
            throw new InvalidArgumentException($tooLargeMessage);
        }

        if (! is_resource($source) && ! $source instanceof StreamInterface) {
            throw new InvalidArgumentException('Downloaded media source must be a readable stream.');
        }

        [$temporary, $temporaryDirectory] = $this->openTemporaryStream();

        $receivedBytes = 0;
        $checkpointBytes = 0;
        $checkpointAt = microtime(true);

        try {
            while (! $this->sourceAtEnd($source)) {
                try {
                    $chunk = $this->readSource($source, min(
                        self::READ_CHUNK_BYTES,
                        $maxBytes - $receivedBytes + 1,
                    ));
                } catch (RuntimeException $exception) {
                    if ($expectedLength !== null && $receivedBytes >= $expectedLength) {
                        break;
                    }

                    throw $exception;
                }

                if ($chunk === '') {
                    break;
                }

                $receivedBytes += strlen($chunk);

                if ($receivedBytes > $maxBytes) {
                    throw new InvalidArgumentException($tooLargeMessage);
                }

                $this->assertTemporaryStorageCapacity(
                    $temporaryDirectory,
                    strlen($chunk),
                    $receivedBytes,
                );
                $this->writeAll($temporary, $chunk);

                if (
                    $onProgress instanceof Closure
                    && (
                        $receivedBytes - $checkpointBytes >= self::PROGRESS_CHECKPOINT_BYTES
                        || microtime(true) - $checkpointAt >= self::PROGRESS_CHECKPOINT_SECONDS
                    )
                ) {
                    $checkpointBytes = $receivedBytes;
                    $checkpointAt = microtime(true);
                    $onProgress($receivedBytes);
                }
            }

            if ($receivedBytes === 0) {
                throw new InvalidArgumentException($emptyMessage);
            }

            if ($onProgress instanceof Closure && $checkpointBytes !== $receivedBytes) {
                $checkpointBytes = $receivedBytes;
                $onProgress($receivedBytes);
            }

            if ($expectedLength !== null && $receivedBytes !== $expectedLength) {
                throw new MediaDownloadIntegrityException('Downloaded media size does not match the declared length.');
            }

            rewind($temporary);

            return new DownloadedMediaStreamData(
                stream: $temporary,
                sizeBytes: $receivedBytes,
                contentType: $contentType,
                filenameHint: $filenameHint,
                metadata: $metadata,
            );
        } catch (Throwable $throwable) {
            if ($onProgress instanceof Closure && $checkpointBytes !== $receivedBytes) {
                $checkpointBytes = $receivedBytes;

                try {
                    $onProgress($receivedBytes);
                } catch (Throwable $progressFailure) {
                    fclose($temporary);

                    throw $progressFailure;
                }
            }

            fclose($temporary);

            throw $throwable;
        }
    }

    /**
     * @param  resource|StreamInterface  $source
     */
    private function sourceAtEnd(mixed $source): bool
    {
        return is_resource($source) ? feof($source) : $source->eof();
    }

    /**
     * @param  resource|StreamInterface  $source
     */
    private function readSource(mixed $source, int $length): string
    {
        if (! is_resource($source)) {
            return $source->read($length);
        }

        $chunk = fread($source, $length);

        if ($chunk === false) {
            throw new RuntimeException('Failed to read downloaded media stream.');
        }

        return $chunk;
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll(mixed $stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write downloaded media to a temporary stream.');
            }

            $offset += $written;
        }
    }

    /**
     * @return array{0:resource,1:string}
     */
    private function openTemporaryStream(): array
    {
        $configuredDirectory = config(
            'inbound_media.temporary_directory',
            storage_path('app/inbound-media-tmp'),
        );
        $directory = is_string($configuredDirectory) ? trim($configuredDirectory) : '';

        if ($directory === '') {
            throw new RuntimeException('Inbound media temporary directory is not configured.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Failed to create the inbound media temporary directory.');
        }

        $resolvedDirectory = realpath($directory);

        if ($resolvedDirectory === false || ! is_writable($resolvedDirectory)) {
            throw new RuntimeException('Inbound media temporary directory is not writable.');
        }

        $temporaryPath = tempnam($resolvedDirectory, 'inbound-media-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Failed to create a temporary media file.');
        }

        $temporary = @fopen($temporaryPath, 'w+b');

        if ($temporary === false) {
            @unlink($temporaryPath);

            throw new RuntimeException('Failed to open a temporary media stream.');
        }

        if (! @unlink($temporaryPath)) {
            fclose($temporary);
            @unlink($temporaryPath);

            throw new RuntimeException('Failed to unlink the temporary media file.');
        }

        return [$temporary, $resolvedDirectory];
    }

    private function assertTemporaryStorageCapacity(
        string $directory,
        int $chunkBytes,
        int $transferredBytes,
    ): void {
        $availableBytes = $this->storageCapacity->availableBytesForPath($directory);

        if ($availableBytes === null || $availableBytes < $chunkBytes) {
            throw new InboundMediaQuotaExceededException(
                InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED,
                $transferredBytes,
            );
        }
    }

    private function contentLength(HttpResponse $response): ?int
    {
        $header = $response->header('Content-Length');

        if ($header === null) {
            return null;
        }

        $normalized = trim(strtok($header, ',') ?: '');

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        return (int) $normalized;
    }
}
