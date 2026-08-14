<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use Closure;

final class RunBitrix24OpenLineMutationWithAuthorityAction
{
    public function __construct(
        private readonly Bitrix24OpenLineRouteOperationLock $routeOperationLock,
        private readonly Bitrix24OpenLineMutationAuthorityContext $authorityContext,
    ) {}

    public function handle(
        Bitrix24OpenLinesRouteData $routeData,
        string $operationType,
        Closure $callback,
        string $scope = Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
        bool $requireUsable = true,
    ): mixed {
        $route = $this->resolveRoute($routeData, $requireUsable);
        $currentAuthority = $this->authorityContext->current();

        if ($currentAuthority instanceof Bitrix24OpenLineMutationAuthority) {
            $currentAuthority->assertSameRoute(
                (string) $route->portal_domain,
                (string) $route->connector_code,
                (string) $route->line_id,
            );

            if ($currentAuthority->scope !== $scope) {
                throw new Bitrix24OpenLineMutationAuthorityException(
                    'openlines_mutation_scope_mismatch',
                    'Вложенная Open Lines operation запросила другой lease scope.',
                );
            }

            return $callback($currentAuthority);
        }

        return $this->routeOperationLock->run(
            (int) $route->bitrix24_profile_id,
            (int) $route->channel_id,
            function () use ($callback, $operationType, $requireUsable, $routeData, $scope): mixed {
                $route = $this->resolveRoute($routeData, $requireUsable);
                $profile = $route->bitrix24Profile;
                $owner = $route->callbackOwner;
                $connectorType = Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType(
                    (string) $route->channel_type,
                );

                if (! $profile instanceof Bitrix24Profile
                    || ! $owner instanceof Bitrix24CallbackOwner
                    || $connectorType === null
                ) {
                    throw new Bitrix24OpenLineMutationAuthorityException(
                        'openlines_mutation_owner_invalid',
                        'Маршрут не содержит полного активного callback-владельца для authority.',
                    );
                }

                return $this->routeOperationLock->runForOwnedLine(
                    $profile,
                    $owner,
                    (string) $route->connector_code,
                    $connectorType,
                    (string) $route->line_id,
                    fn (
                        Bitrix24OpenLineRouteLeaseDeadline $deadline,
                        Bitrix24OpenLineMutationAuthority $authority,
                    ): mixed => $callback($authority),
                    scope: $scope,
                    route: $route,
                    operationType: $operationType,
                );
            },
        );
    }

    public function handleLocalOnly(
        Bitrix24OpenLinesRouteData $routeData,
        Closure $callback,
        bool $requireUsable = true,
    ): mixed {
        $route = $this->resolveRoute($routeData, $requireUsable);

        return $this->routeOperationLock->run(
            (int) $route->bitrix24_profile_id,
            (int) $route->channel_id,
            function () use ($callback, $requireUsable, $routeData): mixed {
                $this->resolveRoute($routeData, $requireUsable);

                return $callback();
            },
        );
    }

    private function resolveRoute(
        Bitrix24OpenLinesRouteData $routeData,
        bool $requireUsable,
    ): Bitrix24OpenLineRoute {
        if ($routeData->routeId === null) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_route_missing',
                'Mutating Open Lines operation требует сохранённый route id.',
            );
        }

        $route = Bitrix24OpenLineRoute::query()
            ->with(['bitrix24Profile', 'callbackOwner'])
            ->find($routeData->routeId);

        if (! $route instanceof Bitrix24OpenLineRoute
            || ($requireUsable ? ! $route->isUsable() : ! $route->claimsExternalLine())
            || (string) $route->connector_code !== trim($routeData->connectorCode)
            || ! Bitrix24OpenLineRoute::isValidLineId($routeData->lineId)
            || (string) $route->line_id !== $routeData->lineId
        ) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_route_changed',
                'Маршрут изменился до получения mutating authority.',
            );
        }

        return $route;
    }
}
