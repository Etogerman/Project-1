<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

final class Bitrix24OpenLineRouteOperationLock
{
    public const BUSY_MESSAGE = 'Маршрут ОЛ уже изменяется или обновляется. Повторите попытку после завершения текущей операции.';

    private const STATE_TRANSITION_CONNECTION = 'bitrix24_route_state_transition';

    private const MINIMUM_LOCK_SECONDS = 180;

    private const REMOTE_OPERATION_COUNT = 3;

    private const REST_ROUNDS_PER_OPERATION = 2;

    private const REST_ATTEMPTS_PER_ROUND = 2;

    private const TOKEN_REFRESH_REQUESTS_PER_OPERATION = 1;

    private const RETRY_SLEEPS_PER_REST_ROUND = 1;

    private const SAFETY_MARGIN_MULTIPLIER = 1.25;

    public function __construct(
        private readonly Bitrix24OpenLineRouteOwnershipLease $ownershipLease,
    ) {}

    public function run(int $profileId, int $channelId, Closure $callback): mixed
    {
        return $this->runLock(
            sprintf('bitrix24-open-line-route-operation:%d:%d', $profileId, $channelId),
            $callback,
        );
    }

    /**
     * Call while the corresponding route lock is held so route and line
     * operations always acquire locks in the same order.
     */
    public function runForLine(string $portalDomain, string $lineId, Closure $callback): mixed
    {
        $normalizedPortalDomain = mb_strtolower(trim($portalDomain));
        $normalizedLineId = trim($lineId);

        if ($normalizedPortalDomain === '' || $normalizedLineId === '') {
            return $callback();
        }

        return $this->runLock(
            'bitrix24-open-line-resource-operation:'.hash(
                'sha256',
                $normalizedPortalDomain."\0".$normalizedLineId,
            ),
            $callback,
        );
    }

    /**
     * The local line lock preserves in-process lock ordering. The signed
     * registry lease is the authority shared by independent DB/cache contours.
     */
    public function runForOwnedLine(
        Bitrix24Profile $profile,
        Bitrix24CallbackOwner $owner,
        string $connectorCode,
        string $connectorType,
        string $lineId,
        Closure $callback,
    ): mixed {
        return $this->runForLine(
            (string) $profile->portal_domain,
            $lineId,
            fn (): mixed => $this->ownershipLease->run(
                $profile,
                $owner,
                $connectorCode,
                $connectorType,
                $lineId,
                $this->lockSeconds(),
                $callback,
            ),
        );
    }

    /**
     * Keep the callback database-only so the row lock is never held during external I/O.
     */
    public function runShortStateTransition(int $routeId, Closure $callback): mixed
    {
        $connectionName = $this->stateTransitionConnectionName();
        $connection = DB::connection($connectionName);

        return $connection->transaction(function () use ($connectionName, $routeId, $callback): mixed {
            $route = (new Bitrix24OpenLineRoute)
                ->setConnection($connectionName)
                ->newQuery()
                ->select('bitrix24_open_line_routes.*')
                ->selectRaw('bitrix24_open_line_routes.xmin::text as state_version')
                ->whereKey($routeId)
                ->lockForUpdate()
                ->first();

            return $callback($route);
        });
    }

    private function stateTransitionConnectionName(): string
    {
        $defaultConnection = (string) config('database.default');

        if (DB::connection($defaultConnection)->transactionLevel() > 0) {
            return $defaultConnection;
        }

        if (is_array(config('database.connections.'.self::STATE_TRANSITION_CONNECTION))) {
            return self::STATE_TRANSITION_CONNECTION;
        }

        $defaultConfiguration = config('database.connections.'.$defaultConnection);

        if (! is_array($defaultConfiguration)) {
            throw new LogicException('Default database connection is not configured.');
        }

        config([
            'database.connections.'.self::STATE_TRANSITION_CONNECTION => $defaultConfiguration,
        ]);

        return self::STATE_TRANSITION_CONNECTION;
    }

    private function runLock(string $key, Closure $callback): mixed
    {
        $lock = Cache::lock($key, $this->lockSeconds());

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
