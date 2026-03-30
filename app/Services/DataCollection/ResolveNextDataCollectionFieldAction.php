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

        if (! filled($contact->country)) {
            return Contact::DATA_COLLECTION_FIELD_COUNTRY;
        }

        if (! filled($contact->city)) {
            return Contact::DATA_COLLECTION_FIELD_CITY;
        }

        return null;
    }
}
