<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;

class ResolveActiveBitrix24ConnectionAction
{
    public function handle(): Bitrix24Connection
    {
        $connections = Bitrix24Connection::query()
            ->where('status', Bitrix24Connection::STATUS_ACTIVE)
            ->get();

        if ($connections->isEmpty()) {
            throw new NoActiveBitrix24ConnectionException('No active Bitrix24 connection is configured.');
        }

        if ($connections->count() > 1) {
            throw new Bitrix24ConnectionStateException('Multiple active Bitrix24 connections are configured.');
        }

        return $connections->firstOrFail();
    }
}
