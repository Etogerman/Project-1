<?php

namespace App\Data\Contacts;

use App\Models\Contact;
use Illuminate\Support\Collection;

final readonly class ResolvedContactAggregateResult
{
    /**
     * @param  Collection<int, Contact>  $aggregateContacts
     * @param  list<int>  $aggregateContactIds
     * @param  list<int>  $deletionOrder
     */
    public function __construct(
        public Contact $rootContact,
        public Collection $aggregateContacts,
        public array $aggregateContactIds,
        public array $deletionOrder,
    ) {}
}
