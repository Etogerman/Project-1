<?php

namespace App\Policies;

use App\Models\AiTask;
use App\Models\User;

class AiTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDebugAnalytics();
    }

    public function view(User $user, AiTask $aiTask): bool
    {
        return $user->canDebugAnalytics();
    }

    public function create(User $user): bool
    {
        return $user->canDebugAnalytics();
    }

    public function update(User $user, AiTask $aiTask): bool
    {
        return $user->canDebugAnalytics();
    }

    public function delete(User $user, AiTask $aiTask): bool
    {
        return $user->canDebugAnalytics() && ! $aiTask->aiRequests()->exists();
    }
}
