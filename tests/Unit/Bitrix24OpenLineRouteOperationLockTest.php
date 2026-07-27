<?php

namespace Tests\Unit;

use App\Services\Bitrix24\Bitrix24OpenLineRouteOperationLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class Bitrix24OpenLineRouteOperationLockTest extends TestCase
{
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
        $fullRefreshBudgetSeconds = 2 * ((5 * 45) + (2 * 2.5));
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
}
