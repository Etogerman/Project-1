<?php

namespace App\Services\Contacts;

use App\Data\Contacts\SelectedMergeContactsResult;
use App\Models\Contact;

class SelectPrimaryContactForMergeAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $left, Contact|int $right): ?SelectedMergeContactsResult
    {
        $leftRoot = $this->resolveRootContactAction->handle($left);
        $rightRoot = $this->resolveRootContactAction->handle($right);

        if ($leftRoot->id === $rightRoot->id) {
            return null;
        }

        $contacts = Contact::query()
            ->whereKey([$leftRoot->id, $rightRoot->id])
            ->withCount('messages')
            ->get()
            ->keyBy('id');

        /** @var Contact $leftCandidate */
        $leftCandidate = $contacts->get($leftRoot->id, $leftRoot);
        /** @var Contact $rightCandidate */
        $rightCandidate = $contacts->get($rightRoot->id, $rightRoot);

        $comparison = $this->compareContacts($leftCandidate, $rightCandidate);

        return $comparison <= 0
            ? new SelectedMergeContactsResult(primary: $leftCandidate, secondary: $rightCandidate)
            : new SelectedMergeContactsResult(primary: $rightCandidate, secondary: $leftCandidate);
    }

    private function compareContacts(Contact $left, Contact $right): int
    {
        $leftCompleteness = $this->profileCompletenessScore($left);
        $rightCompleteness = $this->profileCompletenessScore($right);

        if ($leftCompleteness !== $rightCompleteness) {
            return $rightCompleteness <=> $leftCompleteness;
        }

        $leftMessages = (int) ($left->messages_count ?? $left->messages()->count());
        $rightMessages = (int) ($right->messages_count ?? $right->messages()->count());

        if ($leftMessages !== $rightMessages) {
            return $rightMessages <=> $leftMessages;
        }

        $leftTimestamp = $left->created_at?->getTimestamp() ?? PHP_INT_MAX;
        $rightTimestamp = $right->created_at?->getTimestamp() ?? PHP_INT_MAX;

        if ($leftTimestamp !== $rightTimestamp) {
            return $leftTimestamp <=> $rightTimestamp;
        }

        return $left->id <=> $right->id;
    }

    private function profileCompletenessScore(Contact $contact): int
    {
        $score = 0;

        foreach ([
            $contact->first_name,
            $contact->last_name,
            $contact->gender,
            $contact->birth_date,
            $contact->age_years,
            $contact->age_range,
            $contact->country,
            $contact->city,
            $contact->region,
        ] as $value) {
            if (filled($value)) {
                $score++;
            }
        }

        return $score;
    }
}
