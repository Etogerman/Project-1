<?php

namespace App\Policies;

use App\Models\DataDictionaryEntry;
use App\Models\User;

class DataDictionaryEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function view(User $user, DataDictionaryEntry $dataDictionaryEntry): bool
    {
        return $user->canManageSystem();
    }

    public function create(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function update(User $user, DataDictionaryEntry $dataDictionaryEntry): bool
    {
        return $user->canManageSystem();
    }

    public function delete(User $user, DataDictionaryEntry $dataDictionaryEntry): bool
    {
        return $user->canManageSystem();
    }

    public function deleteAny(User $user): bool
    {
        return $user->canManageSystem();
    }
}
