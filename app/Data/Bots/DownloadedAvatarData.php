<?php

namespace App\Data\Bots;

final readonly class DownloadedAvatarData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $contents,
        public ?string $contentType = null,
        public ?string $filenameHint = null,
        public array $metadata = [],
    ) {}
}
