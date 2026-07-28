<?php

namespace App\Services\Bitrix24;

use RuntimeException;

class Bitrix24CallbackOwnerIdentityLockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Данные владения callback-владельца с маршрутами нельзя менять обычным сохранением. Сначала выполните отдельный переход владения.',
        );
    }
}
