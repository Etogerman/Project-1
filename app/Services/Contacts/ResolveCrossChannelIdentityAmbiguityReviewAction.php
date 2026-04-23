<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResolveCrossChannelIdentityAmbiguityReviewAction
{
    public function __construct(
        private readonly MergeContactsAction $mergeContactsAction,
        private readonly RefreshContactDuplicateReviewStatusAction $refreshContactDuplicateReviewStatusAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(ContactDuplicateReview|int $review, Contact|int $selectedContact): Contact
    {
        $reviewId = $review instanceof ContactDuplicateReview ? $review->id : (int) $review;
        $selectedContactId = $selectedContact instanceof Contact ? $selectedContact->id : (int) $selectedContact;

        return DB::transaction(function () use ($reviewId, $selectedContactId): Contact {
            $lockedReview = ContactDuplicateReview::query()
                ->whereKey($reviewId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOpenCrossChannelIdentityReview($lockedReview);

            [$anchorContact, $reviewSetContacts] = $this->lockReviewSetContacts($lockedReview);
            /** @var Contact|null $routedContact */
            $routedContact = $reviewSetContacts->get($selectedContactId);

            if (! $routedContact instanceof Contact) {
                throw new RuntimeException('Выбранный контакт не входит в набор проверки identity ambiguity.');
            }

            $lockedReview->forceFill([
                'status' => ContactDuplicateReview::STATUS_RESOLVED,
                'routed_contact_id' => $routedContact->id,
                'resolved_at' => now(),
            ])->save();

            $effectiveContact = $routedContact;

            if ($routedContact->id !== $anchorContact->id) {
                $mergeResult = $this->mergeContactsAction->handle(
                    left: $routedContact,
                    right: $anchorContact,
                    mergeReason: 'cross_channel_identity_resolution',
                    triggerPhone: null,
                    triggerMessage: $lockedReview->triggerMessage,
                    forcedPrimaryContactId: $routedContact->id,
                );

                $effectiveContact = Contact::query()
                    ->findOrFail($mergeResult->primaryContactId);
            }

            $this->refreshReviewSetStatuses($lockedReview, $reviewSetContacts);

            return $this->resolveRootContactAction->handle($effectiveContact);
        });
    }

    private function assertOpenCrossChannelIdentityReview(ContactDuplicateReview $review): void
    {
        if (! $review->isCrossChannelIdentityAmbiguity() || ! $review->isOpen()) {
            throw new RuntimeException('Для выбора root-контакта доступна только открытая cross-channel identity review.');
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
    private function refreshReviewSetStatuses(ContactDuplicateReview $review, Collection $reviewSetContacts): void
    {
        $contactIds = collect([
            ...$reviewSetContacts->keys()->all(),
            $review->routed_contact_id,
        ])
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values();

        foreach ($contactIds as $contactId) {
            $contact = Contact::query()->find($contactId);

            if (! $contact instanceof Contact) {
                continue;
            }

            $this->refreshContactDuplicateReviewStatusAction->handle($contact);
        }
    }
}
