<?php

namespace App\Policies;

use App\Models\AutoReplyRule;
use App\Models\User;

class AutoReplyRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageRules($user);
    }

    public function view(User $user, AutoReplyRule $autoReplyRule): bool
    {
        return $this->canManageRules($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageRules($user);
    }

    public function update(User $user, AutoReplyRule $autoReplyRule): bool
    {
        return $this->canManageRules($user);
    }

    public function delete(User $user, AutoReplyRule $autoReplyRule): bool
    {
        return $this->canManageRules($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canManageRules(User $user): bool
    {
        return $user->canManageSystem();
    }
}
