<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;
use App\Models\Bitrix24WebhookEvent;

class ResolveCurrentBitrix24ProfileAction
{
    public function __construct(
        private readonly ResolveCurrentBitrix24CallbackBaseUrlAction $resolveCurrentCallbackBaseUrl,
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
    ) {}

    public function handle(): Bitrix24Profile
    {
        $callbackBaseUrl = $this->resolveCurrentCallbackBaseUrl->handle();
        $profiles = Bitrix24Profile::query()
            ->get();
        $matches = $profiles->filter(
            fn (Bitrix24Profile $profile): bool => $this->normalizeCallbackBaseUrl->handle($profile->callback_base_url) === $callbackBaseUrl,
        );

        if ($matches->isEmpty()) {
            throw new Bitrix24ConnectionStateException(sprintf(
                'Current runtime callback_base_url `%s` does not match a Bitrix24 profile registry entry.',
                $callbackBaseUrl,
            ));
        }

        if ($matches->count() > 1) {
            throw new Bitrix24ConnectionStateException(sprintf(
                'Current runtime callback_base_url `%s` matches multiple Bitrix24 profiles.',
                $callbackBaseUrl,
            ));
        }

        $profile = $matches->firstOrFail();

        if (! $profile->allowsCallbackType(Bitrix24WebhookEvent::TYPE_OPENLINES)) {
            throw new Bitrix24ConnectionStateException(sprintf(
                'Current runtime callback_base_url `%s` resolves to profile `%s`, but profile_type `%s` does not allow openlines runtime.',
                $callbackBaseUrl,
                $profile->profile_key,
                $profile->profile_type,
            ));
        }

        return $profile;
    }
}
