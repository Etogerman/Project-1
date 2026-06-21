<?php

namespace App\Services\Contacts;

use App\Models\Contact;

class ResolveContactDataCollectionCompletionRequirementsAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    /**
     * @return list<string>
     */
    public function handle(Contact|int $contact): array
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $missing = [];

        if (! $rootContact->isRoot()) {
            $missing[] = 'root_contact';
        }

        foreach ([
            'first_name' => $rootContact->first_name,
            'country' => $rootContact->country,
            'city' => $rootContact->city,
            'age_range' => $rootContact->age_range,
        ] as $field => $value) {
            if (! filled($value)) {
                $missing[] = $field;
            }
        }

        if (! $rootContact->phoneNumbers()
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->exists()) {
            $missing[] = 'phone';
        }

        $primaryIdentity = $rootContact->primaryIdentity()->with('channel')->first();

        if ($primaryIdentity === null || $primaryIdentity->channel === null) {
            $missing[] = 'primary_identity';
        }

        return $missing;
    }
}
