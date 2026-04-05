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

        $grouped = [];

        foreach ($value as $key => $item) {
            $normalizedKey = mb_strtolower((string) $key);

            $grouped[$normalizedKey][] = [
                'key' => (string) $key,
                'value' => $this->normalizeValue($item),
            ];
        }

        ksort($grouped);

        $normalized = [];

        foreach ($grouped as $normalizedKey => $entries) {
            if (count($entries) === 1) {
                $normalized[$normalizedKey] = $entries[0]['value'];

                continue;
            }

            usort($entries, fn (array $left, array $right): int => strcmp($left['key'], $right['key']));

            $normalized[$normalizedKey] = [
                '__case_conflict__' => true,
                'entries' => $entries,
            ];
        }

        return $normalized;
    }
}
