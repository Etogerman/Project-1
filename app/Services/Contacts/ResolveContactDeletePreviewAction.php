<?php

namespace App\Services\Contacts;

use App\Data\Contacts\ResolvedContactDeletePreviewResult;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;

class ResolveContactDeletePreviewAction
{
    public function __construct(
        private readonly ResolveContactAggregateAction $resolveContactAggregateAction,
    ) {}

    public function handle(Contact|int $contact): ResolvedContactDeletePreviewResult
    {
        $resolvedAggregate = $this->resolveContactAggregateAction->handle($contact);

        return new ResolvedContactDeletePreviewResult(
            rootContact: $resolvedAggregate->rootContact,
            contactsCount: count($resolvedAggregate->aggregateContactIds),
            dialogsCount: Dialog::query()
                ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                ->count(),
            messagesCount: Message::query()
                ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                ->count(),
            phonesCount: ContactPhoneNumber::query()
                ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                ->count(),
            identitiesCount: ContactIdentity::query()
                ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                ->count(),
            hasMergeHistory: count($resolvedAggregate->aggregateContactIds) > 1,
        );
    }
}
