<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;

class BuildBitrix24OpenLinesRouteRegistryOwnerSnapshotAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Bitrix24Profile $profile, Bitrix24CallbackOwner $owner): array
    {
        $ownerKey = trim((string) $owner->owner_key);
        $callbackBaseUrl = trim((string) $owner->callback_base_url);

        if ($ownerKey === '' || $callbackBaseUrl === '') {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_owner_invalid',
                'Callback-владелец для публикации registry заполнен не полностью.',
            );
        }

        $connectors = [];
        $routes = [];
        $lineIds = [];

        if ($owner->isActive()) {
            Bitrix24OpenLineRoute::query()
                ->where('bitrix24_profile_id', $profile->id)
                ->where('callback_owner_id', $owner->id)
                ->whereIn('status', Bitrix24OpenLineRoute::usableStatuses())
                ->whereNotNull('connector_code')
                ->whereNotNull('line_id')
                ->orderBy('connector_code')
                ->orderBy('line_id')
                ->get()
                ->each(function (Bitrix24OpenLineRoute $route) use (&$connectors, &$routes, &$lineIds): void {
                    $connectorCode = trim((string) $route->connector_code);
                    $lineId = trim((string) $route->line_id);

                    if ($connectorCode === '' || $lineId === '') {
                        return;
                    }

                    $routeKey = $connectorCode.':'.$lineId;

                    if (array_key_exists($routeKey, $routes)) {
                        throw new Bitrix24OpenLinesRouteRegistryException(
                            'route_registry_duplicate_route_key',
                            'В локальной базе найден дублирующийся route key для OpenLines registry.',
                        );
                    }

                    if (array_key_exists($lineId, $lineIds)) {
                        throw new Bitrix24OpenLinesRouteRegistryException(
                            'route_registry_duplicate_line_id',
                            'В локальной базе найден дублирующийся line id для OpenLines registry.',
                        );
                    }

                    $connectorType = $this->connectorType((string) $route->channel_type);
                    $knownConnectorType = $connectors[$connectorCode]['connector_type'] ?? null;

                    if (is_string($knownConnectorType) && $knownConnectorType !== $connectorType) {
                        throw new Bitrix24OpenLinesRouteRegistryException(
                            'route_registry_connector_type_conflict',
                            'Один connector code связан с несовместимыми типами каналов.',
                        );
                    }

                    $connectors[$connectorCode] = [
                        'connector_code' => $connectorCode,
                        'connector_type' => $connectorType,
                    ];
                    $lineIds[$lineId] = true;

                    $routes[$routeKey] = [
                        'connector_code' => $connectorCode,
                        'line_id' => $lineId,
                        'line_name' => trim((string) ($route->line_name ?? '')),
                        'active' => true,
                    ];
                });
        }

        return [
            'schema_version' => 1,
            'portal_domain' => trim((string) $profile->portal_domain),
            'owner_profile_key' => $ownerKey,
            'owner_callback_base_url' => $callbackBaseUrl,
            'connectors' => $connectors,
            'routes' => $routes,
        ];
    }

    private function connectorType(string $channelType): string
    {
        $connectorType = Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType($channelType);

        if ($connectorType === null) {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_connector_type_invalid',
                'Тип канала не поддерживается OpenLines connector registry.',
            );
        }

        return $connectorType;
    }
}
