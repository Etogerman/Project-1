<?php

namespace App\Services\Bitrix24;

use RuntimeException;

class Bitrix24OpenLinesRouteRegistryException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
