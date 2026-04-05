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
        $auth = $this->caseInsensitiveValue($payload, 'auth');

        if (! is_array($auth)) {
            $auth = [];
        }

        return new Bitrix24AuthContextData(
            portalDomain: $this->nullableString($this->caseInsensitiveValue($auth, 'domain')),
            memberId: $this->nullableString($this->caseInsensitiveValue($auth, 'member_id')),
            applicationToken: $this->nullableString($this->caseInsensitiveValue($auth, 'application_token')),
            clientEndpoint: $this->nullableString($this->caseInsensitiveValue($auth, 'client_endpoint')),
            serverEndpoint: $this->nullableString($this->caseInsensitiveValue($auth, 'server_endpoint')),
            status: $this->nullableString($this->caseInsensitiveValue($auth, 'status')),
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

    private function caseInsensitiveValue(array $values, string $needle): mixed
    {
        $normalizedNeedle = mb_strtolower((string) $needle);

        foreach ($values as $key => $value) {
            if (mb_strtolower((string) $key) === $normalizedNeedle) {
                return $value;
            }
        }

        return null;
    }
}
