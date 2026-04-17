<?php

namespace App\Services\Dialogs;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class SyncDialogsStageForContactAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly SyncDialogStageAction $syncDialogStageAction,
    ) {}

    public function handle(Contact $contact, bool $writeHistory = true): void
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        $rootContact->dialogs()
            ->with(['contact.phoneNumbers', 'contact.primaryIdentity', 'currentContactIdentity'])
            ->get()
            ->each(fn ($dialog) => $this->syncDialogStageAction->handle($dialog, $writeHistory));
    }
}
