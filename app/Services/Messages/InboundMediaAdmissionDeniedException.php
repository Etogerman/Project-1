<?php

namespace App\Services\Messages;

use RuntimeException;

class InboundMediaAdmissionDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('Inbound media download admission was temporarily denied.');
    }
}
