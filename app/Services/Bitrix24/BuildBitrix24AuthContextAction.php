<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24AuthContextData;

class BuildBitrix24AuthContextAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): Bitrix24AuthContextData
    {
        $auth = $payload['auth'] ?? [];

        if (! is_array($auth)) {
            $auth = [];
        }

        return new Bitrix24AuthContextData(
            portalDomain: $this->nullableString($auth['domain'] ?? null),
            memberId: $this->nullableString($auth['member_id'] ?? null),
            applicationToken: $this->nullableString($auth['application_token'] ?? null),
            clientEndpoint: $this->nullableString($auth['client_endpoint'] ?? null),
            serverEndpoint: $this->nullableString($auth['server_endpoint'] ?? null),
            status: $this->nullableString($auth['status'] ?? null),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
