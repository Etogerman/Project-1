<?php

namespace App\Policies;

use App\Models\AiProcessor;
use App\Models\User;

class AiProcessorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function view(User $user, AiProcessor $aiProcessor): bool
    {
        return $user->canManageSystem();
    }

    public function create(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function update(User $user, AiProcessor $aiProcessor): bool
    {
        return $user->canManageSystem();
    }

    public function delete(User $user, AiProcessor $aiProcessor): bool
    {
        return $user->canManageSystem();
    }
}
