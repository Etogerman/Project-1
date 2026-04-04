<?php

namespace App\Policies;

use App\Models\Bitrix24Connection;
use App\Models\User;

class Bitrix24ConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRolePermission('bitrix24.view');
    }

    public function view(User $user, Bitrix24Connection $connection): bool
    {
        return $user->hasRolePermission('bitrix24.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Bitrix24Connection $connection): bool
    {
        return false;
    }

    public function delete(User $user, Bitrix24Connection $connection): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
