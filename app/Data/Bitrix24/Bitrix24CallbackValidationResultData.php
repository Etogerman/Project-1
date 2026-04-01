<?php

namespace App\Data\Bitrix24;

use App\Models\Bitrix24Connection;

final readonly class Bitrix24CallbackValidationResultData
{
    public function __construct(
        public bool $accepted,
        public string $processingStatus,
        public ?string $reason,
        public ?Bitrix24Connection $connection,
    ) {}
}
