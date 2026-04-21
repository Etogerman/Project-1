<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24OpenLinesManualReplyChatData
{
    public function __construct(
        public string $chatId,
        public bool $usedFallback,
        public bool $trustedReusableSource = false,
    ) {}
}
