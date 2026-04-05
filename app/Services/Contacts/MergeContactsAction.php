<?php

namespace App\Services\Contacts;

use App\Data\Contacts\MergeContactsResult;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactMergeLog;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use App\Services\Dialogs\ConsolidateDialogsForRootContactAction;
use App\Services\DataCollection\ResolveRussianRegionCandidatesLookupAction;
use App\Services\Geo\ResolveRussianLocalityGeocodeQueryAction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MergeContactsAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly SelectPrimaryContactForMergeAction $selectPrimaryContactForMergeAction,
        private readonly ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
        private readonly ConsolidateDialogsForRootContactAction $consolidateDialogsForRootContactAction,
        private readonly ResolveRussianRegionCandidatesLookupAction $resolveRussianRegionCandidatesLookupAction,
        private readonly ResolveRussianLocalityGeocodeQueryAction $resolveRussianLocalityGeocodeQueryAction,
    ) {}

    public function handle(
        Contact|int $left,
        Contact|int $right,
        string $mergeReason = 'phone_exact_match',
        ?string $triggerPhone = null,
        ?Message $triggerMessage = null,
        string $createdByType = ContactMergeLog::CREATED_BY_TYPE_SYSTEM,
    ): MergeContactsResult {
        $selection = $this->selectPrimaryContactForMergeAction->handle($left, $right);

        if ($selection === null) {
            $root = $this->resolveRootContactAction->handle($left);

            return new MergeContactsResult(
                primaryContactId: $root->id,
                secondaryContactId: $root->id,
                wasMerged: false,
                wasNoopSameRoot: true,
                messagesMovedCount: 0,
                identitiesMovedCount: 0,
                phonesMovedCount: 0,
                fieldsCopied: [],
                fieldsConflicted: [],
                mergeLogId: null,
            );
        }

        return DB::transaction(function () use ($selection, $mergeReason, $triggerPhone, $triggerMessage, $createdByType): MergeContactsResult {
            $lockedContacts = Contact::query()
                ->whereKey([$selection->primary->id, $selection->secondary->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Contact $lockedPrimary */
            $lockedPrimary = $lockedContacts->get($selection->primary->id) ?? throw new ContactMergeException('Primary contact is missing during merge.');
            /** @var Contact $lockedSecondary */
            $lockedSecondary = $lockedContacts->get($selection->secondary->id) ?? throw new ContactMergeException('Secondary contact is missing during merge.');

            if ($lockedPrimary->isMerged()) {
                throw new ContactMergeException('Primary contact changed its root during merge.');
            }

            if ($lockedSecondary->merged_into_contact_id === $lockedPrimary->id) {
                return new MergeContactsResult(
                    primaryContactId: $lockedPrimary->id,
                    secondaryContactId: $lockedSecondary->id,
                    wasMerged: false,
                    wasNoopSameRoot: true,
                    messagesMovedCount: 0,
                    identitiesMovedCount: 0,
                    phonesMovedCount: 0,
                    fieldsCopied: [],
                    fieldsConflicted: [],
                    mergeLogId: null,
                );
            }

            if ($lockedSecondary->isMerged()) {
                throw new ContactMergeException('Secondary contact changed its root during merge.');
            }

            /** @var Collection<int, ContactIdentity> $identities */
            $identities = ContactIdentity::query()
                ->whereIn('contact_id', [$lockedPrimary->id, $lockedSecondary->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var Collection<int, ContactPhoneNumber> $phones */
            $phones = ContactPhoneNumber::query()
                ->whereIn('contact_id', [$lockedPrimary->id, $lockedSecondary->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertIdentityConflictFree($lockedPrimary, $lockedSecondary, $identities);

            $originalLocation = [
                'country' => $this->normalizeComparableValue($lockedPrimary->country),
                'city' => $this->normalizeComparableValue($lockedPrimary->city),
                'region' => $this->normalizeComparableValue($lockedPrimary->region),
            ];

            [$fieldsCopied, $fieldsConflicted] = $this->mergeContactFields($lockedPrimary, $lockedSecondary);

            $this->consolidateDialogsForRootContactAction->handle(
                $lockedPrimary,
                [$lockedPrimary->id, $lockedSecondary->id],
                true,
                false,
            );

            $messagesMovedCount = Message::query()
                ->where('contact_id', $lockedSecondary->id)
                ->count();

            Message::query()
                ->where('contact_id', $lockedSecondary->id)
                ->update([
                    'contact_id' => $lockedPrimary->id,
                    'updated_at' => now(),
                ]);

            $identitiesMovedCount = ContactIdentity::query()
                ->where('contact_id', $lockedSecondary->id)
                ->update([
                    'contact_id' => $lockedPrimary->id,
                    'updated_at' => now(),
                ]);

            $phonesMovedCount = $this->mergePhones(
                primary: $lockedPrimary,
                secondary: $lockedSecondary,
                phones: $phones,
                triggerPhone: $triggerPhone,
            );
            $this->mergeTags(
                primary: $lockedPrimary,
                secondary: $lockedSecondary,
            );

            $lockedPrimary->refresh();

            $locationChanged = $originalLocation !== [
                'country' => $this->normalizeComparableValue($lockedPrimary->country),
                'city' => $this->normalizeComparableValue($lockedPrimary->city),
                'region' => $this->normalizeComparableValue($lockedPrimary->region),
            ];

            if ($locationChanged) {
                $this->synchronizeDerivedLocationState($lockedPrimary);
            }

            $this->synchronizeDataCollectionState($lockedPrimary->fresh());

            $lockedSecondary->forceFill([
                'merged_into_contact_id' => $lockedPrimary->id,
                'merged_at' => now(),
                'merge_reason' => $mergeReason,
                'merge_trigger_phone' => $triggerPhone,
            ])->save();

            $this->resolvePhoneDuplicateReviews($lockedPrimary, $lockedSecondary, $triggerPhone);
            $this->refreshDuplicateReviewStatus($lockedPrimary->fresh());
            $this->refreshDuplicateReviewStatus($lockedSecondary->fresh());

            $mergeLog = ContactMergeLog::query()->create([
                'primary_contact_id' => $lockedPrimary->id,
                'secondary_contact_id' => $lockedSecondary->id,
                'trigger_phone' => $triggerPhone ?? '',
                'trigger_message_id' => $triggerMessage?->id,
                'merge_reason' => $mergeReason,
                'messages_moved_count' => $messagesMovedCount,
                'identities_moved_count' => $identitiesMovedCount,
                'phones_moved_count' => $phonesMovedCount,
                'fields_copied' => $fieldsCopied === [] ? null : $fieldsCopied,
                'fields_conflicted' => $fieldsConflicted === [] ? null : $fieldsConflicted,
                'created_by_type' => $createdByType,
            ]);

            return new MergeContactsResult(
                primaryContactId: $lockedPrimary->id,
                secondaryContactId: $lockedSecondary->id,
                wasMerged: true,
                wasNoopSameRoot: false,
                messagesMovedCount: $messagesMovedCount,
                identitiesMovedCount: $identitiesMovedCount,
                phonesMovedCount: $phonesMovedCount,
                fieldsCopied: $fieldsCopied,
                fieldsConflicted: $fieldsConflicted,
                mergeLogId: $mergeLog->id,
            );
        });
    }

    /**
     * @param  Collection<int, ContactIdentity>  $identities
     */
    private function assertIdentityConflictFree(Contact $primary, Contact $secondary, Collection $identities): void
    {
        $primaryKeys = [];
        $secondaryKeys = [];

        foreach ($identities as $identity) {
            $key = $identity->channel_id.'|'.$identity->external_user_id;

            if ($identity->contact_id === $primary->id) {
                $primaryKeys[$key] = true;

                continue;
            }

            if ($identity->contact_id === $secondary->id) {
                $secondaryKeys[$key] = true;
            }
        }

        $conflicts = array_keys(array_intersect_key($secondaryKeys, $primaryKeys));

        if ($conflicts !== []) {
            throw new ContactMergeException('Secondary contact has conflicting channel identities.');
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function mergeContactFields(Contact $primary, Contact $secondary): array
    {
        $fieldsCopied = [];
        $fieldsConflicted = [];
        $payload = [];

        foreach ([
            'first_name',
            'last_name',
            'gender',
            'birth_date',
            'age_years',
            'age_range',
            'country',
            'city',
            'region',
        ] as $field) {
            $primaryValue = $primary->getAttribute($field);
            $secondaryValue = $secondary->getAttribute($field);

            if (! filled($primaryValue) && filled($secondaryValue)) {
                $payload[$field] = $secondaryValue;
                $fieldsCopied[$field] = $this->serializeValue($secondaryValue);

                continue;
            }

            if (filled($primaryValue) && filled($secondaryValue) && $this->normalizeComparableValue($primaryValue) !== $this->normalizeComparableValue($secondaryValue)) {
                $fieldsConflicted[$field] = [
                    'primary' => $this->serializeValue($primaryValue),
                    'secondary' => $this->serializeValue($secondaryValue),
                ];
            }
        }

        if (! filled($primary->assigned_user_id) && filled($secondary->assigned_user_id)) {
            $payload['assigned_user_id'] = $secondary->assigned_user_id;
            $fieldsCopied['assigned_user_id'] = $secondary->assigned_user_id;
        } elseif (filled($primary->assigned_user_id) && filled($secondary->assigned_user_id) && (int) $primary->assigned_user_id !== (int) $secondary->assigned_user_id) {
            $fieldsConflicted['assigned_user_id'] = [
                'primary' => (int) $primary->assigned_user_id,
                'secondary' => (int) $secondary->assigned_user_id,
            ];
        }

        $mergedAutoReplyEnabled = (bool) $primary->is_auto_reply_enabled && (bool) $secondary->is_auto_reply_enabled;

        if ((bool) $primary->is_auto_reply_enabled !== $mergedAutoReplyEnabled) {
            $payload['is_auto_reply_enabled'] = $mergedAutoReplyEnabled;
            $fieldsCopied['is_auto_reply_enabled'] = $mergedAutoReplyEnabled;
        }

        if ($payload !== []) {
            $primary->forceFill($payload)->save();
        }

        return [$fieldsCopied, $fieldsConflicted];
    }

    /**
     * @param  Collection<int, ContactPhoneNumber>  $phones
     */
    private function mergePhones(Contact $primary, Contact $secondary, Collection $phones, ?string $triggerPhone): int
    {
        /** @var Collection<int, ContactPhoneNumber> $primaryPhones */
        $primaryPhones = $phones->where('contact_id', $primary->id)->values();
        /** @var Collection<int, ContactPhoneNumber> $secondaryPhones */
        $secondaryPhones = $phones->where('contact_id', $secondary->id)->values();

        $survivingByNormalized = [];

        foreach ($primaryPhones as $phone) {
            $survivingByNormalized[$phone->phone_normalized] = $phone;
        }

        $primaryPhoneId = $primaryPhones->firstWhere('is_primary', true)?->id;
        $secondaryPrimaryPhoneNormalized = null;
        $phonesMovedCount = 0;

        foreach ($secondaryPhones as $phone) {
            if ($phone->is_primary) {
                $secondaryPrimaryPhoneNormalized = $phone->phone_normalized;
            }

            if (array_key_exists($phone->phone_normalized, $survivingByNormalized)) {
                continue;
            }

            $phone->forceFill([
                'contact_id' => $primary->id,
                'is_primary' => false,
            ])->save();

            $survivingByNormalized[$phone->phone_normalized] = $phone->fresh();
            $phonesMovedCount++;
        }

        if ($primaryPhoneId === null) {
            $candidatePhone = null;

            if ($triggerPhone !== null && array_key_exists($triggerPhone, $survivingByNormalized)) {
                $candidatePhone = $survivingByNormalized[$triggerPhone];
            } elseif ($secondaryPrimaryPhoneNormalized !== null && array_key_exists($secondaryPrimaryPhoneNormalized, $survivingByNormalized)) {
                $candidatePhone = $survivingByNormalized[$secondaryPrimaryPhoneNormalized];
            } else {
                $candidatePhone = collect($survivingByNormalized)
                    ->sortBy('id')
                    ->first();
            }

            if ($candidatePhone instanceof ContactPhoneNumber) {
                ContactPhoneNumber::query()
                    ->where('contact_id', $primary->id)
                    ->update(['is_primary' => false]);

                ContactPhoneNumber::query()
                    ->whereKey($candidatePhone->id)
                    ->update(['is_primary' => true]);
            }
        }

        return $phonesMovedCount;
    }

    private function mergeTags(Contact $primary, Contact $secondary): void
    {
        $secondaryTagRows = DB::table('contact_tag')
            ->where('contact_id', $secondary->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($secondaryTagRows->isEmpty()) {
            return;
        }

        $existingPrimaryTagIds = DB::table('contact_tag')
            ->where('contact_id', $primary->id)
            ->pluck('tag_id')
            ->all();

        $existingPrimaryTagIds = array_fill_keys(array_map('intval', $existingPrimaryTagIds), true);

        foreach ($secondaryTagRows as $tagRow) {
            $tagId = (int) $tagRow->tag_id;

            if (array_key_exists($tagId, $existingPrimaryTagIds)) {
                DB::table('contact_tag')
                    ->where('id', $tagRow->id)
                    ->delete();

                continue;
            }

            DB::table('contact_tag')
                ->where('id', $tagRow->id)
                ->update([
                    'contact_id' => $primary->id,
                    'updated_at' => now(),
                ]);

            $existingPrimaryTagIds[$tagId] = true;
        }
    }

    private function synchronizeDerivedLocationState(Contact $contact): void
    {
        $country = $this->normalizeString($contact->country);
        $city = $this->normalizeString($contact->city);
        $region = $this->normalizeString($contact->region);

        if ($country !== null && ! $this->isRussianCountry($country)) {
            $contact->forceFill([
                'region' => null,
                'region_status' => Contact::REGION_STATUS_OUT_OF_SCOPE,
                'region_source' => null,
                'pending_region_candidates' => null,
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return;
        }

        if ($country === null || $city === null) {
            $contact->forceFill([
                'region_status' => $region === null ? Contact::REGION_STATUS_UNKNOWN : Contact::REGION_STATUS_RESOLVED,
                'region_source' => $region === null ? null : ($contact->region_source ?: Contact::REGION_SOURCE_MANUAL),
                'pending_region_candidates' => null,
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_PENDING,
                'distance_to_moscow_calculated_at' => null,
            ])->save();

            return;
        }

        if ($region !== null) {
            $contact->forceFill([
                'region' => $region,
                'region_status' => Contact::REGION_STATUS_RESOLVED,
                'region_source' => $contact->region_source ?: Contact::REGION_SOURCE_MANUAL,
                'pending_region_candidates' => null,
            ])->save();
        } else {
            $lookup = $this->resolveRussianRegionCandidatesLookupAction->handle($city);
            $candidateRegions = $lookup['candidate_regions'] ?? [];

            if (count($candidateRegions) === 1) {
                $contact->forceFill([
                    'region' => $candidateRegions[0],
                    'region_status' => Contact::REGION_STATUS_RESOLVED,
                    'region_source' => Contact::REGION_SOURCE_AI,
                    'pending_region_candidates' => null,
                ])->save();
            } elseif (count($candidateRegions) >= 2 && count($candidateRegions) <= 4) {
                $contact->forceFill([
                    'region' => null,
                    'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
                    'region_source' => null,
                    'pending_region_candidates' => array_values($candidateRegions),
                ])->save();
            } elseif (count($candidateRegions) >= 5) {
                $contact->forceFill([
                    'region' => null,
                    'region_status' => Contact::REGION_STATUS_AMBIGUOUS,
                    'region_source' => null,
                    'pending_region_candidates' => null,
                ])->save();
            } else {
                $contact->forceFill([
                    'region' => null,
                    'region_status' => Contact::REGION_STATUS_UNKNOWN,
                    'region_source' => null,
                    'pending_region_candidates' => null,
                ])->save();
            }
        }

        $contact->refresh();

        if (mb_strtolower($city) === 'москва') {
            $contact->forceFill([
                'distance_to_moscow_km' => 0,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return;
        }

        $queryResolution = $this->resolveRussianLocalityGeocodeQueryAction->handle($contact);

        if ($queryResolution['status'] === 'out_of_scope') {
            $contact->forceFill([
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return;
        }

        if ($queryResolution['status'] === 'unknown') {
            $contact->forceFill([
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return;
        }

        $contact->forceFill([
            'distance_to_moscow_km' => null,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_PENDING,
            'distance_to_moscow_calculated_at' => null,
        ])->save();
    }

    private function synchronizeDataCollectionState(Contact $contact): void
    {
        $nextField = $this->resolveNextDataCollectionFieldAction->handle($contact);

        if ($nextField === null) {
            if ($contact->data_collection_status === Contact::DATA_COLLECTION_STATUS_COMPLETED && $contact->data_collection_current_field === null) {
                return;
            }

            $contact->completeDataCollection();

            return;
        }

        if (
            $contact->data_collection_status !== Contact::DATA_COLLECTION_STATUS_ACTIVE
            || $contact->data_collection_current_field !== $nextField
        ) {
            $contact->startDataCollection($nextField);

            return;
        }

        $contact->forceFill([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => $nextField,
            'data_collection_last_prompted_field' => null,
            'data_collection_current_field_started_at' => now(),
            'data_collection_completed_at' => null,
        ])->save();
    }

    private function resolvePhoneDuplicateReviews(Contact $primary, Contact $secondary, ?string $triggerPhone): void
    {
        if ($triggerPhone === null || trim($triggerPhone) === '') {
            return;
        }

        ContactDuplicateReview::query()
            ->whereIn('contact_id', [$primary->id, $secondary->id])
            ->where('phone_normalized', $triggerPhone)
            ->whereIn('review_type', [
                ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
                ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            ])
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->update([
                'status' => ContactDuplicateReview::STATUS_RESOLVED,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function refreshDuplicateReviewStatus(Contact $contact): void
    {
        $hasOpenReviews = $contact->duplicateReviews()
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->exists();

        if ($hasOpenReviews) {
            $status = Contact::DUPLICATE_REVIEW_STATUS_PENDING;
        } elseif (
            $contact->duplicate_review_status === Contact::DUPLICATE_REVIEW_STATUS_PENDING
            || $contact->duplicateReviews()->exists()
        ) {
            $status = Contact::DUPLICATE_REVIEW_STATUS_RESOLVED;
        } else {
            $status = Contact::DUPLICATE_REVIEW_STATUS_NONE;
        }

        if ($contact->duplicate_review_status === $status) {
            return;
        }

        $contact->forceFill([
            'duplicate_review_status' => $status,
        ])->save();
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return $value;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isRussianCountry(string $country): bool
    {
        $normalized = mb_strtolower(trim($country));

        return in_array($normalized, ['россия', 'российская федерация', 'рф', 'russia'], true);
    }
}
