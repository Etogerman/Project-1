<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Profile;
use Closure;
use Throwable;

class Bitrix24OpenLineRouteOwnershipLease
{
    public function __construct(
        private readonly Bitrix24OpenLinesRouteRegistryClient $registryClient,
    ) {}

    public function run(
        Bitrix24Profile $profile,
        Bitrix24CallbackOwner $owner,
        string $connectorCode,
        string $connectorType,
        string $lineId,
        int $leaseSeconds,
        Closure $callback,
    ): mixed {
        $this->assertLocalIdentity($profile, $owner, $connectorCode, $connectorType, $lineId);
        $lease = $this->registryClient->acquireLineLease(
            $profile,
            $owner,
            $connectorCode,
            $connectorType,
            $lineId,
            $leaseSeconds,
        );

        try {
            return $callback();
        } finally {
            try {
                $this->registryClient->releaseLineLease(
                    $profile,
                    $owner,
                    $lineId,
                    $lease['lease_token'],
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function assertLocalIdentity(
        Bitrix24Profile $profile,
        Bitrix24CallbackOwner $owner,
        string $connectorCode,
        string $connectorType,
        string $lineId,
    ): void {
        if ((int) $owner->bitrix24_profile_id !== (int) $profile->getKey()
            || ! $owner->isActive()
            || trim((string) $owner->owner_key) === ''
            || trim((string) $owner->callback_base_url) === ''
        ) {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_line_owner_invalid',
                'У маршрута нет активного callback-владельца для общей operation lease.',
            );
        }

        if (trim((string) $profile->portal_domain) === ''
            || trim($connectorCode) === ''
            || ! in_array(trim($connectorType), ['telegram', 'max'], true)
            || trim($lineId) === ''
        ) {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_route_invalid',
                'Маршрут заполнен не полностью для общей operation lease.',
            );
        }
    }
}
