<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24OpenLinesManualReplyExportData
{
    public function __construct(
        public string $resolvedBitrixChatId,
        public string $bitrixRemoteMessageId,
        public bool $usedFallback,
        public bool $usedChatUserAddRecovery,
        public ?string $resolvedCrmEntityType = null,
        public ?string $resolvedCrmEntityId = null,
    ) {}
}
