<?php

namespace App\Policies;

use App\Models\AutoReplyCategory;
use App\Models\User;

class AutoReplyCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageCategories($user);
    }

    public function view(User $user, AutoReplyCategory $autoReplyCategory): bool
    {
        return $this->canManageCategories($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageCategories($user);
    }

    public function update(User $user, AutoReplyCategory $autoReplyCategory): bool
    {
        return $this->canManageCategories($user);
    }

    public function delete(User $user, AutoReplyCategory $autoReplyCategory): bool
    {
        return $this->canManageCategories($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canManageCategories(User $user): bool
    {
        return $user->canManageSystem();
    }
}
