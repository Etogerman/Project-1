<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class IsContactReadyForBitrix24SyncAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $contact): bool
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        if (! $rootContact->isRoot()) {
            return false;
        }

        if ($rootContact->data_collection_status !== Contact::DATA_COLLECTION_STATUS_COMPLETED) {
            return false;
        }

        foreach ([$rootContact->first_name, $rootContact->city, $rootContact->country, $rootContact->age_range] as $value) {
            if (! filled($value)) {
                return false;
            }
        }

        if (! $rootContact->phoneNumbers()
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->exists()) {
            return false;
        }

        $primaryIdentity = $rootContact->primaryIdentity()->with('channel')->first();

        return $primaryIdentity !== null && $primaryIdentity->channel !== null;
    }
}
