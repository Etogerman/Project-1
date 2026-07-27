<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24OpenLineRoute;
use Illuminate\Support\Str;

final class MarkBitrix24OpenLineRouteMisconfiguredAction
{
    public function __construct(
        private readonly Bitrix24OpenLineRouteOperationLock $routeOperationLock,
    ) {}

    public function handle(int $routeId, ?string $message): ?Bitrix24OpenLineRoute
    {
        return $this->routeOperationLock->runStateTransition(
            $routeId,
            function (?Bitrix24OpenLineRoute $route) use ($message): ?Bitrix24OpenLineRoute {
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
            },
        );
    }
}
