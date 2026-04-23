<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DismissCrossChannelIdentityAmbiguityReviewAction
{
    public function __construct(
        private readonly RefreshContactDuplicateReviewStatusAction $refreshContactDuplicateReviewStatusAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(ContactDuplicateReview|int $review): Contact
    {
        $reviewId = $review instanceof ContactDuplicateReview ? $review->id : (int) $review;

        return DB::transaction(function () use ($reviewId): Contact {
            $lockedReview = ContactDuplicateReview::query()
                ->whereKey($reviewId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOpenCrossChannelIdentityReview($lockedReview);

            [$anchorContact, $reviewSetContacts] = $this->lockReviewSetContacts($lockedReview);

            $lockedReview->forceFill([
                'status' => ContactDuplicateReview::STATUS_DISMISSED,
                'routed_contact_id' => $anchorContact->id,
                'resolved_at' => now(),
            ])->save();

            $this->refreshReviewSetStatuses($reviewSetContacts);

            return $this->resolveRootContactAction->handle($anchorContact);
        });
    }

    private function assertOpenCrossChannelIdentityReview(ContactDuplicateReview $review): void
    {
        if (! $review->isCrossChannelIdentityAmbiguity() || ! $review->isOpen()) {
            throw new RuntimeException('Dismiss доступен только для открытой cross-channel identity review.');
        }
    }

    /**
     * @return array{0: Contact, 1: Collection<int, Contact>}
     */
    private function lockReviewSetContacts(ContactDuplicateReview $review): array
    {
        $reviewSetIds = collect([
            $review->contact_id,
            ...($review->candidate_root_contact_ids ?? []),
        ])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        /** @var Collection<int, Contact> $contacts */
        $contacts = Contact::query()
            ->whereKey($reviewSetIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var Contact|null $anchorContact */
        $anchorContact = $contacts->get((int) $review->contact_id);

        if (! $anchorContact instanceof Contact) {
            throw new RuntimeException('Anchor contact для cross-channel identity review не найден.');
        }

        return [$anchorContact, $contacts];
    }

    /**
     * @param  Collection<int, Contact>  $reviewSetContacts
     */
    private function refreshReviewSetStatuses(Collection $reviewSetContacts): void
    {
        foreach ($reviewSetContacts as $contact) {
            $this->refreshContactDuplicateReviewStatusAction->handle($contact);
        }
    }
}
