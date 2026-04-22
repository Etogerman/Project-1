<?php

namespace App\Jobs;

use App\Models\Bitrix24Connection;
use App\Services\Bitrix24\RefreshBitrix24AccessTokenAction;
use App\Services\Bitrix24\ResolveCurrentBitrix24ConnectionAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshBitrix24TokenJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public readonly ?int $connectionId = null,
    ) {}

    public function handle(
        ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnection,
        RefreshBitrix24AccessTokenAction $refreshAccessToken,
    ): void {
        $connection = $this->connectionId === null
            ? $resolveCurrentConnection->handle()
            : Bitrix24Connection::query()->find($this->connectionId);

        if (! $connection) {
            return;
        }

        $refreshAccessToken->handle($connection);
    }
}
