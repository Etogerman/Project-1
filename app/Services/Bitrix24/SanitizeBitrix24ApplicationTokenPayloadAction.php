<?php

namespace App\Services\Bitrix24;

class SanitizeBitrix24ApplicationTokenPayloadAction
{
    public function __construct(
        private readonly HashBitrix24ApplicationTokenAction $hashApplicationToken,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return $this->sanitize($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key)
                ? mb_strtolower(str_replace('_', '', $key))
                : null;

            if ($normalizedKey === 'applicationtoken') {
                $hash = $this->hashApplicationToken->handle($value);

                if ($hash !== null) {
                    $sanitized['application_token_hash'] = $hash;
                }

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
