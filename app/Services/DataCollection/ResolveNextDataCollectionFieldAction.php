<?php

namespace App\Services\DataCollection;

use App\Models\Contact;

class ResolveNextDataCollectionFieldAction
{
    public function handle(Contact $contact): ?string
    {
        if (! filled($contact->first_name)) {
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

        if (
            $contact->region_status === Contact::REGION_STATUS_CLARIFICATION_PENDING
            && is_array($contact->pending_region_candidates)
            && count($contact->pending_region_candidates) >= 2
            && count($contact->pending_region_candidates) <= 4
            && ! filled($contact->region)
        ) {
            return Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM;
        }

        if (! filled($contact->age_range)) {
            return Contact::DATA_COLLECTION_FIELD_AGE_RANGE;
        }

        return null;
    }
}
