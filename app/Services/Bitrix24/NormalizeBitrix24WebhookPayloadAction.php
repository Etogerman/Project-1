<?php

namespace App\Services\Bitrix24;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class NormalizeBitrix24WebhookPayloadAction
{
    /**
     * @return array{
     *     payload: array<string, mixed>,
     *     headers: array<string, string>,
     *     query: array<string, mixed>,
     *     event_name: string,
     *     looks_like_bitrix: bool
     * }
     */
    public function handle(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        /** @var array<string, mixed> $query */
        $query = $request->query();

        $sanitizedPayload = $this->sanitizeArray($payload);
        $sanitizedQuery = $this->sanitizeArray($query);

        $eventName = $this->extractEventName($sanitizedPayload, $sanitizedQuery);

        return [
            'payload' => $sanitizedPayload,
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'query' => $sanitizedQuery,
            'event_name' => $eventName,
            'looks_like_bitrix' => $eventName !== ''
                || $this->hasCaseInsensitiveKey($payload, 'auth')
                || $this->hasCaseInsensitiveKey($payload, 'auth_id')
                || $this->hasCaseInsensitiveKey($payload, 'refresh_id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     */
    private function extractEventName(array $payload, array $query): string
    {
        $event = $this->caseInsensitiveValue($payload, 'event');

        if ($event === null) {
            $event = $this->caseInsensitiveValue($query, 'event');
        }

        return is_scalar($event) ? trim((string) $event) : '';
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            $normalizedCompactKey = str_replace('_', '', $normalizedKey);

            if (in_array($normalizedCompactKey, [
                'accessid',
                'accesstoken',
                'authid',
                'clientsecret',
                'refreshid',
                'refreshtoken',
            ], true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[(string) $key] = $this->sanitizeArray($value);

                continue;
            }

            $sanitized[(string) $key] = $value;
        }

        return $sanitized;
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

    private function hasCaseInsensitiveKey(array $values, string $needle): bool
    {
        $normalizedNeedle = mb_strtolower((string) $needle);

        foreach ($values as $key => $_) {
            if (mb_strtolower((string) $key) === $normalizedNeedle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function sanitizeHeaders(array $headers): array
    {
        return Collection::make($headers)
            ->mapWithKeys(fn (array $values, string $name): array => [strtolower($name) => implode(', ', $values)])
            ->only([
                'content-type',
                'user-agent',
                'x-forwarded-for',
                'x-forwarded-proto',
                'x-forwarded-host',
            ])
            ->all();
    }
}
