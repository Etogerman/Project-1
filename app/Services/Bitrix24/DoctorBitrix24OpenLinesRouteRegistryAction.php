<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DoctorBitrix24OpenLinesRouteRegistryAction
{
    public function __construct(
        private readonly BuildBitrix24OpenLinesRouteRegistryOwnerSnapshotAction $buildOwnerSnapshotAction,
        private readonly Bitrix24OpenLinesRouteRegistryClient $client,
    ) {}

    /**
     * @return array{status:string,diff_count:int,diffs:list<string>,warning_count:int,warnings:list<string>,extra_owner_count:int,extra_owners:list<string>,checked_owners:int}
     */
    public function handle(Bitrix24Profile $profile): array
    {
        try {
            $snapshot = $this->client->snapshot($profile);
            $registry = Arr::get($snapshot, 'registry', []);
            $registry = is_array($registry) ? $registry : [];
            $transitionFallbackRouteKeys = $this->transitionFallbackRouteKeys($snapshot);
            $diffs = $this->diffs($profile, $registry, $transitionFallbackRouteKeys);
            $duplicateLineIds = $this->duplicateRemoteLineIds($registry);
            $diffs = array_merge($diffs, $this->duplicateLineDiffs($duplicateLineIds));
            $extraOwners = $this->extraRemoteOwners($profile, $registry);
            $warnings = $this->warnings($extraOwners);
            $status = $diffs === []
                ? Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED
                : Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF;
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $profile->forceFill([
                'openlines_route_registry_last_status' => Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED,
                'openlines_route_registry_last_error' => Str::limit($exception->errorCode, 512, ''),
                'openlines_route_registry_last_checked_at' => now(),
            ])->save();

            throw $exception;
        }

        $profile->forceFill([
            'openlines_route_registry_last_status' => $status,
            'openlines_route_registry_last_error' => $diffs === [] && $warnings === []
                ? null
                : Str::limit(implode('; ', array_merge($diffs, $warnings)), 512, ''),
            'openlines_route_registry_last_checked_at' => now(),
        ])->save();

        return [
            'status' => $status,
            'diff_count' => count($diffs),
            'diffs' => $diffs,
            'warning_count' => count($warnings),
            'warnings' => $warnings,
            'extra_owner_count' => count($extraOwners),
            'extra_owners' => $extraOwners,
            'checked_owners' => $profile->callbackOwners()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  list<string>  $transitionFallbackRouteKeys
     * @return list<string>
     */
    private function diffs(Bitrix24Profile $profile, array $registry, array $transitionFallbackRouteKeys): array
    {
        $diffs = [];
        $owners = is_array($registry['owners'] ?? null) ? $registry['owners'] : [];

        foreach ($profile->callbackOwners()->orderBy('owner_key')->get() as $owner) {
            if (! $owner instanceof Bitrix24CallbackOwner) {
                continue;
            }

            $expected = $this->buildOwnerSnapshotAction->handle($profile, $owner);
            $ownerKey = (string) $expected['owner_profile_key'];
            $actual = $owners[$ownerKey] ?? null;

            if (! is_array($actual)) {
                $diffs[] = "owner {$ownerKey}: missing";

                continue;
            }

            $expectedCallback = (string) $expected['owner_callback_base_url'];
            $actualCallback = trim((string) ($actual['owner_callback_base_url'] ?? ''));

            if ($actualCallback !== $expectedCallback) {
                $diffs[] = "owner {$ownerKey}: callback_url";
            }

            $actualRoutes = is_array($actual['routes'] ?? null) ? $actual['routes'] : [];

            if ($actualRoutes != $expected['routes']) {
                $diffs[] = "owner {$ownerKey}: routes";
            }
        }

        $diffs = array_merge($diffs, $this->fallbackOnlyDiffs($profile, $registry, $transitionFallbackRouteKeys));

        return $diffs;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function transitionFallbackRouteKeys(array $snapshot): array
    {
        $routeKeys = is_array($snapshot['transition_fallback_routes'] ?? null)
            ? $snapshot['transition_fallback_routes']
            : [];
        $normalized = [];

        foreach ($routeKeys as $routeKey) {
            $routeKey = trim((string) $routeKey);

            if ($routeKey !== '') {
                $normalized[$routeKey] = true;
            }
        }

        $routeKeys = array_keys($normalized);
        sort($routeKeys);

        return $routeKeys;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  list<string>  $transitionFallbackRouteKeys
     * @return list<string>
     */
    private function fallbackOnlyDiffs(Bitrix24Profile $profile, array $registry, array $transitionFallbackRouteKeys): array
    {
        if ($transitionFallbackRouteKeys === []) {
            return [];
        }

        $expectedRouteKeys = array_fill_keys($this->expectedRouteKeys($profile), true);
        $remoteActiveRouteKeys = array_fill_keys($this->remoteActiveRouteKeys($registry), true);
        $localKnownRouteKeys = array_fill_keys($this->localKnownRouteKeys($profile), true);
        $diffs = [];

        foreach ($transitionFallbackRouteKeys as $routeKey) {
            if ($routeKey === '*'
                || ! isset($localKnownRouteKeys[$routeKey])
                || isset($expectedRouteKeys[$routeKey])
                || isset($remoteActiveRouteKeys[$routeKey])) {
                continue;
            }

            $diffs[] = "fallback_only: {$routeKey}";
        }

        return $diffs;
    }

    /**
     * @return list<string>
     */
    private function expectedRouteKeys(Bitrix24Profile $profile): array
    {
        $routeKeys = [];

        foreach ($profile->callbackOwners()->orderBy('owner_key')->get() as $owner) {
            if (! $owner instanceof Bitrix24CallbackOwner) {
                continue;
            }

            $expected = $this->buildOwnerSnapshotAction->handle($profile, $owner);
            $routes = is_array($expected['routes'] ?? null) ? $expected['routes'] : [];

            foreach (array_keys($routes) as $routeKey) {
                $routeKeys[] = trim((string) $routeKey);
            }
        }

        $routeKeys = array_values(array_filter(array_unique($routeKeys), static fn (string $routeKey): bool => $routeKey !== ''));
        sort($routeKeys);

        return $routeKeys;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return list<string>
     */
    private function remoteActiveRouteKeys(array $registry): array
    {
        $owners = is_array($registry['owners'] ?? null) ? $registry['owners'] : [];
        $routeKeys = [];

        foreach ($owners as $owner) {
            if (! is_array($owner)) {
                continue;
            }

            $routes = is_array($owner['routes'] ?? null) ? $owner['routes'] : [];

            foreach ($routes as $routeKey => $route) {
                if (! is_array($route) || ($route['active'] ?? false) !== true) {
                    continue;
                }

                $routeKey = trim((string) $routeKey);

                if ($routeKey !== '') {
                    $routeKeys[] = $routeKey;
                }
            }
        }

        $routeKeys = array_values(array_unique($routeKeys));
        sort($routeKeys);

        return $routeKeys;
    }

    /**
     * @return list<string>
     */
    private function localKnownRouteKeys(Bitrix24Profile $profile): array
    {
        $routeKeys = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->whereNotNull('connector_code')
            ->whereNotNull('line_id')
            ->get()
            ->map(static function (Bitrix24OpenLineRoute $route): string {
                $connectorCode = trim((string) $route->connector_code);
                $lineId = trim((string) $route->line_id);

                return $connectorCode === '' || $lineId === '' ? '' : "{$connectorCode}:{$lineId}";
            })
            ->filter(static fn (string $routeKey): bool => $routeKey !== '')
            ->unique()
            ->values()
            ->all();

        sort($routeKeys);

        return $routeKeys;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return list<string>
     */
    private function duplicateRemoteLineIds(array $registry): array
    {
        $owners = is_array($registry['owners'] ?? null) ? $registry['owners'] : [];
        $seenLineIds = [];
        $duplicateLineIds = [];

        foreach ($owners as $owner) {
            if (! is_array($owner)) {
                continue;
            }

            $routes = is_array($owner['routes'] ?? null) ? $owner['routes'] : [];

            foreach ($routes as $route) {
                if (! is_array($route) || ($route['active'] ?? false) !== true) {
                    continue;
                }

                $lineId = trim((string) ($route['line_id'] ?? ''));

                if ($lineId === '') {
                    continue;
                }

                if (array_key_exists($lineId, $seenLineIds)) {
                    $duplicateLineIds[$lineId] = true;

                    continue;
                }

                $seenLineIds[$lineId] = true;
            }
        }

        $lineIds = array_keys($duplicateLineIds);
        sort($lineIds);

        return $lineIds;
    }

    /**
     * @param  list<string>  $duplicateLineIds
     * @return list<string>
     */
    private function duplicateLineDiffs(array $duplicateLineIds): array
    {
        if ($duplicateLineIds === []) {
            return [];
        }

        return array_map(
            static fn (string $lineId): string => "portal_audit_duplicate_line_id: {$lineId}",
            $duplicateLineIds,
        );
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return list<string>
     */
    private function extraRemoteOwners(Bitrix24Profile $profile, array $registry): array
    {
        $localOwnerKeys = $profile->callbackOwners()
            ->pluck('owner_key')
            ->map(fn (mixed $ownerKey): string => trim((string) $ownerKey))
            ->filter(static fn (string $ownerKey): bool => $ownerKey !== '')
            ->values()
            ->all();
        $owners = is_array($registry['owners'] ?? null) ? $registry['owners'] : [];
        $remoteOwnerKeys = array_values(array_filter(
            array_map(static fn (mixed $ownerKey): string => trim((string) $ownerKey), array_keys($owners)),
            static fn (string $ownerKey): bool => $ownerKey !== '',
        ));
        $extraOwners = array_values(array_diff($remoteOwnerKeys, $localOwnerKeys));

        sort($extraOwners);

        return $extraOwners;
    }

    /**
     * @param  list<string>  $extraOwners
     * @return list<string>
     */
    private function warnings(array $extraOwners): array
    {
        if ($extraOwners === []) {
            return [];
        }

        return [
            'portal_audit_extra_owners: '.implode(', ', $extraOwners),
        ];
    }
}
