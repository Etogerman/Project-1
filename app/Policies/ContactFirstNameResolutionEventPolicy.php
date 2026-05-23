<?php

namespace App\Policies;

use App\Models\ContactFirstNameResolutionEvent;
use App\Models\User;

class ContactFirstNameResolutionEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewAnalytics();
    }

    public function view(User $user, ContactFirstNameResolutionEvent $event): bool
    {
        return $user->canViewAnalytics();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ContactFirstNameResolutionEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, ContactFirstNameResolutionEvent $event): bool
    {
        return false;
    }
}
