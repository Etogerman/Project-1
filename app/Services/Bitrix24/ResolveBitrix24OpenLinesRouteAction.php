<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\Dialog;

class ResolveBitrix24OpenLinesRouteAction
{
    public function __construct(
        private readonly ResolveCurrentBitrix24ProfileAction $resolveCurrentProfileAction,
    ) {}

    public function handle(Dialog|int $dialog): Bitrix24OpenLinesRouteData
    {
        [$dialog, $channel, $profile] = $this->resolveContext($dialog);
        $route = $this->resolveRoute($dialog, $profile, $channel);

        if (! $route instanceof Bitrix24OpenLineRoute) {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines route is not configured for channel #%d [%s] on current runtime profile `%s`.',
                $channel->id,
                $channel->platform,
                $profile->profile_key,
            ));
        }

        $this->assertRouteMatchesContext($route, $dialog, $profile, $channel);

        return $this->buildRouteData($route, $channel);
    }

    public function handleIncomingCallback(
        Dialog|int $dialog,
        string $connectorCode,
        string $lineId,
    ): Bitrix24OpenLinesRouteData {
        [$dialog, $channel, $profile] = $this->resolveContext($dialog);

        if ($connectorCode === '' || $lineId === '') {
            throw new Bitrix24OpenLinesRouteMismatchException(sprintf(
                'Bitrix24 Open Lines callback for dialog #%d must include connector and line.',
                $dialog->id,
            ));
        }

        $route = $this->resolveIncomingCallbackRoute($dialog, $profile, $channel, $connectorCode, $lineId);

        if (! $route instanceof Bitrix24OpenLineRoute) {
            throw new Bitrix24OpenLinesRouteMismatchException(sprintf(
                'Bitrix24 Open Lines callback for unpinned dialog #%d requires matching usable route [%s:%s].',
                $dialog->id,
                $connectorCode,
                $lineId,
            ));
        }

        $this->assertRouteMatchesContext($route, $dialog, $profile, $channel);

        return $this->buildRouteData($route, $channel);
    }

    /**
     * @return array{0: Dialog, 1: Channel, 2: Bitrix24Profile}
     */
    private function resolveContext(Dialog|int $dialog): array
    {
        $dialog = $dialog instanceof Dialog
            ? $dialog
            : Dialog::query()->with('channel')->findOrFail($dialog);

        $dialog->loadMissing('channel');

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines export requires a dialog channel.');
        }

        return [$dialog, $channel, $this->resolveCurrentProfileAction->handle()];
    }

    private function buildRouteData(Bitrix24OpenLineRoute $route, Channel $channel): Bitrix24OpenLinesRouteData
    {
        return new Bitrix24OpenLinesRouteData(
            platform: $channel->platform,
            connectorCode: (string) $route->connector_code,
            lineId: (string) $route->line_id,
            routeId: $route->id,
            status: $route->status,
            channelType: $route->channel_type,
        );
    }

    private function resolveRoute(Dialog $dialog, Bitrix24Profile $profile, Channel $channel): ?Bitrix24OpenLineRoute
    {
        if ($dialog->bitrix24_open_line_route_id !== null) {
            return $dialog->bitrix24OpenLineRoute()->first();
        }

        return Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channel->id)
            ->usable()
            ->first();
    }

    private function resolveIncomingCallbackRoute(
        Dialog $dialog,
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $lineId,
    ): ?Bitrix24OpenLineRoute {
        if ($dialog->bitrix24_open_line_route_id !== null) {
            return $dialog->bitrix24OpenLineRoute()->first();
        }

        $route = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channel->id)
            ->where('connector_code', $connectorCode)
            ->where('line_id', $lineId)
            ->usable()
            ->first();

        if ($route instanceof Bitrix24OpenLineRoute) {
            $dialog->forceFill([
                'bitrix24_open_line_route_id' => $route->id,
            ])->save();
        }

        return $route;
    }

    private function assertRouteMatchesContext(
        Bitrix24OpenLineRoute $route,
        Dialog $dialog,
        Bitrix24Profile $profile,
        Channel $channel,
    ): void {
        if ($route->bitrix24_profile_id !== $profile->id) {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines route #%d belongs to another runtime profile.',
                $route->id,
            ));
        }

        if ($route->channel_id !== $channel->id) {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines route #%d does not belong to dialog #%d channel.',
                $route->id,
                $dialog->id,
            ));
        }

        if (! $route->isUsable()) {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines route #%d has non-working status [%s].',
                $route->id,
                $route->status,
            ));
        }
    }
}
