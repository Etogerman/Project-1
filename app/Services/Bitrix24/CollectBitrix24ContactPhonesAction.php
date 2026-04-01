<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class CollectBitrix24ContactPhonesAction
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
        $phones = [];

        foreach ($rootContact->phoneNumbers()->get() as $phoneNumber) {
            $phone = trim((string) $phoneNumber->phone_normalized);

            if ($phone === '' || isset($phones[$phone])) {
                continue;
            }

            $phones[$phone] = $phone;
        }

        return array_values($phones);
    }
}
