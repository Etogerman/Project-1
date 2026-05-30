<?php

namespace App\Policies;

use App\Models\GeoRegion;
use App\Models\User;

class GeoRegionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, GeoRegion $geoRegion): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, GeoRegion $geoRegion): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, GeoRegion $geoRegion): bool
    {
        return $this->canManage($user)
            && ! $geoRegion->cities()->exists();
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
