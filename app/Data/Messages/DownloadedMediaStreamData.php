<?php

namespace App\Data\Messages;

use LogicException;

final readonly class DownloadedMediaStreamData
{
    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public mixed $stream,
        public int $sizeBytes,
        public ?string $contentType = null,
        public ?string $filenameHint = null,
        public array $metadata = [],
        public ?int $expectedLengthBytes = null,
    ) {
        if (! is_resource($stream)) {
            throw new LogicException('Downloaded media stream must be a resource.');
        }

        if ($sizeBytes < 0) {
            throw new LogicException('Downloaded media stream size cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            stream: $this->stream,
            sizeBytes: $this->sizeBytes,
            contentType: $this->contentType,
            filenameHint: $this->filenameHint,
            metadata: $metadata,
            expectedLengthBytes: $this->expectedLengthBytes,
        );
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
