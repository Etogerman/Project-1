<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use Illuminate\Support\Collection;

class CleanupExternalDuplicateReviewsForDeletedAggregateAction
{
    public function __construct(
        private readonly RefreshContactDuplicateReviewStatusAction $refreshContactDuplicateReviewStatusAction,
    ) {}

    /**
     * @param  list<int>  $aggregateContactIds
     * @return array{reviewsTouchedCount:int,reviewsResolvedCount:int,reviewsDowngradedCount:int,contactsRefreshedCount:int}
     */
    public function handle(array $aggregateContactIds): array
    {
        $aggregateContactIds = array_values(array_unique(array_map('intval', $aggregateContactIds)));

        if ($aggregateContactIds === []) {
            return [
                'reviewsTouchedCount' => 0,
                'reviewsResolvedCount' => 0,
                'reviewsDowngradedCount' => 0,
                'contactsRefreshedCount' => 0,
            ];
        }

        /** @var Collection<int, ContactDuplicateReview> $reviews */
        $reviews = ContactDuplicateReview::query()
            ->whereNotIn('contact_id', $aggregateContactIds)
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->whereIn('review_type', [
                ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
                ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            ])
            ->where(function ($query) use ($aggregateContactIds): void {
                foreach ($aggregateContactIds as $contactId) {
                    $query->orWhereJsonContains('candidate_root_contact_ids', $contactId);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($reviews->isEmpty()) {
            return [
                'reviewsTouchedCount' => 0,
                'reviewsResolvedCount' => 0,
                'reviewsDowngradedCount' => 0,
                'contactsRefreshedCount' => 0,
            ];
        }

        $affectedContactIds = [];
        $reviewsResolvedCount = 0;
        $reviewsDowngradedCount = 0;

        foreach ($reviews as $review) {
            $candidates = $this->normalizeCandidateIds($review->candidate_root_contact_ids);
            $remainingCandidates = array_values(array_filter(
                $candidates,
                static fn (int $candidateId): bool => ! in_array($candidateId, $aggregateContactIds, true),
            ));

            sort($remainingCandidates);

            $payload = [
                'candidate_root_contact_ids' => $remainingCandidates === [] ? null : $remainingCandidates,
            ];

            if ($remainingCandidates === []) {
                $payload['status'] = ContactDuplicateReview::STATUS_RESOLVED;
                $payload['resolved_at'] = now();
                $reviewsResolvedCount++;
            } else {
                $payload['status'] = ContactDuplicateReview::STATUS_OPEN;
                $payload['resolved_at'] = null;

                if (
                    count($remainingCandidates) === 1
                    && $review->review_type === ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS
                ) {
                    $payload['review_type'] = ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE;
                    $reviewsDowngradedCount++;
                }
            }

            $review->forceFill($payload)->save();
            $affectedContactIds[$review->contact_id] = true;
        }

        $lockedContacts = Contact::query()
            ->whereKey(array_keys($affectedContactIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lockedContacts as $contact) {
            $this->refreshContactDuplicateReviewStatusAction->handle($contact);
        }

        return [
            'reviewsTouchedCount' => $reviews->count(),
            'reviewsResolvedCount' => $reviewsResolvedCount,
            'reviewsDowngradedCount' => $reviewsDowngradedCount,
            'contactsRefreshedCount' => $lockedContacts->count(),
        ];
    }

    /**
     * @param  mixed  $candidateIds
     * @return list<int>
     */
    private function normalizeCandidateIds(mixed $candidateIds): array
    {
        if (! is_array($candidateIds)) {
            return [];
        }

        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        sort($candidateIds);

        return $candidateIds;
    }
}
