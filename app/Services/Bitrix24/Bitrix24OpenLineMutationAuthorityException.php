<?php

namespace App\Services\Bitrix24;

final class Bitrix24OpenLineMutationAuthorityException extends Bitrix24ApiException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
