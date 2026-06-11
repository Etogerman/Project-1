<?php

namespace App\Policies;

use App\Models\Dialog;
use App\Models\User;

class DialogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRolePermission('dialogs.view');
    }

    public function view(User $user, Dialog $dialog): bool
    {
        return $user->hasRolePermission('dialogs.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Dialog $dialog): bool
    {
        return $user->hasRolePermission('dialogs.edit');
    }

    public function delete(User $user, Dialog $dialog): bool
    {
        return $user->hasRolePermission('dialogs.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRolePermission('dialogs.delete');
    }
}
