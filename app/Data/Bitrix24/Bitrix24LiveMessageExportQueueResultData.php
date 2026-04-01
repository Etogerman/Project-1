<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24LiveMessageExportQueueResultData
{
    public function __construct(
        public bool $queued,
        public bool $alreadyPending,
        public bool $ready,
        public int $messageId,
        public ?int $rootContactId,
    ) {}
}
