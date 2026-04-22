<?php

namespace App\Data\Bitrix24;

use App\Models\Bitrix24Profile;

final readonly class Bitrix24CallbackIngressData
{
    public function __construct(
        public ?string $callbackBaseUrl,
        public ?Bitrix24Profile $profile,
    ) {}
}
