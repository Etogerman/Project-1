<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24CurrentOpenLineChatData
{
    public function __construct(
        public string $userCode,
        public string $chatId,
        public ?string $lastMessageId = null,
    ) {}
}
