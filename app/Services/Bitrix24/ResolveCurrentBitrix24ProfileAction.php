<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;

class ResolveCurrentBitrix24ProfileAction
{
    public function __construct(
        private readonly ResolveCurrentBitrix24CallbackBaseUrlAction $resolveCurrentCallbackBaseUrl,
    ) {}

    public function handle(): Bitrix24Profile
    {
        $callbackBaseUrl = $this->resolveCurrentCallbackBaseUrl->handle();
        $profiles = Bitrix24Profile::query()
            ->where('callback_base_url', $callbackBaseUrl)
            ->get();

        if ($profiles->isEmpty()) {
            throw new Bitrix24ConnectionStateException(sprintf(
                'Current runtime callback_base_url `%s` does not match a Bitrix24 profile registry entry.',
                $callbackBaseUrl,
            ));
        }

        if ($profiles->count() > 1) {
            throw new Bitrix24ConnectionStateException(sprintf(
                'Current runtime callback_base_url `%s` matches multiple Bitrix24 profiles.',
                $callbackBaseUrl,
            ));
        }

        return $profiles->firstOrFail();
    }
}
