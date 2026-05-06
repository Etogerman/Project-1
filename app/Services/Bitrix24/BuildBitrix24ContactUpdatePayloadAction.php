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
        private readonly Bitrix24ContactPayloadNormalizer $bitrix24ContactPayloadNormalizer,
        private readonly ResolveBitrix24ContactNameUpdateAction $resolveBitrix24ContactNameUpdateAction,
        private readonly ResolveBitrix24ContactGenderUpdateAction $resolveBitrix24ContactGenderUpdateAction,
        private readonly CollectBitrix24ContactPhonesAction $collectContactPhonesAction,
        private readonly MergeBitrix24ContactPhonesAction $mergeBitrix24ContactPhonesAction,
        private readonly ComputeBitrix24ContactSyncFingerprintAction $computeFingerprintAction,
        private readonly ResolveBitrix24ProfileSchemaAction $resolveProfileSchemaAction,
    ) {}

    /**
     * @param  array<string, mixed>  $remoteSnapshot
     */
    public function handle(Contact|int $contact, array $remoteSnapshot): Bitrix24ContactUpdatePlanData
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $desiredBasePayload = $this->buildContactPayloadAction->handle($rootContact);
        $normalizedRemoteSnapshot = $this->normalizeBitrix24ContactSnapshotAction->handle($remoteSnapshot);
        $fields = $this->resolveProfileSchemaAction->fields();

        $payload = [];
        $warnings = [];

        $nameResolution = $this->resolveBitrix24ContactNameUpdateAction->handle($rootContact, $normalizedRemoteSnapshot);
        $payload = array_merge($payload, $this->filterChangedFields(
            $nameResolution['fields'],
            $normalizedRemoteSnapshot,
            [
                'NAME' => 'name',
                'LAST_NAME' => 'last_name',
                $fields['name_source'] => 'name_source_id',
                $fields['alt_first_name'] => 'alt_first_name',
                $fields['alt_last_name'] => 'alt_last_name',
            ],
        ));
        $warnings = array_merge($warnings, $nameResolution['warnings']);

        $genderResolution = $this->resolveBitrix24ContactGenderUpdateAction->handle($rootContact, $normalizedRemoteSnapshot);
        $payload = array_merge($payload, $this->filterChangedFields(
            $genderResolution['fields'],
            $normalizedRemoteSnapshot,
            [
                $fields['gender'] => 'gender_id',
            ],
        ));
        $warnings = array_merge($warnings, $genderResolution['warnings']);

        $payload = array_merge($payload, $this->filterChangedFields(
            array_diff_key($desiredBasePayload, array_flip([
                'NAME',
                'LAST_NAME',
                'PHONE',
                $fields['gender'],
                $fields['name_source'],
                $fields['alt_first_name'],
                $fields['alt_last_name'],
            ])),
            $normalizedRemoteSnapshot,
            [
                'ADDRESS_CITY' => 'address_city',
                'ADDRESS_COUNTRY' => 'address_country',
                'SOURCE_ID' => 'source_id',
                $fields['age_exact'] => 'age_exact',
                $fields['age_range'] => 'age_range',
                $fields['contact_id'] => 'contact_id',
                $fields['channel_id'] => 'channel_id',
                $fields['channel_name'] => 'channel_name',
                $fields['platform'] => 'platform',
                $fields['bot_code'] => 'bot_code',
                $fields['bot_name'] => 'bot_name',
            ],
        ));

        $localPhones = $this->collectContactPhonesAction->handle($rootContact);
        $mergedPhones = $this->mergeBitrix24ContactPhonesAction->handle(
            $localPhones,
            $normalizedRemoteSnapshot['phones'],
        );

        if (
            $this->bitrix24ContactPayloadNormalizer->normalizePhonePayload($mergedPhones)
            !== $this->bitrix24ContactPayloadNormalizer->normalizePhonePayload($normalizedRemoteSnapshot['phones'])
        ) {
            $payload['PHONE'] = $mergedPhones;
        }

        $fingerprint = $this->computeFingerprintAction->handle([
            'name' => $nameResolution['resolved_first_name'],
            'last_name' => $nameResolution['resolved_last_name'],
            'name_source_id' => isset($nameResolution['fields'][$fields['name_source']])
                ? (string) $nameResolution['fields'][$fields['name_source']]
                : ($normalizedRemoteSnapshot['name_source_id'] ?? null),
            'age_exact' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload[$fields['age_exact']] ?? null),
            'age_range' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload[$fields['age_range']] ?? null),
            'gender_id' => $genderResolution['resolved_gender_id'],
            'address_city' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload['ADDRESS_CITY'] ?? null),
            'address_country' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload['ADDRESS_COUNTRY'] ?? null),
            'source_id' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload['SOURCE_ID'] ?? null),
            'contact_id' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload[$fields['contact_id']] ?? null),
            'channel_id' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload[$fields['channel_id']] ?? null),
            'channel_name' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload[$fields['channel_name']] ?? null),
            'platform' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload[$fields['platform']] ?? null),
            'bot_code' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload[$fields['bot_code']] ?? null),
            'bot_name' => $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($desiredBasePayload[$fields['bot_name']] ?? null),
            'alt_first_name' => $nameResolution['alt_first_name'],
            'alt_last_name' => $nameResolution['alt_last_name'],
            'phones' => $this->bitrix24ContactPayloadNormalizer->normalizePhonePayload($mergedPhones),
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
                : $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($remoteSnapshot[$remoteKey] ?? null);
            $normalizedLocalValue = $this->bitrix24ContactPayloadNormalizer->normalizeScalarValue($value);

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
}
