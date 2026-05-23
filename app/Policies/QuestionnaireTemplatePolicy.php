<?php

namespace App\Policies;

use App\Models\QuestionnaireTemplate;
use App\Models\User;

class QuestionnaireTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function view(User $user, QuestionnaireTemplate $template): bool
    {
        return $user->canManageSystem();
    }

    public function create(User $user): bool
    {
        return $user->canManageSystem();
    }

    public function update(User $user, QuestionnaireTemplate $template): bool
    {
        return $user->canManageSystem();
    }

    public function delete(User $user, QuestionnaireTemplate $template): bool
    {
        return $user->canManageSystem() && ! $template->runs()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
