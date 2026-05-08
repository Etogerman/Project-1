<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24StaleContactRepairResultData
{
    public function __construct(
        public bool $repaired,
        public ?string $failureCode = null,
        public ?string $failureReason = null,
    ) {}
}
