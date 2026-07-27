<?php

namespace App\Services\Bitrix24;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class Bitrix24OpenLineRouteOperationLock
{
    public const BUSY_MESSAGE = 'Маршрут ОЛ уже изменяется или обновляется. Повторите попытку после завершения текущей операции.';

    private const MINIMUM_LOCK_SECONDS = 180;

    private const REMOTE_OPERATION_COUNT = 4;

    private const REST_ROUNDS_PER_OPERATION = 2;

    private const REST_ATTEMPTS_PER_ROUND = 2;

    private const TOKEN_REFRESH_REQUESTS_PER_OPERATION = 1;

    private const RETRY_SLEEPS_PER_REST_ROUND = 1;

    private const SAFETY_MARGIN_MULTIPLIER = 1.25;

    public function run(int $profileId, int $channelId, Closure $callback): mixed
    {
        $lock = Cache::lock(
            sprintf('bitrix24-open-line-route-operation:%d:%d', $profileId, $channelId),
            $this->lockSeconds(),
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

    private function lockSeconds(): int
    {
        $requestBudgetSeconds = max(
            1,
            (int) config('bitrix24.http.timeout_seconds', 15),
            (int) config('bitrix24.http.connect_timeout_seconds', 5),
        );
        $retrySleepSeconds = max(
            0,
            (int) config('bitrix24.http.retry_sleep_milliseconds', 200),
        ) / 1000;

        $requestCount = self::REMOTE_OPERATION_COUNT * (
            (self::REST_ROUNDS_PER_OPERATION * self::REST_ATTEMPTS_PER_ROUND)
            + self::TOKEN_REFRESH_REQUESTS_PER_OPERATION
        );
        $retrySleepCount = self::REMOTE_OPERATION_COUNT
            * self::REST_ROUNDS_PER_OPERATION
            * self::RETRY_SLEEPS_PER_REST_ROUND;
        $remoteBudgetSeconds = ($requestCount * $requestBudgetSeconds)
            + ($retrySleepCount * $retrySleepSeconds);

        return max(
            self::MINIMUM_LOCK_SECONDS,
            (int) ceil($remoteBudgetSeconds * self::SAFETY_MARGIN_MULTIPLIER),
        );
    }
}
