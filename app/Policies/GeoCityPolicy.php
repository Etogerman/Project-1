<?php

namespace App\Policies;

use App\Models\GeoCity;
use App\Models\User;

class GeoCityPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, GeoCity $geoCity): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, GeoCity $geoCity): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, GeoCity $geoCity): bool
    {
        return $this->canManage($user)
            && ! $geoCity->aliases()->exists();
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
