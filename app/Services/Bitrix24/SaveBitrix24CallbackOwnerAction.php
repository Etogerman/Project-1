<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use Illuminate\Support\Facades\DB;

class SaveBitrix24CallbackOwnerAction
{
    public function __construct(
        private readonly Bitrix24OpenLinesRouteRegistrySnapshotLock $snapshotLock,
    ) {}

    /**
     * @param  array{owner_key:string,display_name:?string,callback_base_url:string,status:string}  $values
     */
    public function update(
        Bitrix24Profile $profile,
        int $ownerId,
        array $values,
    ): ?Bitrix24CallbackOwner {
        return $this->snapshotLock->run(
            fn (): ?Bitrix24CallbackOwner => DB::transaction(
                function () use ($profile, $ownerId, $values): ?Bitrix24CallbackOwner {
                    $owner = Bitrix24CallbackOwner::query()
                        ->where('bitrix24_profile_id', $profile->id)
                        ->whereKey($ownerId)
                        ->lockForUpdate()
                        ->first();

                    if (! $owner instanceof Bitrix24CallbackOwner) {
                        return null;
                    }

                    return $this->saveLocked($owner, $values);
                },
                attempts: 3,
            ),
        );
    }

    /**
     * @param  array{display_name:?string,callback_base_url:string,status:string}  $values
     */
    public function updateOrCreate(
        Bitrix24Profile $profile,
        string $ownerKey,
        array $values,
    ): Bitrix24CallbackOwner {
        return $this->snapshotLock->run(
            fn (): Bitrix24CallbackOwner => DB::transaction(
                function () use ($profile, $ownerKey, $values): Bitrix24CallbackOwner {
                    $owner = Bitrix24CallbackOwner::query()
                        ->where('bitrix24_profile_id', $profile->id)
                        ->where('owner_key', $ownerKey)
                        ->lockForUpdate()
                        ->first();
                    $attributes = ['owner_key' => $ownerKey] + $values;

                    if (! $owner instanceof Bitrix24CallbackOwner) {
                        Bitrix24CallbackOwner::query()->createOrFirst(
                            [
                                'bitrix24_profile_id' => $profile->id,
                                'owner_key' => $ownerKey,
                            ],
                            $values,
                        );

                        $owner = Bitrix24CallbackOwner::query()
                            ->where('bitrix24_profile_id', $profile->id)
                            ->where('owner_key', $ownerKey)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    return $this->saveLocked($owner, $attributes);
                },
                attempts: 3,
            ),
        );
    }

    /**
     * @param  array{owner_key:string,display_name:?string,callback_base_url:string,status:string}  $values
     */
    private function saveLocked(
        Bitrix24CallbackOwner $owner,
        array $values,
    ): Bitrix24CallbackOwner {
        $identityChanges = trim((string) $owner->owner_key) !== trim($values['owner_key'])
            || trim((string) $owner->callback_base_url) !== trim($values['callback_base_url'])
            || trim((string) $owner->status) !== trim($values['status']);
        $hasLineClaims = $owner->openLineRoutes()
            ->whereIn('status', Bitrix24OpenLineRoute::claimingStatuses())
            ->whereNotNull('connector_code')
            ->where('connector_code', '!=', '')
            ->whereNotNull('line_id')
            ->where('line_id', '!=', '')
            ->exists();

        if ($identityChanges && $hasLineClaims) {
            throw new Bitrix24CallbackOwnerIdentityLockedException;
        }

        $owner->fill($values)->save();

        return $owner;
    }
}
