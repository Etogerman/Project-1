<?php

namespace App\Policies;

use App\Models\AutoReplyRule;
use App\Models\User;

class AutoReplyRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRolePermission('auto_reply_rules.view');
    }

    public function view(User $user, AutoReplyRule $autoReplyRule): bool
    {
        return $user->hasRolePermission('auto_reply_rules.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRolePermission('auto_reply_rules.edit');
    }

    public function update(User $user, AutoReplyRule $autoReplyRule): bool
    {
        return $user->hasRolePermission('auto_reply_rules.edit');
    }

    public function delete(User $user, AutoReplyRule $autoReplyRule): bool
    {
        return $user->hasRolePermission('auto_reply_rules.delete');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
