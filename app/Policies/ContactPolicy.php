<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRolePermission('contacts.view');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->hasRolePermission('contacts.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->hasRolePermission('contacts.edit');
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->hasRolePermission('contacts.delete');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
