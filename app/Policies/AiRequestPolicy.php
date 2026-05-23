<?php

namespace App\Policies;

use App\Models\AiRequest;
use App\Models\User;

class AiRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewAnalytics();
    }

    public function view(User $user, AiRequest $aiRequest): bool
    {
        return $user->canViewAnalytics();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AiRequest $aiRequest): bool
    {
        return false;
    }

    public function delete(User $user, AiRequest $aiRequest): bool
    {
        return false;
    }
}
