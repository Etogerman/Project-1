<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageTags($user);
    }

    public function view(User $user, Tag $tag): bool
    {
        return $this->canManageTags($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageTags($user);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $this->canManageTags($user);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $this->canManageTags($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canManageTags(User $user): bool
    {
        return $user->canManageSystem();
    }
}
