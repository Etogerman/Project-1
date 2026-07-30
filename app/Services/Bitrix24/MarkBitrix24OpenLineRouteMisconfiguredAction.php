<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24OpenLineRoute;
use Illuminate\Support\Str;

final class MarkBitrix24OpenLineRouteMisconfiguredAction
{
    public function __construct(
        private readonly Bitrix24OpenLineRouteOperationLock $routeOperationLock,
        private readonly Bitrix24OpenLineRouteMutationFence $mutationFence,
        private readonly Bitrix24OpenLineMutationAuthorityContext $authorityContext,
        private readonly Bitrix24OpenLinesRouteRegistrySnapshotLock $snapshotLock,
    ) {}

    public function handle(
        int $routeId,
        ?string $message,
        ?Bitrix24OpenLineMutationAuthority $authority = null,
    ): ?Bitrix24OpenLineRoute {
        return $this->snapshotLock->run(
            fn (): ?Bitrix24OpenLineRoute => $this->handleUnderSnapshotLock(
                $routeId,
                $message,
                $authority,
            ),
        );
    }

    private function handleUnderSnapshotLock(
        int $routeId,
        ?string $message,
        ?Bitrix24OpenLineMutationAuthority $authority,
    ): ?Bitrix24OpenLineRoute {
        $authority ??= $this->authorityContext->current();

        if ($authority instanceof Bitrix24OpenLineMutationAuthority) {
            if ($authority->routeId !== $routeId) {
                throw new Bitrix24OpenLineMutationAuthorityException(
                    'openlines_mutation_route_mismatch',
                    'Нельзя изменить состояние другого маршрута под текущим authority.',
                );
            }

            return $this->mutationFence->runMutation(
                $authority,
                fn (?Bitrix24OpenLineRoute $route): ?Bitrix24OpenLineRoute => $this->mark(
                    $route,
                    $message,
                ),
            );
        }

        return $this->routeOperationLock->runShortStateTransition(
            $routeId,
            fn (?Bitrix24OpenLineRoute $route): ?Bitrix24OpenLineRoute => $this->mark(
                $route,
                $message,
            ),
        );
    }

    public function handleExpected(
        Bitrix24OpenLineRoute $expectedRoute,
        ?string $message,
    ): ?Bitrix24OpenLineRoute {
        return $this->snapshotLock->run(
            fn (): ?Bitrix24OpenLineRoute => $this->routeOperationLock->runShortStateTransition(
                (int) $expectedRoute->getKey(),
                function (?Bitrix24OpenLineRoute $route) use (
                    $expectedRoute,
                    $message,
                ): ?Bitrix24OpenLineRoute {
                    if (! $this->matchesExpectedState($route, $expectedRoute)) {
                        return null;
                    }

                    return $this->mark($route, $message);
                },
            ),
        );
    }

    private function mark(
        ?Bitrix24OpenLineRoute $route,
        ?string $message,
    ): ?Bitrix24OpenLineRoute {
        if (! $route instanceof Bitrix24OpenLineRoute) {
            return null;
        }

        $route->forceFill([
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
            'last_error_message' => filled($message)
                ? Str::limit((string) $message, 1000, '')
                : null,
            'last_error_at' => now(),
        ])->save();

        return $route->refresh();
    }

    private function matchesExpectedState(
        ?Bitrix24OpenLineRoute $route,
        Bitrix24OpenLineRoute $expectedRoute,
    ): bool {
        return $route instanceof Bitrix24OpenLineRoute
            && (int) $route->mutation_state_version === (int) $expectedRoute->mutation_state_version
            && (string) $route->mutation_operation_id === (string) $expectedRoute->mutation_operation_id
            && (int) $route->bitrix24_profile_id === (int) $expectedRoute->bitrix24_profile_id
            && (int) $route->channel_id === (int) $expectedRoute->channel_id
            && (int) $route->callback_owner_id === (int) $expectedRoute->callback_owner_id
            && (string) $route->portal_domain === (string) $expectedRoute->portal_domain
            && (string) $route->connector_code === (string) $expectedRoute->connector_code
            && (string) $route->line_id === (string) $expectedRoute->line_id
            && (string) $route->source_id === (string) $expectedRoute->source_id
            && (string) $route->status === (string) $expectedRoute->status;
    }
}
