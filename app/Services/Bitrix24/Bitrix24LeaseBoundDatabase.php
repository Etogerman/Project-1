<?php

namespace App\Services\Bitrix24;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class Bitrix24LeaseBoundDatabase
{
    public function run(
        Bitrix24OpenLineRouteLeaseDeadline $deadline,
        Closure $callback,
        int $attempts = 1,
    ): mixed {
        $connection = DB::connection();

        return $connection->transaction(
            fn (): mixed => $this->runOnConnection($connection, $deadline, $callback),
            $attempts,
        );
    }

    public function runOnConnection(
        Connection $connection,
        Bitrix24OpenLineRouteLeaseDeadline $deadline,
        Closure $callback,
    ): mixed {
        $deadline->assertAvailableFor(1);

        if ($connection->getDriverName() !== 'pgsql') {
            $result = $callback();
            $deadline->assertAvailableFor(0);

            return $result;
        }

        $lockTimeout = $deadline->boundedDatabaseTimeoutSeconds(5);
        $statementTimeout = $deadline->boundedDatabaseTimeoutSeconds(15);

        try {
            $connection->select(
                "select set_config('lock_timeout', ?, true)",
                [$lockTimeout.'s'],
            );
            $connection->select(
                "select set_config('statement_timeout', ?, true)",
                [$statementTimeout.'s'],
            );
            $result = $callback();
            $deadline->assertAvailableFor(0);

            return $result;
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (in_array($sqlState, ['55P03', '57014'], true)) {
                throw new LockTimeoutException(
                    Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE,
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }
}
