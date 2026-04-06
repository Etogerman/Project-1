<?php

namespace App\Services\Bitrix24;

class HashBitrix24ApplicationTokenAction
{
    public function handle(mixed $token): ?string
    {
        if (! is_scalar($token)) {
            return null;
        }

        $normalized = trim((string) $token);

        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
