<?php

namespace App\Services\Contacts;

use App\Data\Contacts\ResolvedContactAggregateResult;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;

class BuildContactAggregateDeleteSummaryAction
{
    /**
     * @return array{
     *     root_contact_id:int,
     *     root_contact_label:string,
     *     aggregate_contact_ids:list<int>,
     *     contacts_count:int,
     *     dialogs_count:int,
     *     messages_count:int,
     *     phones_count:int,
     *     identities_count:int,
     *     had_merge_history:bool
     * }
     */
    public function handle(ResolvedContactAggregateResult $resolvedAggregate): array
    {
        return [
            'root_contact_id' => $resolvedAggregate->rootContact->id,
            'root_contact_label' => $resolvedAggregate->rootContact->display_name,
            'aggregate_contact_ids' => $resolvedAggregate->aggregateContactIds,
            'contacts_count' => count($resolvedAggregate->aggregateContactIds),
            'dialogs_count' => Dialog::query()
                ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                ->count(),
            'messages_count' => Message::query()
                ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                ->count(),
            'phones_count' => ContactPhoneNumber::query()
                ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                ->count(),
            'identities_count' => ContactIdentity::query()
                ->whereIn('contact_id', $resolvedAggregate->aggregateContactIds)
                ->count(),
            'had_merge_history' => count($resolvedAggregate->aggregateContactIds) > 1,
        ];
    }
}
