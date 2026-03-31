<?php

namespace App\Policies;

use App\Models\Dialog;
use App\Models\User;

class DialogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewDialogs($user);
    }

    public function view(User $user, Dialog $dialog): bool
    {
        return $this->canViewDialogs($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Dialog $dialog): bool
    {
        return false;
    }

    public function delete(User $user, Dialog $dialog): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canViewDialogs(User $user): bool
    {
        return $user->is_active && $user->is_admin;
    }
}
