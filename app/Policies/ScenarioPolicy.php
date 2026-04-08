<?php

namespace App\Policies;

use App\Models\Scenario;
use App\Models\User;

class ScenarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRolePermission('scenarios.view');
    }

    public function view(User $user, Scenario $scenario): bool
    {
        return $user->hasRolePermission('scenarios.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRolePermission('scenarios.edit');
    }

    public function update(User $user, Scenario $scenario): bool
    {
        return $user->hasRolePermission('scenarios.edit');
    }

    public function archive(User $user, Scenario $scenario): bool
    {
        return $user->hasRolePermission('scenarios.archive');
    }

    public function delete(User $user, Scenario $scenario): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
