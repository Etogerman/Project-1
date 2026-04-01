<?php

namespace App\Services\Bitrix24;

class ComputeBitrix24ContactSyncFingerprintAction
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(array $state): string
    {
        return hash('sha256', json_encode($this->normalizeState($state), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeState(array $state): array
    {
        ksort($state);

        foreach ($state as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($this->isAssoc($value)) {
                $state[$key] = $this->normalizeState($value);

                continue;
            }

            $normalizedItems = [];

            foreach ($value as $item) {
                $normalizedItems[] = is_array($item)
                    ? ($this->isAssoc($item) ? $this->normalizeState($item) : array_values($item))
                    : $item;
            }

            $state[$key] = array_values($normalizedItems);
        }

        return $state;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
