<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24AuthContextData
{
    public function __construct(
        public ?string $portalDomain,
        public ?string $memberId,
        public ?string $applicationToken,
        public ?string $clientEndpoint,
        public ?string $serverEndpoint,
        public ?string $status,
    ) {}
}
