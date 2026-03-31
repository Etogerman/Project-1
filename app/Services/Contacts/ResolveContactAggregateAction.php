<?php

namespace App\Services\Contacts;

use App\Data\Contacts\ResolvedContactAggregateResult;
use App\Models\Contact;
use Illuminate\Support\Collection;

class ResolveContactAggregateAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $contact): ResolvedContactAggregateResult
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        /** @var Collection<int, Contact> $aggregateContacts */
        $aggregateContacts = collect([$rootContact]);
        $depthMap = [$rootContact->id => 0];
        $frontier = [$rootContact->id];

        while ($frontier !== []) {
            /** @var Collection<int, Contact> $children */
            $children = Contact::query()
                ->whereIn('merged_into_contact_id', $frontier)
                ->orderBy('id')
                ->get();

            $nextFrontier = [];

            foreach ($children as $child) {
                if (array_key_exists($child->id, $depthMap)) {
                    throw BrokenContactMergeChainException::cycleDetected($child->id);
                }

                $parentId = (int) $child->merged_into_contact_id;
                $parentDepth = $depthMap[$parentId] ?? null;

                if ($parentDepth === null) {
                    throw BrokenContactMergeChainException::missingMergedParent($child->id, $parentId);
                }

                $depthMap[$child->id] = $parentDepth + 1;
                $aggregateContacts->push($child);
                $nextFrontier[] = $child->id;
            }

            $frontier = $nextFrontier;
        }

        $aggregateContacts = $aggregateContacts
            ->sortBy('id')
            ->values();

        /** @var list<int> $aggregateContactIds */
        $aggregateContactIds = $aggregateContacts
            ->pluck('id')
            ->all();

        /** @var list<int> $deletionOrder */
        $deletionOrder = collect($aggregateContactIds)
            ->sort(static function (int $leftId, int $rightId) use ($depthMap): int {
                $leftDepth = $depthMap[$leftId];
                $rightDepth = $depthMap[$rightId];

                if ($leftDepth !== $rightDepth) {
                    return $rightDepth <=> $leftDepth;
                }

                return $rightId <=> $leftId;
            })
            ->values()
            ->all();

        return new ResolvedContactAggregateResult(
            rootContact: $rootContact,
            aggregateContacts: $aggregateContacts,
            aggregateContactIds: $aggregateContactIds,
            deletionOrder: $deletionOrder,
        );
    }
}
