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

        if (! filled($contact->age_range)) {
            return Contact::DATA_COLLECTION_FIELD_AGE_RANGE;
        }

        return null;
    }
}
