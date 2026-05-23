<?php

namespace App\Services\AI;

use RuntimeException;
use Throwable;

class AiStructuredGenerationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $aiRequestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
