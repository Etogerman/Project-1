<?php

namespace App\Services\AI;

use RuntimeException;
use Throwable;

class AiProviderRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $requestBodyRaw,
        public readonly string $responseBodyRaw,
        public readonly ?int $httpStatus = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $thinkingTokens = null,
        public readonly ?int $totalTokens = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
