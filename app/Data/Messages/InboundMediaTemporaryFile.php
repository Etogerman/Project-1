<?php

namespace App\Data\Messages;

use LogicException;

class InboundMediaTemporaryFile
{
    private bool $closed = false;

    private readonly int $device;

    private readonly int $inode;

    /**
     * @param  resource  $stream
     */
    public function __construct(
        public mixed $stream,
        public readonly string $directory,
        private readonly string $path,
    ) {
        if (! is_resource($stream)) {
            throw new LogicException('Inbound media temporary file stream must be a resource.');
        }

        $metadata = stream_get_meta_data($stream);
        $uri = $metadata['uri'] ?? null;
        $statistics = fstat($stream);
        $resolvedDirectory = realpath($directory);
        $pathDirectory = realpath(dirname($path));

        if (
            ! is_string($uri)
            || $uri !== $path
            || $resolvedDirectory === false
            || $pathDirectory === false
            || $pathDirectory !== $resolvedDirectory
            || ! str_starts_with(basename($path), 'inbound-media-')
            || ! is_array($statistics)
            || ! is_int($statistics['dev'] ?? null)
            || ! is_int($statistics['ino'] ?? null)
        ) {
            throw new LogicException('Inbound media temporary file ownership is invalid.');
        }

        $this->device = $statistics['dev'];
        $this->inode = $statistics['ino'];
    }

    public function closeAndDelete(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            $statistics = @lstat($this->path);

            if (
                is_array($statistics)
                && ($statistics['dev'] ?? null) === $this->device
                && ($statistics['ino'] ?? null) === $this->inode
            ) {
                @unlink($this->path);
            }
        } finally {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
        }
    }

    public function __destruct()
    {
        $this->closeAndDelete();
    }
}
