<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24OpenLineRoute;
use Closure;

final class Bitrix24OpenLineRouteMutationFence
{
    public function __construct(
        private readonly Bitrix24LeaseBoundDatabase $leaseBoundDatabase,
    ) {}

    public function begin(
        Bitrix24OpenLineRoute $expectedRoute,
        string $operationId,
        Bitrix24OpenLineRouteLeaseDeadline $deadline,
    ): int {
        $expectedVersion = (int) $expectedRoute->mutation_state_version;

        return $this->leaseBoundDatabase->run(
            $deadline,
            function () use ($deadline, $expectedRoute, $expectedVersion, $operationId): int {
                $route = Bitrix24OpenLineRoute::query()
                    ->whereKey($expectedRoute->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $route instanceof Bitrix24OpenLineRoute
                    || (int) $route->mutation_state_version !== $expectedVersion
                    || ! $this->sameIdentity($route, $expectedRoute)
                ) {
                    throw $this->staleOperationException();
                }

                $deadline->assertAvailableFor(1);
                $nextVersion = $expectedVersion + 1;
                $route->forceFill([
                    'mutation_operation_id' => $operationId,
                    'mutation_state_version' => $nextVersion,
                    'mutation_lease_expires_at' => $deadline->expiresAt(),
                ])->save();

                return $nextVersion;
            },
        );
    }

    public function assertCurrent(Bitrix24OpenLineMutationAuthority $authority): void
    {
        if ($authority->routeId === null || $authority->expectedStateVersion === null) {
            return;
        }

        $current = $this->leaseBoundDatabase->run(
            $authority->deadline,
            fn (): bool => Bitrix24OpenLineRoute::query()
                ->whereKey($authority->routeId)
                ->where('mutation_operation_id', $authority->operationId)
                ->where('mutation_state_version', $authority->expectedStateVersion)
                ->where('portal_domain', $authority->portalDomain)
                ->where('connector_code', $authority->connectorCode)
                ->where('line_id', $authority->lineId)
                ->exists(),
        );

        if (! $current) {
            throw $this->staleOperationException();
        }
    }

    public function runMutation(
        Bitrix24OpenLineMutationAuthority $authority,
        Closure $callback,
    ): mixed {
        return $this->leaseBoundDatabase->run(
            $authority->deadline,
            function () use ($authority, $callback): mixed {
                $route = null;

                if ($authority->routeId !== null && $authority->expectedStateVersion !== null) {
                    $route = Bitrix24OpenLineRoute::query()
                        ->whereKey($authority->routeId)
                        ->where('mutation_operation_id', $authority->operationId)
                        ->where('mutation_state_version', $authority->expectedStateVersion)
                        ->where('portal_domain', $authority->portalDomain)
                        ->where('connector_code', $authority->connectorCode)
                        ->where('line_id', $authority->lineId)
                        ->lockForUpdate()
                        ->first();

                    if (! $route instanceof Bitrix24OpenLineRoute) {
                        throw $this->staleOperationException();
                    }

                    $route->preserveMutationFenceVersion();
                }

                try {
                    $authority->deadline->assertAvailableFor(1);
                    $result = $callback($route);
                    $authority->deadline->assertAvailableFor(0);

                    return $result;
                } finally {
                    $route?->resumeMutationFenceVersioning();
                }
            },
        );
    }

    public function finish(Bitrix24OpenLineMutationAuthority $authority): void
    {
        if ($authority->routeId === null || $authority->expectedStateVersion === null) {
            return;
        }

        try {
            $this->leaseBoundDatabase->run(
                $authority->deadline,
                function () use ($authority): void {
                    $route = Bitrix24OpenLineRoute::query()
                        ->whereKey($authority->routeId)
                        ->where('mutation_operation_id', $authority->operationId)
                        ->where('mutation_state_version', $authority->expectedStateVersion)
                        ->where('portal_domain', $authority->portalDomain)
                        ->where('connector_code', $authority->connectorCode)
                        ->where('line_id', $authority->lineId)
                        ->lockForUpdate()
                        ->first();

                    if (! $route instanceof Bitrix24OpenLineRoute) {
                        return;
                    }

                    $route->forceFill([
                        'mutation_operation_id' => null,
                        'mutation_state_version' => $authority->expectedStateVersion,
                        'mutation_lease_expires_at' => null,
                    ])->save();
                },
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function sameIdentity(
        Bitrix24OpenLineRoute $route,
        Bitrix24OpenLineRoute $expectedRoute,
    ): bool {
        return (int) $route->bitrix24_profile_id === (int) $expectedRoute->bitrix24_profile_id
            && (int) $route->channel_id === (int) $expectedRoute->channel_id
            && (int) $route->callback_owner_id === (int) $expectedRoute->callback_owner_id
            && (string) $route->portal_domain === (string) $expectedRoute->portal_domain
            && (string) $route->connector_code === (string) $expectedRoute->connector_code
            && (string) $route->line_id === (string) $expectedRoute->line_id;
    }

    private function staleOperationException(): Bitrix24OpenLineMutationAuthorityException
    {
        return new Bitrix24OpenLineMutationAuthorityException(
            'openlines_mutation_fence_stale',
            'Состояние маршрута изменилось другой операцией; поздняя запись заблокирована.',
        );
    }
}
