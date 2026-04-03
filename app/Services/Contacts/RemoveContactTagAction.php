<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RemoveContactTagAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact $contact, Tag $tag, User $actor): Contact
    {
        if (! $actor->canManageContactWorkspaceMutations()) {
            throw new AuthorizationException();
        }

        $contact = $this->resolveRootContactAction->handle($contact);
        $contact->tags()->detach($tag->id);

        return $contact->refresh();
    }
}
