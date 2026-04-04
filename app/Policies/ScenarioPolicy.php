<?php

namespace App\Policies;

use App\Models\Scenario;
use App\Models\User;

class ScenarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageScenarios($user);
    }

    public function view(User $user, Scenario $scenario): bool
    {
        return $this->canManageScenarios($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageScenarios($user);
    }

    public function update(User $user, Scenario $scenario): bool
    {
        return $this->canManageScenarios($user);
    }

    public function delete(User $user, Scenario $scenario): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canManageScenarios(User $user): bool
    {
        return $user->canManageSystem();
    }
}
