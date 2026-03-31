<?php

namespace App\Services\Contacts;

use App\Models\Contact;

class DeleteContactAction
{
    public function __construct(
        private readonly DeleteContactAggregateAction $deleteContactAggregateAction,
    ) {}

    public function handle(Contact $contact): void
    {
        $this->deleteContactAggregateAction->handle($contact);
    }
}
