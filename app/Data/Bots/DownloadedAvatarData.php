<?php

namespace App\Data\Bots;

final readonly class DownloadedAvatarData
{
    public function __construct(
        public string $contents,
        public ?string $contentType = null,
        public ?string $filenameHint = null,
    ) {}
}
