<?php

namespace App\Services\Bitrix24;

final class Bitrix24OpenLinesManualReplyExportException extends Bitrix24ApiException
{
    public function __construct(
        string $message,
        public readonly string $failureCode,
        public readonly bool $failureUncertain = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
