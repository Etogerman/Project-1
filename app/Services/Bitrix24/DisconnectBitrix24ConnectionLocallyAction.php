<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;

class DisconnectBitrix24ConnectionLocallyAction
{
    public function handle(Bitrix24Connection $connection): void
    {
        $connection->forceFill([
            'status' => Bitrix24Connection::STATUS_NEEDS_REINSTALL,
            'access_token_encrypted' => null,
            'refresh_token_encrypted' => null,
            'access_token_expires_at' => null,
            'last_error_at' => now(),
            'last_error_message' => 'Подключение отключено локально. Для работы нужно подключить заново.',
        ])->save();
    }
}
