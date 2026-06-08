<?php

namespace App\Data\AI;

final readonly class AiStructuredGenerationResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public ?int $aiRequestId = null,
        public ?int $finalAttemptId = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $status = null,
    ) {}
}
