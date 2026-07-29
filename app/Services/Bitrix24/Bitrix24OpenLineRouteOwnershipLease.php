<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use Closure;
use Illuminate\Support\Str;
use Throwable;

class Bitrix24OpenLineRouteOwnershipLease
{
    public function __construct(
        private readonly Bitrix24OpenLinesRouteRegistryClient $registryClient,
        private readonly Bitrix24OpenLineRouteMutationFence $mutationFence,
        private readonly Bitrix24OpenLineMutationAuthorityContext $authorityContext,
    ) {}

    public function run(
        Bitrix24Profile $profile,
        Bitrix24CallbackOwner $owner,
        string $connectorCode,
        string $connectorType,
        string $lineId,
        int $leaseSeconds,
        Closure $callback,
        string $scope = Bitrix24OpenLineMutationAuthority::SCOPE_CONNECTOR_REGISTRATION,
        ?Bitrix24OpenLineRoute $route = null,
        string $operationType = 'openlines_mutation',
    ): mixed {
        $this->assertLocalIdentity($profile, $owner, $connectorCode, $connectorType, $lineId);
        $operationId = (string) Str::uuid();
        $lease = $this->registryClient->acquireLineLease(
            $profile,
            $owner,
            $connectorCode,
            $connectorType,
            $lineId,
            $leaseSeconds,
            $scope,
        );
        $authority = null;

        try {
            $deadline = Bitrix24OpenLineRouteLeaseDeadline::fromRegistryLease(
                $lease['expires_at'],
                $leaseSeconds,
                max(
                    1,
                    (int) config('bitrix24.http.timeout_seconds', 15),
                    (int) config('bitrix24.http.connect_timeout_seconds', 5),
                ),
            );
            $deadline->assertAvailableFor(0);
            $expectedStateVersion = $route instanceof Bitrix24OpenLineRoute
                ? $this->mutationFence->begin($route, $operationId, $deadline)
                : null;
            $authority = new Bitrix24OpenLineMutationAuthority(
                portalDomain: mb_strtolower(trim((string) $profile->portal_domain)),
                lineId: (string) Bitrix24OpenLineRoute::canonicalLineId($lineId),
                ownerProfileKey: trim((string) $owner->owner_key),
                ownerCallbackBaseUrl: rtrim(trim((string) $owner->callback_base_url), '/'),
                connectorCode: trim($connectorCode),
                connectorType: trim($connectorType),
                scope: $scope,
                leaseToken: $lease['lease_token'],
                deadline: $deadline,
                operationId: $operationId,
                operationType: trim($operationType),
                routeId: $route?->getKey(),
                expectedStateVersion: $expectedStateVersion,
            );

            return $this->authorityContext->run(
                $authority,
                fn (): mixed => $callback($deadline, $authority),
            );
        } finally {
            if ($authority instanceof Bitrix24OpenLineMutationAuthority) {
                $this->mutationFence->finish($authority);
            }

            try {
                $this->registryClient->releaseLineLease(
                    $profile,
                    $owner,
                    $lineId,
                    $lease['lease_token'],
                    $scope,
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
            || ! Bitrix24OpenLineRoute::isValidConnectorCode($connectorCode)
            || ! in_array(trim($connectorType), ['telegram', 'max'], true)
            || ! Bitrix24OpenLineRoute::isValidLineId($lineId)
        ) {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_route_invalid',
                'Маршрут заполнен не полностью для общей operation lease.',
            );
        }
    }
}
