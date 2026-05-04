<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24OpenLinesManualReplyChatData
{
    public function __construct(
        public string $chatId,
        public bool $usedFallback,
        public bool $trustedReusableSource = false,
        public ?string $crmEntityType = null,
        public ?string $crmEntityId = null,
        public ?int $ownerUserId = null,
    ) {}
}
