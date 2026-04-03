<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class SetContactAssigneeAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact $contact, User $actor, ?int $assigneeId): Contact
    {
        if (! $actor->canManageContactOwnership()) {
            throw new AuthorizationException();
        }

        $contact = $this->resolveRootContactAction->handle($contact);

        $assignee = null;

        if ($assigneeId !== null) {
            $assignee = User::query()
                ->whereKey($assigneeId)
                ->where('is_active', true)
                ->first();

            if (! $assignee instanceof User || ! $assignee->canBeAssignedToContacts()) {
                throw new InvalidArgumentException('Не удалось выбрать ответственного сотрудника.');
            }
        }

        Contact::query()
            ->whereKey($contact->id)
            ->update([
                'assigned_user_id' => $assignee?->id,
                'updated_at' => now(),
            ]);

        return Contact::query()
            ->with('assignedUser')
            ->findOrFail($contact->id);
    }
}
