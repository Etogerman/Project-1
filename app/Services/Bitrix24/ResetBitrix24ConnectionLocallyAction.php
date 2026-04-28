<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;

class ResetBitrix24ConnectionLocallyAction
{
    public function handle(Bitrix24Connection $connection): void
    {
        $connection->delete();
    }
}
