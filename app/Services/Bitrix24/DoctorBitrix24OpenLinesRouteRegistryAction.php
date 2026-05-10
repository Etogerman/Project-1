<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
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
            $diffs = $this->diffs($profile, $registry);
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
     * @return list<string>
     */
    private function diffs(Bitrix24Profile $profile, array $registry): array
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

        return $diffs;
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
