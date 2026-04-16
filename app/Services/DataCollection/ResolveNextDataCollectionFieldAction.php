<?php

namespace App\Services\DataCollection;

use App\Models\Contact;

class ResolveNextDataCollectionFieldAction
{
    public function handle(Contact $contact): ?string
    {
        if (! $this->hasCollectedFirstName($contact)) {
            return Contact::DATA_COLLECTION_FIELD_FIRST_NAME;
        }

        if (! filled($contact->city) && ! filled($contact->country)) {
            return Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY;
        }

        if (! filled($contact->city)) {
            return Contact::DATA_COLLECTION_FIELD_CITY;
        }

        if (! filled($contact->country)) {
            return Contact::DATA_COLLECTION_FIELD_COUNTRY;
        }

        $candidateCount = is_array($contact->pending_region_candidates)
            ? count($contact->pending_region_candidates)
            : 0;

        if (
            ! filled($contact->region)
            && $candidateCount >= 2
            && (
                ($contact->region_status === Contact::REGION_STATUS_CLARIFICATION_PENDING && $candidateCount <= 4)
                || ($contact->region_status === Contact::REGION_STATUS_AMBIGUOUS && $candidateCount >= 5)
            )
        ) {
            return Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM;
        }

        if (! filled($contact->age_range)) {
            return Contact::DATA_COLLECTION_FIELD_AGE_RANGE;
        }

        return null;
    }

    private function hasCollectedFirstName(Contact $contact): bool
    {
        if (! filled($contact->first_name)) {
            return false;
        }

        return $contact->first_name_source !== Contact::FIRST_NAME_SOURCE_AUTO;
    }
}
