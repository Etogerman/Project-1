<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class ReleaseContactAssignmentAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact $contact, User $actor): Contact
    {
        if (! $actor->canManageContactOwnership()) {
            throw new AuthorizationException();
        }

        $contact = $this->resolveRootContactAction->handle($contact);

        $updated = Contact::query()
            ->whereKey($contact->id)
            ->whereNotNull('assigned_user_id')
            ->update([
                'assigned_user_id' => null,
                'updated_at' => now(),
            ]);

        $freshContact = Contact::query()
            ->with('assignedUser')
            ->findOrFail($contact->id);

        if ($updated === 1) {
            return $freshContact;
        }

        if (! $freshContact->isAssigned()) {
            throw new InvalidArgumentException('Контакт уже свободен.');
        }

        throw new InvalidArgumentException('Не удалось снять контакт с работы.');
    }
}
