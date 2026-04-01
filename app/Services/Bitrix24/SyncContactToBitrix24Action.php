<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24ContactUpdatePlanData;
use App\Data\Bitrix24\Bitrix24ContactMatchResultData;
use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class SyncContactToBitrix24Action
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly CollectBitrix24ContactPhonesAction $collectContactPhonesAction,
        private readonly ResolveBitrix24ContactSourceAction $resolveContactSourceAction,
        private readonly BuildBitrix24ContactPayloadAction $buildContactPayloadAction,
        private readonly FindBitrix24DuplicateContactsByPhonesAction $findDuplicateContactsByPhonesAction,
        private readonly ResolveBitrix24ContactMatchAction $resolveContactMatchAction,
        private readonly CreateBitrix24ContactAction $createBitrix24ContactAction,
        private readonly LinkBitrix24ContactAction $linkBitrix24ContactAction,
        private readonly FetchBitrix24ContactAction $fetchBitrix24ContactAction,
        private readonly BuildBitrix24ContactUpdatePayloadAction $buildBitrix24ContactUpdatePayloadAction,
        private readonly ShouldUpdateBitrix24ContactAction $shouldUpdateBitrix24ContactAction,
        private readonly UpdateBitrix24ContactAction $updateBitrix24ContactAction,
        private readonly ComputeBitrix24ContactSyncFingerprintAction $computeBitrix24ContactSyncFingerprintAction,
        private readonly LogBitrix24ApiCallAction $logApiCallAction,
    ) {}

    public function handle(Contact|int $contact): Contact
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        if (filled($rootContact->bitrix24_contact_id)) {
            return $this->syncExistingLinkedContact($rootContact);
        }

        $sourceId = $this->resolveContactSourceAction->handle($rootContact);

        if (! filled($sourceId)) {
            $rootContact->forceFill([
                'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_FAILED,
            ])->save();

            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'contact_sync_missing_source',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'contact_id' => $rootContact->id,
                ],
                connection: null,
                errorMessage: 'Bitrix24 source mapping is missing for the contact primary identity channel.',
                entityType: 'contact',
                entityId: (string) $rootContact->id,
            );

            return $rootContact->fresh();
        }

        $phones = $this->collectContactPhonesAction->handle($rootContact);
        $lookupResult = $this->findDuplicateContactsByPhonesAction->handle($phones);
        $matchResult = $this->resolveContactMatchAction->handle($lookupResult);

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'contact_sync_match_resolved',
            status: $matchResult->type === Bitrix24ContactMatchResultData::TYPE_CONFLICT
                ? Bitrix24SyncLog::STATUS_SKIPPED
                : Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $rootContact->id,
                'phones' => $phones,
            ],
            responsePayload: [
                'match_type' => $matchResult->type,
                'matched_contact_id' => $matchResult->matchedContactId,
                'candidate_contact_ids' => $matchResult->candidateContactIds,
                'ambiguous' => $matchResult->ambiguous,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );

        return match ($matchResult->type) {
            Bitrix24ContactMatchResultData::TYPE_NO_MATCH => $this->createRemoteContact($rootContact),
            Bitrix24ContactMatchResultData::TYPE_SINGLE_MATCH => $this->linkVerifiedRemoteContact($rootContact, (string) $matchResult->matchedContactId),
            Bitrix24ContactMatchResultData::TYPE_CONFLICT => $this->markConflict($rootContact, $matchResult),
            default => $rootContact,
        };
    }

    private function createRemoteContact(Contact $contact): Contact
    {
        $payload = $this->buildContactPayloadAction->handle($contact);

        if ($payload === []) {
            $contact->forceFill([
                'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_FAILED,
            ])->save();

            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'contact_sync_payload_failed',
                status: Bitrix24SyncLog::STATUS_FAILED,
                requestPayload: [
                    'contact_id' => $contact->id,
                ],
                connection: null,
                errorMessage: 'Bitrix24 contact payload could not be built.',
                entityType: 'contact',
                entityId: (string) $contact->id,
            );

            return $contact->fresh();
        }

        $bitrix24ContactId = $this->createBitrix24ContactAction->handle($contact, $payload);
        $linkedContact = $this->persistSyncedContactState(
            $this->linkBitrix24ContactAction->handle($contact, $bitrix24ContactId),
            $this->computeCreateFingerprint($payload),
        );

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'contact_sync_created',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $contact->id,
            ],
            responsePayload: [
                'bitrix24_contact_id' => $bitrix24ContactId,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );

        return $linkedContact;
    }

    private function linkVerifiedRemoteContact(Contact $contact, string $bitrix24ContactId): Contact
    {
        $remoteSnapshot = $this->fetchRemoteContact($contact, $bitrix24ContactId);

        $linkedContact = $this->linkBitrix24ContactAction->handle($contact, $bitrix24ContactId);

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'contact_sync_linked',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $contact->id,
            ],
            responsePayload: [
                'bitrix24_contact_id' => $bitrix24ContactId,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );

        return $this->syncExistingLinkedContact($linkedContact, $remoteSnapshot);
    }

    private function markConflict(Contact $contact, Bitrix24ContactMatchResultData $matchResult): Contact
    {
        $contact->forceFill([
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW,
        ])->save();

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'contact_sync_conflict',
            status: Bitrix24SyncLog::STATUS_SKIPPED,
            requestPayload: [
                'contact_id' => $contact->id,
                'phones' => $matchResult->checkedPhones,
            ],
            responsePayload: [
                'candidate_contact_ids' => $matchResult->candidateContactIds,
                'ambiguous' => $matchResult->ambiguous,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $contact->id,
            errorMessage: 'Bitrix24 duplicate search produced multiple candidate contacts.',
        );

        return $contact->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $remoteSnapshot
     */
    private function syncExistingLinkedContact(Contact $contact, ?array $remoteSnapshot = null): Contact
    {
        $bitrix24ContactId = (string) $contact->bitrix24_contact_id;
        $remoteSnapshot ??= $this->fetchRemoteContact($contact, $bitrix24ContactId);
        $updatePlan = $this->buildBitrix24ContactUpdatePayloadAction->handle($contact, $remoteSnapshot);

        $this->logUpdateWarnings($contact, $updatePlan);

        if (! $this->shouldUpdateBitrix24ContactAction->handle($updatePlan->payload)) {
            $syncedContact = $this->persistSyncedContactState($contact, $updatePlan->fingerprint);

            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'contact_sync_noop',
                status: Bitrix24SyncLog::STATUS_SKIPPED,
                requestPayload: [
                    'contact_id' => $contact->id,
                    'bitrix24_contact_id' => $bitrix24ContactId,
                ],
                responsePayload: [
                    'fingerprint' => $updatePlan->fingerprint,
                ],
                connection: null,
                entityType: 'contact',
                entityId: (string) $contact->id,
            );

            return $syncedContact;
        }

        if (array_key_exists('PHONE', $updatePlan->payload)) {
            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: 'contact_sync_phone_merge',
                status: Bitrix24SyncLog::STATUS_SUCCESS,
                requestPayload: [
                    'contact_id' => $contact->id,
                    'bitrix24_contact_id' => $bitrix24ContactId,
                ],
                responsePayload: [
                    'phone_count' => count($updatePlan->payload['PHONE']),
                ],
                connection: null,
                entityType: 'contact',
                entityId: (string) $contact->id,
            );
        }

        $this->updateBitrix24ContactAction->handle($contact, $bitrix24ContactId, $updatePlan->payload);
        $syncedContact = $this->persistSyncedContactState($contact, $updatePlan->fingerprint);

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'contact_sync_update',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $bitrix24ContactId,
            ],
            responsePayload: [
                'updated_fields' => array_keys($updatePlan->payload),
                'fingerprint' => $updatePlan->fingerprint,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );

        return $syncedContact;
    }

    private function persistSyncedContactState(Contact $contact, string $fingerprint): Contact
    {
        $contact->forceFill([
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_last_synced_at' => now(),
            'bitrix24_sync_fingerprint' => $fingerprint,
        ])->save();

        return $contact->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function computeCreateFingerprint(array $payload): string
    {
        $nameSourceField = config('bitrix24.fields.name_source');
        $ageExactField = config('bitrix24.fields.age_exact');
        $ageRangeField = config('bitrix24.fields.age_range');
        $genderField = config('bitrix24.fields.gender');
        $contactIdField = config('bitrix24.fields.contact_id');
        $channelIdField = config('bitrix24.fields.channel_id');
        $channelNameField = config('bitrix24.fields.channel_name');
        $platformField = config('bitrix24.fields.platform');
        $botCodeField = config('bitrix24.fields.bot_code');
        $botNameField = config('bitrix24.fields.bot_name');

        return $this->computeBitrix24ContactSyncFingerprintAction->handle([
            'name' => $this->normalizeScalarValue($payload['NAME'] ?? null),
            'last_name' => $this->normalizeScalarValue($payload['LAST_NAME'] ?? null),
            'name_source_id' => $this->normalizeScalarValue($payload[$nameSourceField] ?? null),
            'age_exact' => $this->normalizeScalarValue($payload[$ageExactField] ?? null),
            'age_range' => $this->normalizeScalarValue($payload[$ageRangeField] ?? null),
            'gender_id' => $this->normalizeScalarValue($payload[$genderField] ?? null),
            'address_city' => $this->normalizeScalarValue($payload['ADDRESS_CITY'] ?? null),
            'address_country' => $this->normalizeScalarValue($payload['ADDRESS_COUNTRY'] ?? null),
            'source_id' => $this->normalizeScalarValue($payload['SOURCE_ID'] ?? null),
            'contact_id' => $this->normalizeScalarValue($payload[$contactIdField] ?? null),
            'channel_id' => $this->normalizeScalarValue($payload[$channelIdField] ?? null),
            'channel_name' => $this->normalizeScalarValue($payload[$channelNameField] ?? null),
            'platform' => $this->normalizeScalarValue($payload[$platformField] ?? null),
            'bot_code' => $this->normalizeScalarValue($payload[$botCodeField] ?? null),
            'bot_name' => $this->normalizeScalarValue($payload[$botNameField] ?? null),
            'alt_first_name' => null,
            'alt_last_name' => null,
            'phones' => $this->normalizePhonePayload($payload['PHONE'] ?? []),
        ]);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function logUpdateWarnings(Contact $contact, Bitrix24ContactUpdatePlanData $updatePlan): void
    {
        foreach ($updatePlan->warnings as $warning) {
            $operation = match ($warning) {
                'training_verified_name_preserved' => 'contact_sync_name_conflict_warning',
                'gender_preserved' => 'contact_sync_gender_preserved',
                default => null,
            };

            if ($operation === null) {
                continue;
            }

            $this->logApiCallAction->handle(
                direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
                operation: $operation,
                status: Bitrix24SyncLog::STATUS_SKIPPED,
                requestPayload: [
                    'contact_id' => $contact->id,
                    'bitrix24_contact_id' => (string) $contact->bitrix24_contact_id,
                ],
                connection: null,
                entityType: 'contact',
                entityId: (string) $contact->id,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRemoteContact(Contact $contact, string $bitrix24ContactId): array
    {
        $remoteSnapshot = $this->fetchBitrix24ContactAction->handle($bitrix24ContactId);

        $this->logApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'contact_sync_fetch_remote',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'contact_id' => $contact->id,
                'bitrix24_contact_id' => $bitrix24ContactId,
            ],
            responsePayload: [
                'remote_id' => $remoteSnapshot['ID'] ?? null,
            ],
            connection: null,
            entityType: 'contact',
            entityId: (string) $contact->id,
        );

        return $remoteSnapshot;
    }

    /**
     * @param  list<array{VALUE: string, VALUE_TYPE: string}>  $phones
     * @return list<array{VALUE: string, VALUE_TYPE: string}>
     */
    private function normalizePhonePayload(array $phones): array
    {
        $normalizedPhones = [];

        foreach ($phones as $phone) {
            $value = $this->normalizeScalarValue($phone['VALUE'] ?? null);

            if ($value === null) {
                continue;
            }

            $normalizedPhones[] = [
                'VALUE' => $value,
                'VALUE_TYPE' => $this->normalizeScalarValue($phone['VALUE_TYPE'] ?? null) ?? 'OTHER',
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
