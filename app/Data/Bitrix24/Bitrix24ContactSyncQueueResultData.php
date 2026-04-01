<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24ContactSyncQueueResultData
{
    public function __construct(
        public bool $queued,
        public bool $alreadyPending,
        public bool $ready,
        public int $rootContactId,
    ) {}
}
