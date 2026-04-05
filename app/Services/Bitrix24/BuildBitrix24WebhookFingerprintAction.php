<?php

namespace App\Services\Bitrix24;

class BuildBitrix24WebhookFingerprintAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->normalizeValue($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}');
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[mb_strtolower((string) $key)] = $this->normalizeValue($item);
        }

        ksort($normalized);

        return $normalized;
    }
}
