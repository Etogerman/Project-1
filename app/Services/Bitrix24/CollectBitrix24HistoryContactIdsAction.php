<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class CollectBitrix24HistoryContactIdsAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    /**
     * @return list<int>
     */
    public function handle(Contact|int $contact): array
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $collectedIds = [$rootContact->id => true];
        $currentParentIds = [$rootContact->id];

        while ($currentParentIds !== []) {
            $childIds = Contact::query()
                ->whereIn('merged_into_contact_id', $currentParentIds)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $nextParentIds = [];

            foreach ($childIds as $childId) {
                if (isset($collectedIds[$childId])) {
                    continue;
                }

                $collectedIds[$childId] = true;
                $nextParentIds[] = $childId;
            }

            $currentParentIds = $nextParentIds;
        }

        $contactIds = array_map('intval', array_keys($collectedIds));
        sort($contactIds);

        return $contactIds;
    }
}
