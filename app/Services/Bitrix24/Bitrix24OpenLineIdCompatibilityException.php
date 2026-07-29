<?php

namespace App\Services\Bitrix24;

use RuntimeException;

final class Bitrix24OpenLineIdCompatibilityException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
