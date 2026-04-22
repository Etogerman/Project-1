<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;

class ResolveCurrentBitrix24ConnectionAction
{
    public function __construct(
        private readonly ResolveCurrentBitrix24ProfileAction $resolveCurrentProfile,
    ) {}

    public function handle(): Bitrix24Connection
    {
        $profile = $this->resolveCurrentProfile->handle();
        $connections = Bitrix24Connection::query()
            ->where('profile_id', $profile->id)
            ->where('status', Bitrix24Connection::STATUS_ACTIVE)
            ->get();

        if ($connections->isEmpty()) {
            throw new NoActiveBitrix24ConnectionException(sprintf(
                'No active Bitrix24 connection is configured for current runtime profile `%s`.',
                $profile->profile_key,
            ));
        }

        if ($connections->count() > 1) {
            throw new Bitrix24ConnectionStateException(sprintf(
                'Multiple active Bitrix24 connections are configured for current runtime profile `%s`.',
                $profile->profile_key,
            ));
        }

        return $connections->firstOrFail();
    }
}
