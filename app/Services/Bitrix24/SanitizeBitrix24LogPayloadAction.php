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

            if (in_array($normalizedKey, ['access_token', 'refresh_token', 'client_secret', 'auth'], true)) {
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
