<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CallbackIngressData;
use App\Models\Bitrix24Profile;
use Illuminate\Http\Request;

class ResolveBitrix24CallbackIngressAction
{
    public function __construct(
        private readonly NormalizeBitrix24CallbackBaseUrlAction $normalizeCallbackBaseUrl,
    ) {}

    public function handle(Request $request): Bitrix24CallbackIngressData
    {
        $callbackBaseUrl = $this->normalizeCallbackBaseUrl->handle($request->root());

        if ($callbackBaseUrl === null) {
            return new Bitrix24CallbackIngressData(
                callbackBaseUrl: null,
                profile: null,
            );
        }

        return new Bitrix24CallbackIngressData(
            callbackBaseUrl: $callbackBaseUrl,
            profile: Bitrix24Profile::query()
                ->where('callback_base_url', $callbackBaseUrl)
                ->first(),
        );
    }
}
