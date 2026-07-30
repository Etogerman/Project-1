<?php

namespace Tests\Unit;

use App\Services\Bitrix24\Bitrix24OpenLineRouteOperationLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class Bitrix24OpenLineRouteOperationLockTest extends TestCase
{
    public function test_same_line_lock_serializes_different_route_locks(): void
    {
        Cache::flush();

        $profileId = 993;
        $firstChannelId = 994;
        $secondChannelId = 995;
        $nestedAttemptException = null;
        $routeOperationLock = app(Bitrix24OpenLineRouteOperationLock::class);

        try {
            $result = $routeOperationLock->run(
                $profileId,
                $firstChannelId,
                function () use (
                    $routeOperationLock,
                    $profileId,
                    $secondChannelId,
                    &$nestedAttemptException,
                ): string {
                    return $routeOperationLock->runForLine(
                        ' StageCRM.FVDS.RU ',
                        ' 14 ',
                        function () use (
                            $routeOperationLock,
                            $profileId,
                            $secondChannelId,
                            &$nestedAttemptException,
                        ): string {
                            try {
                                $routeOperationLock->run(
                                    $profileId,
                                    $secondChannelId,
                                    fn (): string => $routeOperationLock->runForLine(
                                        'stagecrm.fvds.ru',
                                        '14',
                                        fn (): string => 'overlap',
                                    ),
                                );
                            } catch (LockTimeoutException $exception) {
                                $nestedAttemptException = $exception;
                            }

                            return 'completed';
                        },
                    );
                },
            );

            $this->assertSame('completed', $result);
            $this->assertInstanceOf(LockTimeoutException::class, $nestedAttemptException);
            $this->assertSame(
                Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE,
                $nestedAttemptException->getMessage(),
            );
            $this->assertSame(
                'reacquired',
                $routeOperationLock->run(
                    $profileId,
                    $secondChannelId,
                    fn (): string => $routeOperationLock->runForLine(
                        'stagecrm.fvds.ru',
                        '14',
                        fn (): string => 'reacquired',
                    ),
                ),
            );
        } finally {
            Cache::flush();
        }
    }

    public function test_line_aliases_share_one_canonical_lock(): void
    {
        Cache::flush();

        $routeOperationLock = app(Bitrix24OpenLineRouteOperationLock::class);
        $nestedAttemptException = null;

        try {
            $routeOperationLock->runForLine(
                'stagecrm.fvds.ru',
                '0014',
                function () use ($routeOperationLock, &$nestedAttemptException): void {
                    try {
                        $routeOperationLock->runForLine(
                            'stagecrm.fvds.ru',
                            '14',
                            static fn (): string => 'overlap',
                        );
                    } catch (LockTimeoutException $exception) {
                        $nestedAttemptException = $exception;
                    }
                },
            );

            $this->assertInstanceOf(LockTimeoutException::class, $nestedAttemptException);
            $this->assertSame(
                Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE,
                $nestedAttemptException->getMessage(),
            );
        } finally {
            Cache::flush();
        }
    }

    public function test_lock_stays_owned_for_full_configured_refresh_budget(): void
    {
        config()->set([
            'bitrix24.http.timeout_seconds' => 30,
            'bitrix24.http.connect_timeout_seconds' => 45,
            'bitrix24.http.retry_sleep_milliseconds' => 2500,
        ]);
        Cache::flush();

        $profileId = 991;
        $channelId = 992;
        $fullRefreshBudgetSeconds = 3 * ((5 * 45) + (2 * 2.5));
        $nestedAttemptException = null;

        try {
            $result = app(Bitrix24OpenLineRouteOperationLock::class)->run(
                $profileId,
                $channelId,
                function () use (
                    $profileId,
                    $channelId,
                    $fullRefreshBudgetSeconds,
                    &$nestedAttemptException,
                ): string {
                    $this->travel((int) $fullRefreshBudgetSeconds)->seconds();

                    try {
                        app(Bitrix24OpenLineRouteOperationLock::class)->run(
                            $profileId,
                            $channelId,
                            fn (): string => 'overlap',
                        );
                    } catch (LockTimeoutException $exception) {
                        $nestedAttemptException = $exception;
                    }

                    return 'completed';
                },
            );

            $this->assertSame('completed', $result);
            $this->assertInstanceOf(LockTimeoutException::class, $nestedAttemptException);
            $this->assertSame(
                Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE,
                $nestedAttemptException->getMessage(),
            );
            $this->assertSame(
                'reacquired',
                app(Bitrix24OpenLineRouteOperationLock::class)->run(
                    $profileId,
                    $channelId,
                    fn (): string => 'reacquired',
                ),
            );
        } finally {
            $this->travelBack();
            Cache::flush();
        }
    }

    public function test_lease_bound_database_transaction_applies_and_restores_timeouts(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The production database timeout contract is PostgreSQL-specific.');
        }

        $connection = DB::connection();
        $previousLockTimeout = (string) $connection->scalar(
            "select current_setting('lock_timeout')",
        );
        $previousStatementTimeout = (string) $connection->scalar(
            "select current_setting('statement_timeout')",
        );

        $result = app(Bitrix24OpenLineRouteOperationLock::class)
            ->runDatabaseTransaction(function () use ($connection): string {
                $this->assertSame(
                    '5s',
                    (string) $connection->scalar("select current_setting('lock_timeout')"),
                );
                $this->assertSame(
                    '15s',
                    (string) $connection->scalar("select current_setting('statement_timeout')"),
                );

                return 'completed';
            });

        $this->assertSame('completed', $result);
        $this->assertSame(
            $previousLockTimeout,
            (string) $connection->scalar("select current_setting('lock_timeout')"),
        );
        $this->assertSame(
            $previousStatementTimeout,
            (string) $connection->scalar("select current_setting('statement_timeout')"),
        );
    }

    public function test_postgresql_lock_and_statement_timeouts_fail_as_busy_route(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The production database timeout contract is PostgreSQL-specific.');
        }

        foreach (['55P03', '57014'] as $sqlState) {
            $previous = new PDOException('PostgreSQL operation exceeded its bounded wait.');
            $previous->errorInfo = [$sqlState];

            try {
                app(Bitrix24OpenLineRouteOperationLock::class)
                    ->runDatabaseTransaction(
                        fn (): never => throw new QueryException(
                            'pgsql',
                            'select route for update',
                            [],
                            $previous,
                        ),
                        attempts: 1,
                    );

                $this->fail("SQLSTATE {$sqlState} was not translated to a busy route.");
            } catch (LockTimeoutException $exception) {
                $this->assertSame(
                    Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE,
                    $exception->getMessage(),
                );
                $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
            }
        }
    }
}
