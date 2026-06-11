<?php

namespace App\Policies;

use App\Models\FieldDictionaryField;
use App\Models\User;

class FieldDictionaryFieldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function view(User $user, FieldDictionaryField $field): bool
    {
        return $user->canManageSystem();
    }

    public function create(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function update(User $user, FieldDictionaryField $field): bool
    {
        return $user->canManageSystem();
    }

    public function delete(User $user, FieldDictionaryField $field): bool
    {
        return $user->canManageSystem() && ! $field->is_system && ! $field->isReferencedAsSource();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
