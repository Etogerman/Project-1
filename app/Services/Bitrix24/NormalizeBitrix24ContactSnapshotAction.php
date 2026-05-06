<?php

namespace App\Services\Bitrix24;

use App\Services\Contacts\NormalizePhoneNumberAction;

class NormalizeBitrix24ContactSnapshotAction
{
    public function __construct(
        private readonly NormalizePhoneNumberAction $normalizePhoneNumberAction,
        private readonly ResolveBitrix24ProfileSchemaAction $resolveProfileSchemaAction,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function handle(array $snapshot): array
    {
        $fields = $this->resolveProfileSchemaAction->fields();

        return [
            'id' => $this->nullableString($snapshot['ID'] ?? null),
            'name' => $this->nullableString($snapshot['NAME'] ?? null),
            'last_name' => $this->nullableString($snapshot['LAST_NAME'] ?? null),
            'source_id' => $this->nullableString($snapshot['SOURCE_ID'] ?? null),
            'address_city' => $this->nullableString($snapshot['ADDRESS_CITY'] ?? null),
            'address_country' => $this->nullableString($snapshot['ADDRESS_COUNTRY'] ?? null),
            'name_source_id' => $this->nullableString($snapshot[$fields['name_source']] ?? null),
            'age_exact' => $this->nullableString($snapshot[$fields['age_exact']] ?? null),
            'age_range' => $this->nullableString($snapshot[$fields['age_range']] ?? null),
            'gender_id' => $this->nullableString($snapshot[$fields['gender']] ?? null),
            'contact_id' => $this->nullableString($snapshot[$fields['contact_id']] ?? null),
            'channel_id' => $this->nullableString($snapshot[$fields['channel_id']] ?? null),
            'channel_name' => $this->nullableString($snapshot[$fields['channel_name']] ?? null),
            'platform' => $this->nullableString($snapshot[$fields['platform']] ?? null),
            'bot_code' => $this->nullableString($snapshot[$fields['bot_code']] ?? null),
            'bot_name' => $this->nullableString($snapshot[$fields['bot_name']] ?? null),
            'alt_first_name' => $this->nullableString($snapshot[$fields['alt_first_name']] ?? null),
            'alt_last_name' => $this->nullableString($snapshot[$fields['alt_last_name']] ?? null),
            'phones' => $this->normalizePhones($snapshot['PHONE'] ?? []),
        ];
    }

    /**
     * @return list<array{value: string, normalized: string, value_type: string}>
     */
    private function normalizePhones(mixed $phones): array
    {
        if (! is_array($phones)) {
            return [];
        }

        $normalizedPhones = [];

        foreach ($phones as $phone) {
            if (! is_array($phone)) {
                continue;
            }

            $value = $this->nullableString($phone['VALUE'] ?? null);
            $normalized = $value === null
                ? ''
                : $this->normalizePhoneNumberAction->handle($value);

            if ($normalized === '' || isset($normalizedPhones[$normalized])) {
                continue;
            }

            $normalizedPhones[$normalized] = [
                'value' => $value ?? $normalized,
                'normalized' => $normalized,
                'value_type' => $this->nullableString($phone['VALUE_TYPE'] ?? null) ?? 'OTHER',
            ];
        }

        return array_values($normalizedPhones);
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
