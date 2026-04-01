<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use Carbon\CarbonInterface;

class PersistBitrix24RefreshResultAction
{
    public function handleSuccess(
        Bitrix24Connection $connection,
        string $accessToken,
        string $refreshToken,
        ?CarbonInterface $accessTokenExpiresAt,
    ): Bitrix24Connection {
        $connection->forceFill([
            'access_token_encrypted' => $accessToken,
            'refresh_token_encrypted' => $refreshToken,
            'access_token_expires_at' => $accessTokenExpiresAt,
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'last_refreshed_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ])->save();

        return $connection->refresh();
    }

    public function handleFailure(Bitrix24Connection $connection, string $status, string $message): Bitrix24Connection
    {
        $connection->forceFill([
            'status' => $status,
            'last_error_at' => now(),
            'last_error_message' => $message,
        ])->save();

        return $connection->refresh();
    }
}
