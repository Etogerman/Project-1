<?php

namespace App\Services\Messages;

use RuntimeException;

class InboundMediaQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $transferredBytes = 0,
    ) {
        parent::__construct($reason);
    }
}
