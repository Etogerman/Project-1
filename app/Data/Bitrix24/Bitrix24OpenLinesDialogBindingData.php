<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24OpenLinesDialogBindingData
{
    public function __construct(
        public string $userCode,
        public string $connectorCode,
        public string $lineId,
        public string $connectorChatId,
        public string $connectorUserId,
        public ?string $resolvedBitrixChatId = null,
    ) {}
}
