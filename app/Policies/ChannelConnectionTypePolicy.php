<?php

namespace App\Policies;

use App\Models\ChannelConnectionType;
use App\Models\User;

class ChannelConnectionTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function view(User $user, ChannelConnectionType $type): bool
    {
        return $user->canManageSystem();
    }

    public function create(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function update(User $user, ChannelConnectionType $type): bool
    {
        return $user->canManageSystem();
    }

    public function delete(User $user, ChannelConnectionType $type): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
