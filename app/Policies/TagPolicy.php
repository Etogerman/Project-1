<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRolePermission('tags.view');
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->hasRolePermission('tags.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRolePermission('tags.edit');
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->hasRolePermission('tags.edit');
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->hasRolePermission('tags.delete');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
