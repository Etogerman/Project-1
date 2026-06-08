<?php

namespace App\Policies;

use App\Models\GeoAlias;
use App\Models\User;

class GeoAliasPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, GeoAlias $geoAlias): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, GeoAlias $geoAlias): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, GeoAlias $geoAlias): bool
    {
        return $this->canManage($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->canManageSystem();
    }
}
