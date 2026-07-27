<?php

namespace App\Services\Bitrix24;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class Bitrix24OpenLineRouteOperationLock
{
    public const BUSY_MESSAGE = 'Маршрут ОЛ уже изменяется или обновляется. Повторите попытку после завершения текущей операции.';

    private const LOCK_SECONDS = 180;

    public function run(int $profileId, int $channelId, Closure $callback): mixed
    {
        $lock = Cache::lock(
            sprintf('bitrix24-open-line-route-operation:%d:%d', $profileId, $channelId),
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            throw new LockTimeoutException(self::BUSY_MESSAGE);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
