<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;

class ShouldRefreshBitrix24TokenAction
{
    public function handle(Bitrix24Connection $connection, ?Bitrix24RestResponseData $response = null): bool
    {
        if (! filled($connection->access_token_encrypted)) {
            return true;
        }

        if ($connection->access_token_expires_at === null || $connection->access_token_expires_at->lte(now())) {
            return true;
        }

        if ($response === null) {
            return false;
        }

        if ($response->httpStatus === 401) {
            return true;
        }

        $errorCode = strtolower((string) $response->errorCode);
        $errorMessage = strtolower((string) $response->errorMessage);

        if ($response->httpStatus === 403 && $this->mentionsExpiredOrInvalidToken($errorCode.' '.$errorMessage)) {
            return true;
        }

        return in_array($errorCode, [
            'expired_token',
            'invalid_token',
            'no_auth_found',
        ], true) || $this->mentionsExpiredOrInvalidToken($errorMessage);
    }

    private function mentionsExpiredOrInvalidToken(string $value): bool
    {
        return str_contains($value, 'expired token')
            || str_contains($value, 'invalid token')
            || str_contains($value, 'expired auth')
            || str_contains($value, 'access denied due to invalid token')
            || str_contains($value, 'token has expired');
    }
}
