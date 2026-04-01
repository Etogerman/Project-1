<?php

namespace App\Jobs;

use App\Models\Bitrix24Connection;
use App\Services\Bitrix24\RefreshBitrix24AccessTokenAction;
use App\Services\Bitrix24\ResolveActiveBitrix24ConnectionAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshBitrix24TokenJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $connectionId = null,
    ) {}

    public function handle(
        ResolveActiveBitrix24ConnectionAction $resolveActiveConnection,
        RefreshBitrix24AccessTokenAction $refreshAccessToken,
    ): void {
        $connection = $this->connectionId === null
            ? $resolveActiveConnection->handle()
            : Bitrix24Connection::query()->find($this->connectionId);

        if (! $connection) {
            return;
        }

        $refreshAccessToken->handle($connection);
    }
}
