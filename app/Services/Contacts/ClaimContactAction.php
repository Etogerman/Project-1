<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class ClaimContactAction
{
    public function handle(Contact $contact, User $actor): Contact
    {
        if (! $actor->is_active || ! $actor->is_admin) {
            throw new AuthorizationException();
        }

        $updated = Contact::query()
            ->whereKey($contact->id)
            ->whereNull('assigned_user_id')
            ->update([
                'assigned_user_id' => $actor->id,
                'updated_at' => now(),
            ]);

        $freshContact = Contact::query()
            ->with('assignedUser')
            ->findOrFail($contact->id);

        if ($updated === 1) {
            return $freshContact;
        }

        if ($freshContact->isAssignedTo($actor)) {
            throw new InvalidArgumentException('Контакт уже у вас в работе.');
        }

        throw new InvalidArgumentException('Контакт уже взят в работу другим сотрудником.');
    }
}
