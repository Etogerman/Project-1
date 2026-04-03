<?php

namespace App\Data\Scenarios;

final readonly class ScenarioInboundResult
{
    /**
     * @param  array<string, mixed>  $statePayload
     */
    public function __construct(
        public bool $consumed,
        public string $status,
        public ?string $currentStep,
        public array $statePayload,
        public ?string $exitOutcome,
    ) {}
}
