<?php

namespace App\Data\Bots;

final readonly class MaxVideoAttachmentDownloadData
{
    public function __construct(
        public ?string $downloadUrl,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $duration = null,
    ) {}

    /**
     * @return array<string, int>
     */
    public function metadata(): array
    {
        return array_filter([
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
        ], static fn (?int $value): bool => $value !== null);
    }
}
