<?php

namespace App\Services\Contacts;

use App\Models\Contact;

class ResolveRootContactAction
{
    public function handle(Contact|int $contact): Contact
    {
        $current = $contact instanceof Contact
            ? $contact
            : Contact::query()->findOrFail($contact);

        $visitedContactIds = [];

        while ($current->merged_into_contact_id !== null) {
            if (isset($visitedContactIds[$current->id])) {
                throw BrokenContactMergeChainException::cycleDetected($current->id);
            }

            $visitedContactIds[$current->id] = true;

            $nextContactId = (int) $current->merged_into_contact_id;
            $next = Contact::query()->find($nextContactId);

            if (! $next instanceof Contact) {
                throw BrokenContactMergeChainException::missingMergedParent($current->id, $nextContactId);
            }

            $current = $next;
        }

        return $current;
    }
}
