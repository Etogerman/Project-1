<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRolePermission('channels.view');
    }

    public function view(User $user, Channel $channel): bool
    {
        return $user->hasRolePermission('channels.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRolePermission('channels.edit');
    }

    public function update(User $user, Channel $channel): bool
    {
        return $user->hasRolePermission('channels.edit');
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $user->hasRolePermission('channels.edit');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
