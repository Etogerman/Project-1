<?php

namespace App\Services\Bitrix24;

use Throwable;

final class Bitrix24LiveExportTransportException extends Bitrix24ApiException
{
    public function __construct(
        string $message,
        public readonly ?string $failureCode = null,
        public readonly bool $failureUncertain = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
