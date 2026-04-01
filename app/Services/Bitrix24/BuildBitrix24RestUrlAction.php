<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;

class BuildBitrix24RestUrlAction
{
    public function handle(Bitrix24Connection $connection, string $method): string
    {
        $clientEndpoint = trim((string) $connection->client_endpoint);

        if ($clientEndpoint === '') {
            throw new Bitrix24ConnectionStateException('Active Bitrix24 connection is missing client_endpoint.');
        }

        $normalizedMethod = trim($method);

        if ($normalizedMethod === '') {
            throw new Bitrix24ApiException('Bitrix24 REST method must not be empty.');
        }

        if (! str_ends_with($normalizedMethod, '.json')) {
            $normalizedMethod .= '.json';
        }

        return rtrim($clientEndpoint, '/').'/'.ltrim($normalizedMethod, '/');
    }
}
