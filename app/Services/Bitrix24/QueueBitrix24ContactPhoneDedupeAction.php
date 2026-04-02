<?php

namespace App\Services\Bitrix24;

use App\Jobs\DedupeBitrix24ContactPhonesJob;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class QueueBitrix24ContactPhoneDedupeAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $contact): bool
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        if (! filled($rootContact->bitrix24_contact_id)) {
            return false;
        }

        DedupeBitrix24ContactPhonesJob::dispatch($rootContact->id)->afterCommit();

        return true;
    }
}
