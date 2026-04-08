<?php

namespace App\Data\Messages;

final readonly class PreparedMessageContentData
{
    public function __construct(
        public string $textFormat,
        public string $plainText,
        public ?string $sourceText,
        public string $transportText,
    ) {}
}
