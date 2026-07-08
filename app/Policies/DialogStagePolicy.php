<?php

namespace App\Policies;

use App\Models\DialogStage;
use App\Models\User;

class DialogStagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function view(User $user, DialogStage $dialogStage): bool
    {
        return $user->canManageSystem();
    }

    public function create(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function update(User $user, DialogStage $dialogStage): bool
    {
        return $user->canManageSystem();
    }

    public function delete(User $user, DialogStage $dialogStage): bool
    {
        return $user->canManageSystem() && ! $dialogStage->isSystemDerivedStage();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
