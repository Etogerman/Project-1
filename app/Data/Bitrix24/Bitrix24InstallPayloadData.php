<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24InstallPayloadData
{
    /**
     * @param  list<string>  $scope
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public ?string $portalDomain,
        public ?string $applicationToken,
        public ?string $memberId,
        public ?string $clientEndpoint,
        public ?string $serverEndpoint,
        public ?string $accessToken,
        public ?string $refreshToken,
        public ?string $expiresAt,
        public array $scope,
        public array $rawPayload,
    ) {}
}
