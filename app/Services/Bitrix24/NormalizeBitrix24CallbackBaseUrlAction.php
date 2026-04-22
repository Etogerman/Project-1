<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;

class NormalizeBitrix24CallbackBaseUrlAction
{
    public function handle(?string $value): ?string
    {
        return Bitrix24Profile::normalizeCallbackBaseUrl($value);
    }
}
