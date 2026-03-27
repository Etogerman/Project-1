<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageChannels($user);
    }

    public function view(User $user, Channel $channel): bool
    {
        return $this->canManageChannels($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageChannels($user);
    }

    public function update(User $user, Channel $channel): bool
    {
        return $this->canManageChannels($user);
    }

    public function delete(User $user, Channel $channel): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canManageChannels(User $user): bool
    {
        return $user->is_active && $user->is_admin;
    }
}
