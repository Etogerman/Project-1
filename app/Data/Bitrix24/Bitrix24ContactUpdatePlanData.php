<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24ContactUpdatePlanData
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $payload,
        public string $fingerprint,
        public array $warnings = [],
    ) {}
}
