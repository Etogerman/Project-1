<?php

namespace App\Services\Bitrix24;

use Throwable;

final class Bitrix24OpenLineMutationGuardException extends Bitrix24ApiException
{
    public function __construct(
        string $message,
        public readonly string $failureCode,
        public readonly bool $failureUncertain = false,
        ?Throwable $previous = null,
        public readonly ?string $relatedChatId = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
