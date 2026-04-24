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

        $portalDomain = $this->nullableString(
            $this->caseInsensitiveValue($auth, 'domain')
            ?? $this->caseInsensitiveValue($payload, 'domain'),
        );

        return new Bitrix24AuthContextData(
            portalDomain: $portalDomain,
            memberId: $this->nullableString(
                $this->caseInsensitiveValue($auth, 'member_id')
                ?? $this->caseInsensitiveValue($payload, 'member_id'),
            ),
            applicationToken: $this->nullableString(
                $this->caseInsensitiveValue($auth, 'application_token')
                ?? $this->caseInsensitiveValue($payload, 'application_token')
                ?? $this->caseInsensitiveValue($payload, 'app_sid'),
            ),
            clientEndpoint: $this->nullableString(
                $this->caseInsensitiveValue($auth, 'client_endpoint')
                ?? $this->caseInsensitiveValue($payload, 'client_endpoint'),
            ) ?? $this->defaultClientEndpoint($portalDomain),
            serverEndpoint: $this->nullableString(
                $this->caseInsensitiveValue($auth, 'server_endpoint')
                ?? $this->caseInsensitiveValue($payload, 'server_endpoint'),
            ),
            status: $this->nullableString(
                $this->caseInsensitiveValue($auth, 'status')
                ?? $this->caseInsensitiveValue($payload, 'status'),
            ),
        );
    }

    private function defaultClientEndpoint(?string $portalDomain): ?string
    {
        if (! filled($portalDomain)) {
            return null;
        }

        return 'https://'.trim($portalDomain, "/ \t\n\r\0\x0B").'/rest/';
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
