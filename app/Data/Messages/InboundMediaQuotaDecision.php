<?php

namespace App\Data\Messages;

final readonly class InboundMediaQuotaDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public ?string $shadowReason = null,
        public int $storageReservedBytes = 0,
        public int $trafficReservedBytes = 0,
    ) {}
}
