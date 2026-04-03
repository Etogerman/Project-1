<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AssignContactTagAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact $contact, Tag $tag, User $actor): Contact
    {
        if (! $actor->canManageContactWorkspaceMutations()) {
            throw new AuthorizationException();
        }

        if (! $tag->isActive()) {
            throw new \InvalidArgumentException('Нельзя назначить неактивный тег.');
        }

        $contact = $this->resolveRootContactAction->handle($contact);

        $contact->tags()->syncWithoutDetaching([
            $tag->id => [
                'assigned_at' => now(),
                'assigned_by_user_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $contact->refresh();
    }
}
