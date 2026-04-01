<?php

namespace App\Services\Bitrix24;

use App\Services\Contacts\NormalizePhoneNumberAction;

class NormalizeBitrix24ContactSnapshotAction
{
    public function __construct(
        private readonly NormalizePhoneNumberAction $normalizePhoneNumberAction,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function handle(array $snapshot): array
    {
        return [
            'id' => $this->nullableString($snapshot['ID'] ?? null),
            'name' => $this->nullableString($snapshot['NAME'] ?? null),
            'last_name' => $this->nullableString($snapshot['LAST_NAME'] ?? null),
            'source_id' => $this->nullableString($snapshot['SOURCE_ID'] ?? null),
            'address_city' => $this->nullableString($snapshot['ADDRESS_CITY'] ?? null),
            'address_country' => $this->nullableString($snapshot['ADDRESS_COUNTRY'] ?? null),
            'name_source_id' => $this->nullableString($snapshot[config('bitrix24.fields.name_source')] ?? null),
            'age_exact' => $this->nullableString($snapshot[config('bitrix24.fields.age_exact')] ?? null),
            'age_range' => $this->nullableString($snapshot[config('bitrix24.fields.age_range')] ?? null),
            'gender_id' => $this->nullableString($snapshot[config('bitrix24.fields.gender')] ?? null),
            'contact_id' => $this->nullableString($snapshot[config('bitrix24.fields.contact_id')] ?? null),
            'channel_id' => $this->nullableString($snapshot[config('bitrix24.fields.channel_id')] ?? null),
            'channel_name' => $this->nullableString($snapshot[config('bitrix24.fields.channel_name')] ?? null),
            'platform' => $this->nullableString($snapshot[config('bitrix24.fields.platform')] ?? null),
            'bot_code' => $this->nullableString($snapshot[config('bitrix24.fields.bot_code')] ?? null),
            'bot_name' => $this->nullableString($snapshot[config('bitrix24.fields.bot_name')] ?? null),
            'alt_first_name' => $this->nullableString($snapshot[config('bitrix24.fields.alt_first_name')] ?? null),
            'alt_last_name' => $this->nullableString($snapshot[config('bitrix24.fields.alt_last_name')] ?? null),
            'phones' => $this->normalizePhones($snapshot['PHONE'] ?? []),
        ];
    }

    /**
     * @param  mixed  $phones
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
