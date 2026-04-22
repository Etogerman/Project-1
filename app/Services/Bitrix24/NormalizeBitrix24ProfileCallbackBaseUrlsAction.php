<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;

class NormalizeBitrix24ProfileCallbackBaseUrlsAction
{
    public function __construct(
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
    ) {}

    public function handle(): void
    {
        $profiles = Bitrix24Profile::query()
            ->orderBy('id')
            ->get();
        $normalizedValues = [];
        $owners = [];

        foreach ($profiles as $profile) {
            $normalized = $this->normalizeCallbackBaseUrl->handle($profile->callback_base_url);

            if ($normalized === null) {
                throw new Bitrix24ConnectionStateException(sprintf(
                    'Bitrix24 profile `%s` has invalid callback_base_url `%s`.',
                    $profile->profile_key,
                    $profile->callback_base_url,
                ));
            }

            $existingOwner = $owners[$normalized] ?? null;

            if ($existingOwner !== null && $existingOwner !== $profile->id) {
                /** @var Bitrix24Profile $duplicate */
                $duplicate = $profiles->firstWhere('id', $existingOwner);

                throw new Bitrix24ConnectionStateException(sprintf(
                    'Bitrix24 profiles `%s` and `%s` normalize to the same callback_base_url `%s`.',
                    $duplicate->profile_key,
                    $profile->profile_key,
                    $normalized,
                ));
            }

            $owners[$normalized] = $profile->id;
            $normalizedValues[$profile->id] = $normalized;
        }

        foreach ($profiles as $profile) {
            $normalized = $normalizedValues[$profile->id] ?? null;

            if (! is_string($normalized) || $normalized === $profile->callback_base_url) {
                continue;
            }

            Bitrix24Profile::query()
                ->whereKey($profile->id)
                ->update([
                    'callback_base_url' => $normalized,
                    'updated_at' => now(),
                ]);
        }
    }
}
