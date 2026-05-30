<?php

namespace App\Policies;

use App\Models\GeoCountry;
use App\Models\User;

class GeoCountryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, GeoCountry $geoCountry): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, GeoCountry $geoCountry): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, GeoCountry $geoCountry): bool
    {
        return $this->canManage($user)
            && ! $geoCountry->regions()->exists()
            && ! $geoCountry->cities()->exists();
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
