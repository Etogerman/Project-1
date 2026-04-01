<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24ContactUpdatePlanData;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class BuildBitrix24ContactUpdatePayloadAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly BuildBitrix24ContactPayloadAction $buildContactPayloadAction,
        private readonly NormalizeBitrix24ContactSnapshotAction $normalizeBitrix24ContactSnapshotAction,
        private readonly ResolveBitrix24ContactNameUpdateAction $resolveBitrix24ContactNameUpdateAction,
        private readonly ResolveBitrix24ContactGenderUpdateAction $resolveBitrix24ContactGenderUpdateAction,
        private readonly CollectBitrix24ContactPhonesAction $collectContactPhonesAction,
        private readonly MergeBitrix24ContactPhonesAction $mergeBitrix24ContactPhonesAction,
        private readonly ComputeBitrix24ContactSyncFingerprintAction $computeFingerprintAction,
    ) {}

    /**
     * @param  array<string, mixed>  $remoteSnapshot
     */
    public function handle(Contact|int $contact, array $remoteSnapshot): Bitrix24ContactUpdatePlanData
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $desiredBasePayload = $this->buildContactPayloadAction->handle($rootContact);
        $normalizedRemoteSnapshot = $this->normalizeBitrix24ContactSnapshotAction->handle($remoteSnapshot);

        $payload = [];
        $warnings = [];

        $nameResolution = $this->resolveBitrix24ContactNameUpdateAction->handle($rootContact, $normalizedRemoteSnapshot);
        $payload = array_merge($payload, $this->filterChangedFields(
            $nameResolution['fields'],
            $normalizedRemoteSnapshot,
            [
                'NAME' => 'name',
                'LAST_NAME' => 'last_name',
                config('bitrix24.fields.name_source') => 'name_source_id',
                config('bitrix24.fields.alt_first_name') => 'alt_first_name',
                config('bitrix24.fields.alt_last_name') => 'alt_last_name',
            ],
        ));
        $warnings = array_merge($warnings, $nameResolution['warnings']);

        $genderResolution = $this->resolveBitrix24ContactGenderUpdateAction->handle($rootContact, $normalizedRemoteSnapshot);
        $payload = array_merge($payload, $this->filterChangedFields(
            $genderResolution['fields'],
            $normalizedRemoteSnapshot,
            [
                config('bitrix24.fields.gender') => 'gender_id',
            ],
        ));
        $warnings = array_merge($warnings, $genderResolution['warnings']);

        $payload = array_merge($payload, $this->filterChangedFields(
            array_diff_key($desiredBasePayload, array_flip([
                'NAME',
                'LAST_NAME',
                'PHONE',
                config('bitrix24.fields.gender'),
                config('bitrix24.fields.name_source'),
                config('bitrix24.fields.alt_first_name'),
                config('bitrix24.fields.alt_last_name'),
            ])),
            $normalizedRemoteSnapshot,
            [
                'ADDRESS_CITY' => 'address_city',
                'ADDRESS_COUNTRY' => 'address_country',
                'SOURCE_ID' => 'source_id',
                config('bitrix24.fields.age_exact') => 'age_exact',
                config('bitrix24.fields.age_range') => 'age_range',
                config('bitrix24.fields.contact_id') => 'contact_id',
                config('bitrix24.fields.channel_id') => 'channel_id',
                config('bitrix24.fields.channel_name') => 'channel_name',
                config('bitrix24.fields.platform') => 'platform',
                config('bitrix24.fields.bot_code') => 'bot_code',
                config('bitrix24.fields.bot_name') => 'bot_name',
            ],
        ));

        $localPhones = $this->collectContactPhonesAction->handle($rootContact);
        $mergedPhones = $this->mergeBitrix24ContactPhonesAction->handle(
            $localPhones,
            $normalizedRemoteSnapshot['phones'],
        );

        if ($this->normalizePhonePayload($mergedPhones) !== $this->normalizePhonePayload($normalizedRemoteSnapshot['phones'])) {
            $payload['PHONE'] = $mergedPhones;
        }

        $fingerprint = $this->computeFingerprintAction->handle([
            'name' => $nameResolution['resolved_first_name'],
            'last_name' => $nameResolution['resolved_last_name'],
            'name_source_id' => isset($nameResolution['fields'][config('bitrix24.fields.name_source')])
                ? (string) $nameResolution['fields'][config('bitrix24.fields.name_source')]
                : ($normalizedRemoteSnapshot['name_source_id'] ?? null),
            'age_exact' => $this->normalizeScalarValue($desiredBasePayload[config('bitrix24.fields.age_exact')] ?? null),
            'age_range' => $this->normalizeScalarValue($desiredBasePayload[config('bitrix24.fields.age_range')] ?? null),
            'gender_id' => $genderResolution['resolved_gender_id'],
            'address_city' => $this->normalizeScalarValue($desiredBasePayload['ADDRESS_CITY'] ?? null),
            'address_country' => $this->normalizeScalarValue($desiredBasePayload['ADDRESS_COUNTRY'] ?? null),
            'source_id' => $this->normalizeScalarValue($desiredBasePayload['SOURCE_ID'] ?? null),
            'contact_id' => $this->normalizeScalarValue($desiredBasePayload[config('bitrix24.fields.contact_id')] ?? null),
            'channel_id' => $this->normalizeScalarValue($desiredBasePayload[config('bitrix24.fields.channel_id')] ?? null),
            'channel_name' => $this->normalizeScalarValue($desiredBasePayload[config('bitrix24.fields.channel_name')] ?? null),
            'platform' => $this->normalizeScalarValue($desiredBasePayload[config('bitrix24.fields.platform')] ?? null),
            'bot_code' => $this->normalizeScalarValue($desiredBasePayload[config('bitrix24.fields.bot_code')] ?? null),
            'bot_name' => $this->normalizeScalarValue($desiredBasePayload[config('bitrix24.fields.bot_name')] ?? null),
            'alt_first_name' => $nameResolution['alt_first_name'],
            'alt_last_name' => $nameResolution['alt_last_name'],
            'phones' => $this->normalizePhonePayload($mergedPhones),
        ]);

        return new Bitrix24ContactUpdatePlanData(
            payload: $payload,
            fingerprint: $fingerprint,
            warnings: array_values(array_unique(array_filter($warnings))),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $remoteSnapshot
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function filterChangedFields(array $fields, array $remoteSnapshot, array $mapping): array
    {
        $changedFields = [];

        foreach ($fields as $fieldKey => $value) {
            $remoteKey = $mapping[$fieldKey] ?? null;
            $normalizedRemoteValue = $remoteKey === null
                ? null
                : $this->normalizeScalarValue($remoteSnapshot[$remoteKey] ?? null);
            $normalizedLocalValue = $this->normalizeScalarValue($value);

            if ($normalizedLocalValue === null) {
                continue;
            }

            if ($normalizedLocalValue === $normalizedRemoteValue) {
                continue;
            }

            $changedFields[$fieldKey] = $value;
        }

        return $changedFields;
    }

    /**
     * @param  list<array{VALUE: string, VALUE_TYPE: string}|array{value: string, normalized: string, value_type: string}>  $phones
     * @return list<array{VALUE: string, VALUE_TYPE: string}>
     */
    private function normalizePhonePayload(array $phones): array
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

    private function normalizeScalarValue(mixed $value): ?string
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
