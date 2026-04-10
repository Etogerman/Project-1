<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactTimelineEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use RuntimeException;

class AddContactTimelineCommentAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact $contact, User $actor, string $body, ?Carbon $occurredAt = null): ContactTimelineEvent
    {
        if (! $actor->canAddContactTimelineComments()) {
            throw new AuthorizationException();
        }

        $contact = $this->resolveRootContactAction->handle($contact);
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Введите комментарий.');
        }

        return $contact->timelineEvents()->create([
            'event_type' => ContactTimelineEvent::EVENT_OPERATOR_COMMENT,
            'actor_user_id' => $actor->id,
            'body' => $body,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
