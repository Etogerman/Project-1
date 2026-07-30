<?php

namespace App\Services\Bitrix24;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;

final class Bitrix24OpenLinesRouteRegistrySnapshotLock
{
    public const BUSY_MESSAGE = 'Источники OpenLines registry уже изменяются или публикуются. Повторите попытку после завершения текущей операции.';

    private const CACHE_KEY = 'bitrix24-open-lines-route-registry-snapshot';

    private const LOCK_SECONDS = 600;

    private const ADVISORY_NAMESPACE = 'ab-connector';

    private const ADVISORY_RESOURCE = 'bitrix24-open-lines-route-registry-snapshot';

    private int $depth = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function run(Closure $callback): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;

            try {
                return $callback();
            } finally {
                $this->depth--;
            }
        }

        return $this->connection->getDriverName() === 'pgsql'
            ? $this->runWithPostgresAdvisoryLock($callback)
            : $this->runWithCacheLock($callback);
    }

    private function runWithPostgresAdvisoryLock(Closure $callback): mixed
    {
        $result = $this->connection->selectOne(
            'select pg_try_advisory_lock(hashtext(?), hashtext(?)) as acquired',
            [self::ADVISORY_NAMESPACE, self::ADVISORY_RESOURCE],
            false,
        );

        if (! $this->postgresBoolean($result?->acquired ?? false)) {
            throw new LockTimeoutException(self::BUSY_MESSAGE);
        }

        $this->depth = 1;

        try {
            return $callback();
        } finally {
            $this->depth = 0;
            $this->connection->selectOne(
                'select pg_advisory_unlock(hashtext(?), hashtext(?)) as released',
                [self::ADVISORY_NAMESPACE, self::ADVISORY_RESOURCE],
                false,
            );
        }
    }

    private function runWithCacheLock(Closure $callback): mixed
    {
        $lock = Cache::lock(self::CACHE_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new LockTimeoutException(self::BUSY_MESSAGE);
        }

        $this->depth = 1;

        try {
            return $callback();
        } finally {
            $this->depth = 0;
            $lock->release();
        }
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }
}
