<?php

namespace App\Services\Bitrix24;

use App\Services\Contacts\NormalizePhoneNumberAction;

class BuildBitrix24DedupedRawPhonePayloadAction
{
    public function __construct(
        private readonly NormalizePhoneNumberAction $normalizePhoneNumberAction,
    ) {}

    /**
     * @param  mixed  $rawPhones
     * @return list<array{VALUE: string, VALUE_TYPE: string}>|null
     */
    public function handle(mixed $rawPhones): ?array
    {
        if (! is_array($rawPhones)) {
            return null;
        }

        $groups = [];
        $hasDuplicates = false;

        foreach ($rawPhones as $index => $phone) {
            if (! is_array($phone)) {
                continue;
            }

            $value = $this->nullableString($phone['VALUE'] ?? null);
            $normalized = $value === null
                ? ''
                : $this->normalizePhoneNumberAction->handle($value);

            if ($normalized === '') {
                continue;
            }

            $groups[$normalized] ??= [];
            $groups[$normalized][] = [
                'index' => $index,
                'value_type' => $this->resolveValueType($phone['VALUE_TYPE'] ?? null),
            ];
        }

        $payload = [];

        foreach ($groups as $normalized => $entries) {
            if (count($entries) > 1) {
                $hasDuplicates = true;
            }

            $chosenEntry = $this->selectCanonicalEntry($entries);
            $payload[] = [
                'VALUE' => $normalized,
                'VALUE_TYPE' => $chosenEntry['value_type'],
            ];
        }

        return $hasDuplicates ? $payload : null;
    }

    /**
     * @param  list<array{index: int, value_type: string}>  $entries
     * @return array{index: int, value_type: string}
     */
    private function selectCanonicalEntry(array $entries): array
    {
        foreach ($entries as $entry) {
            if ($entry['value_type'] === 'WORK') {
                return $entry;
            }
        }

        return $entries[0];
    }

    private function resolveValueType(mixed $valueType): string
    {
        $normalizedValueType = $this->nullableString($valueType);

        return $normalizedValueType === null ? 'OTHER' : strtoupper($normalizedValueType);
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
