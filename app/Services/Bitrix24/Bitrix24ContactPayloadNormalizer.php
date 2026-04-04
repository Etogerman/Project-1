<?php

namespace App\Services\Bitrix24;

class Bitrix24ContactPayloadNormalizer
{
    /**
     * @param  list<array{VALUE?: mixed, VALUE_TYPE?: mixed, value?: mixed, value_type?: mixed}>  $phones
     * @return list<array{VALUE: string, VALUE_TYPE: string}>
     */
    public function normalizePhonePayload(array $phones): array
    {
        $normalizedPhones = [];

        foreach ($phones as $phone) {
            $value = $phone['VALUE'] ?? $phone['value'] ?? null;
            $valueType = $phone['VALUE_TYPE'] ?? $phone['value_type'] ?? 'OTHER';
            $normalizedValue = $this->normalizeScalarValue($value);

            if ($normalizedValue === null) {
                continue;
            }

            $normalizedPhones[] = [
                'VALUE' => $normalizedValue,
                'VALUE_TYPE' => $this->normalizeScalarValue($valueType) ?? 'OTHER',
            ];
        }

        return $normalizedPhones;
    }

    public function normalizeScalarValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
