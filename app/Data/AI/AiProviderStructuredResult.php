<?php

namespace App\Data\AI;

final readonly class AiProviderStructuredResult
{
    /**
     * @param  array<string, mixed>  $parsedPayload
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $parsedPayload,
        public string $requestBodyRaw,
        public string $responseBodyRaw,
        public ?int $httpStatus = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $thinkingTokens = null,
        public ?int $totalTokens = null,
        public ?float $providerReportedCost = null,
        public ?string $providerReportedCurrency = null,
    ) {}
}
