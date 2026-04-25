<?php

namespace App\Services\Bitrix24;

class SanitizeBitrix24LogPayloadAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->sanitizeArray($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $normalizedCompactKey = str_replace('_', '', $normalizedKey);

            if (in_array($normalizedCompactKey, ['accesstoken', 'authid', 'clientsecret', 'refreshid', 'refreshtoken'], true)
                || $normalizedKey === 'auth'
            ) {
                if ($normalizedKey === 'auth' && is_array($value)) {
                    $sanitized[(string) $key] = $this->sanitizeArray($value);
                }

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
}
