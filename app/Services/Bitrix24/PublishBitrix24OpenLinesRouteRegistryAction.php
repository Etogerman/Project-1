<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Profile;
use Illuminate\Support\Str;

class PublishBitrix24OpenLinesRouteRegistryAction
{
    public function __construct(
        private readonly BuildBitrix24OpenLinesRouteRegistryOwnerSnapshotAction $buildOwnerSnapshotAction,
        private readonly Bitrix24OpenLinesRouteRegistryClient $client,
    ) {}

    /**
     * @return array{published_owners:int,published_routes:int,owners:list<array{owner_profile_key:string,published_routes:int}>}
     */
    public function handle(Bitrix24Profile $profile): array
    {
        $owners = $profile->callbackOwners()
            ->orderBy('owner_key')
            ->get();

        if ($owners->isEmpty()) {
            $this->markFailed($profile, 'route_registry_owner_missing');

            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_owner_missing',
                'У профиля нет callback-владельцев для публикации OpenLines registry.',
            );
        }

        $publishedOwners = [];
        $publishedRoutes = 0;

        try {
            $snapshots = [];

            foreach ($owners as $owner) {
                if (! $owner instanceof Bitrix24CallbackOwner) {
                    continue;
                }

                $snapshots[] = $this->buildOwnerSnapshotAction->handle($profile, $owner);
            }

            $this->ensureUniqueLineIds($snapshots);
            $this->ensureCompatibleConnectorTypes($snapshots);

            foreach ($snapshots as $snapshot) {
                $response = $this->client->publish($profile, $snapshot);
                $routeCount = (int) ($response['published_routes'] ?? count($snapshot['routes'] ?? []));

                $publishedOwners[] = [
                    'owner_profile_key' => (string) $snapshot['owner_profile_key'],
                    'published_routes' => $routeCount,
                ];
                $publishedRoutes += $routeCount;
            }
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->markFailed($profile, $exception->errorCode);

            throw $exception;
        }

        $profile->forceFill([
            'openlines_route_registry_last_status' => Bitrix24Profile::ROUTE_REGISTRY_STATUS_PUBLISHED,
            'openlines_route_registry_last_error' => null,
            'openlines_route_registry_last_checked_at' => now(),
            'openlines_route_registry_last_published_at' => now(),
        ])->save();

        return [
            'published_owners' => count($publishedOwners),
            'published_routes' => $publishedRoutes,
            'owners' => $publishedOwners,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     */
    private function ensureUniqueLineIds(array $snapshots): void
    {
        $lineIds = [];

        foreach ($snapshots as $snapshot) {
            $routes = is_array($snapshot['routes'] ?? null) ? $snapshot['routes'] : [];

            foreach ($routes as $route) {
                if (! is_array($route)) {
                    continue;
                }

                $lineId = trim((string) ($route['line_id'] ?? ''));

                if ($lineId === '') {
                    continue;
                }

                if (array_key_exists($lineId, $lineIds)) {
                    throw new Bitrix24OpenLinesRouteRegistryException(
                        'route_registry_duplicate_line_id',
                        'В локальной базе найден дублирующийся line id для OpenLines registry.',
                    );
                }

                $lineIds[$lineId] = true;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     */
    private function ensureCompatibleConnectorTypes(array $snapshots): void
    {
        $connectorTypes = [];

        foreach ($snapshots as $snapshot) {
            $connectors = is_array($snapshot['connectors'] ?? null) ? $snapshot['connectors'] : [];

            foreach ($connectors as $connector) {
                if (! is_array($connector)) {
                    continue;
                }

                $connectorCode = trim((string) ($connector['connector_code'] ?? ''));
                $connectorType = trim((string) ($connector['connector_type'] ?? ''));

                if ($connectorCode !== ''
                    && isset($connectorTypes[$connectorCode])
                    && $connectorTypes[$connectorCode] !== $connectorType
                ) {
                    throw new Bitrix24OpenLinesRouteRegistryException(
                        'route_registry_connector_type_conflict',
                        'Один connector code связан с несовместимыми типами каналов.',
                    );
                }

                if ($connectorCode !== '') {
                    $connectorTypes[$connectorCode] = $connectorType;
                }
            }
        }
    }

    private function markFailed(Bitrix24Profile $profile, string $errorCode): void
    {
        $profile->forceFill([
            'openlines_route_registry_last_status' => Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED,
            'openlines_route_registry_last_error' => Str::limit($errorCode, 512, ''),
            'openlines_route_registry_last_checked_at' => now(),
        ])->save();
    }
}
