<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewContacts($user);
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->canViewContacts($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Contact $contact): bool
    {
        return false;
    }

    public function delete(User $user, Contact $contact): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canViewContacts(User $user): bool
    {
        return $user->is_active && $user->is_admin;
    }
}
