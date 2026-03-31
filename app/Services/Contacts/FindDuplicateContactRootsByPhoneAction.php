<?php

namespace App\Services\Contacts;

use App\Data\Contacts\FoundDuplicateContactRootsResult;
use App\Models\Contact;
use App\Models\ContactPhoneNumber;

class FindDuplicateContactRootsByPhoneAction
{
    public function __construct(
        private readonly NormalizePhoneNumberAction $normalizePhoneNumberAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(string $phoneRawOrNormalized, Contact|int|null $currentContact = null): FoundDuplicateContactRootsResult
    {
        $phoneNormalized = $this->normalizePhoneNumberAction->handle($phoneRawOrNormalized);

        if ($phoneNormalized === '') {
            return new FoundDuplicateContactRootsResult(
                phoneNormalized: '',
                currentRootContactId: null,
                matchedRootContactIds: [],
                matchedRootCount: 0,
                hasMatches: false,
                hasSingleOtherRoot: false,
                hasMultipleOtherRoots: false,
            );
        }

        $currentRootContactId = null;

        if ($currentContact !== null) {
            $currentRootContactId = $this->resolveRootContactAction->handle($currentContact)->id;
        }

        $matchedRootContactIds = [];
        $contactIds = ContactPhoneNumber::query()
            ->where('phone_normalized', $phoneNormalized)
            ->pluck('contact_id');

        foreach ($contactIds as $contactId) {
            $rootContactId = $this->resolveRootContactAction->handle((int) $contactId)->id;

            if ($currentRootContactId !== null && $rootContactId === $currentRootContactId) {
                continue;
            }

            $matchedRootContactIds[$rootContactId] = $rootContactId;
        }

        $matchedRootContactIds = array_values($matchedRootContactIds);
        sort($matchedRootContactIds);
        $matchedRootCount = count($matchedRootContactIds);

        return new FoundDuplicateContactRootsResult(
            phoneNormalized: $phoneNormalized,
            currentRootContactId: $currentRootContactId,
            matchedRootContactIds: $matchedRootContactIds,
            matchedRootCount: $matchedRootCount,
            hasMatches: $matchedRootCount > 0,
            hasSingleOtherRoot: $matchedRootCount === 1,
            hasMultipleOtherRoots: $matchedRootCount > 1,
        );
    }
}
