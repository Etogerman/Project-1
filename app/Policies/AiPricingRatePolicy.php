<?php

namespace App\Policies;

use App\Models\AiPricingRate;
use App\Models\User;

class AiPricingRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDebugAnalytics();
    }

    public function view(User $user, AiPricingRate $aiPricingRate): bool
    {
        return $user->canDebugAnalytics();
    }

    public function create(User $user): bool
    {
        return $user->canDebugAnalytics();
    }

    public function update(User $user, AiPricingRate $aiPricingRate): bool
    {
        return $user->canDebugAnalytics();
    }

    public function delete(User $user, AiPricingRate $aiPricingRate): bool
    {
        return $user->canDebugAnalytics();
    }
}
