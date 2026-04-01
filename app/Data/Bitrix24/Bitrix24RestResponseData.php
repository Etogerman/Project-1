<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24RestResponseData
{
    /**
     * @param  mixed  $result
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $successful,
        public ?int $httpStatus,
        public mixed $result,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $raw,
        public string $requestMethod,
        public string $restMethod,
        public bool $attemptedRefresh,
    ) {}
}
