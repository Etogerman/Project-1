<?php

namespace App\Services\Bitrix24;

class ShouldUpdateBitrix24ContactAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): bool
    {
        return $payload !== [];
    }
}
