<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use RuntimeException;
use Throwable;

class AutoReplyDispatchException extends RuntimeException
{
    public function __construct(
        public readonly AutoReplyRule $rule,
        public readonly ?string $buttonType,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
