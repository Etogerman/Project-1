<?php

namespace App\Data\Bitrix24;

use App\Models\Bitrix24Profile;

final readonly class Bitrix24AdminOAuthStartData
{
    public function __construct(
        public Bitrix24Profile $profile,
        public string $authorizationUrl,
        public string $state,
    ) {}
}
