<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\Message;
use InvalidArgumentException;
use Illuminate\Database\QueryException;

class CreateContactDuplicateReviewAction
{
    public function __construct(
        private readonly RefreshContactDuplicateReviewStatusAction $refreshContactDuplicateReviewStatusAction,
    ) {}

    /**
     * @param  list<int>  $candidateRootContactIds
     */
    public function handle(
        Contact $contact,
        ?string $phoneNormalized,
        string $reviewType,
        array $candidateRootContactIds = [],
        ?Message $triggerMessage = null,
        ?string $reason = null,
        ?string $identityKey = null,
        ?int $routedContactId = null,
        ?array $contextPayload = null,
    ): ContactDuplicateReview {
        $candidateRootContactIds = array_values(array_unique(array_map('intval', $candidateRootContactIds)));
        sort($candidateRootContactIds);

        $phoneNormalized = $this->normalizeNullableString($phoneNormalized);
        $identityKey = $this->normalizeNullableString($identityKey);

        if ($phoneNormalized === null && $identityKey === null) {
            throw new InvalidArgumentException('Either phoneNormalized or identityKey must be provided.');
        }

        $existingReview = $this->buildOpenReviewLookupQuery(
            contact: $contact,
            reviewType: $reviewType,
            phoneNormalized: $phoneNormalized,
            identityKey: $identityKey,
        )->first();

        if ($existingReview instanceof ContactDuplicateReview) {
            $previousCandidateRootContactIds = $this->normalizeCandidateRootContactIds($existingReview->candidate_root_contact_ids);

            $existingReview->forceFill([
                'candidate_root_contact_ids' => $candidateRootContactIds === [] ? null : $candidateRootContactIds,
                'context_payload' => $contextPayload === [] ? null : $contextPayload,
                'identity_key' => $identityKey,
                'phone_normalized' => $phoneNormalized,
                'routed_contact_id' => $routedContactId,
                'trigger_message_id' => $triggerMessage?->id,
                'reason' => $reason,
            ])->save();

            $this->refreshReviewParticipants(
                review: $existingReview,
                additionalContactIds: [...$previousCandidateRootContactIds, ...$candidateRootContactIds],
            );

            return $existingReview->fresh();
        }

        try {
            $review = ContactDuplicateReview::query()->create([
                'contact_id' => $contact->id,
                'phone_normalized' => $phoneNormalized,
                'identity_key' => $identityKey,
                'review_type' => $reviewType,
                'candidate_root_contact_ids' => $candidateRootContactIds === [] ? null : $candidateRootContactIds,
                'context_payload' => $contextPayload === [] ? null : $contextPayload,
                'routed_contact_id' => $routedContactId,
                'trigger_message_id' => $triggerMessage?->id,
                'status' => ContactDuplicateReview::STATUS_OPEN,
                'reason' => $reason,
            ]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            $review = $this->buildOpenReviewLookupQuery(
                contact: $contact,
                reviewType: $reviewType,
                phoneNormalized: $phoneNormalized,
                identityKey: $identityKey,
            )->firstOrFail();
        }

        $this->refreshReviewParticipants($review, $candidateRootContactIds);

        return $review;
    }

    private function buildOpenReviewLookupQuery(
        Contact $contact,
        string $reviewType,
        ?string $phoneNormalized,
        ?string $identityKey,
    ) {
        $query = ContactDuplicateReview::query()
            ->where('review_type', $reviewType)
            ->where('status', ContactDuplicateReview::STATUS_OPEN);

        if ($identityKey !== null) {
            return $query->where('identity_key', $identityKey);
        }

        return $query
            ->where('contact_id', $contact->id)
            ->where('phone_normalized', $phoneNormalized);
    }

    private function refreshReviewParticipants(ContactDuplicateReview $review, array $additionalContactIds = []): void
    {
        $contactIds = [$review->contact_id];

        if ($review->isCrossChannelIdentityAmbiguity()) {
            $contactIds = [
                ...$contactIds,
                ...$this->normalizeCandidateRootContactIds($review->candidate_root_contact_ids),
                ...$this->normalizeCandidateRootContactIds($additionalContactIds),
            ];
        }

        $contactIds = array_values(array_unique(array_filter(
            array_map('intval', $contactIds),
            static fn (int $id): bool => $id > 0,
        )));

        foreach ($contactIds as $contactId) {
            $participant = Contact::query()->find($contactId);

            if (! $participant instanceof Contact) {
                continue;
            }

            $this->refreshContactDuplicateReviewStatusAction->handle($participant);
        }
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  mixed  $candidateRootContactIds
     * @return list<int>
     */
    private function normalizeCandidateRootContactIds(mixed $candidateRootContactIds): array
    {
        if (! is_array($candidateRootContactIds)) {
            return [];
        }

        $candidateRootContactIds = array_values(array_unique(array_map('intval', $candidateRootContactIds)));
        sort($candidateRootContactIds);

        return $candidateRootContactIds;
    }
}
