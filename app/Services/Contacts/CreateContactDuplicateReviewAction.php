<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\Message;
use Illuminate\Database\QueryException;

class CreateContactDuplicateReviewAction
{
    /**
     * @param  list<int>  $candidateRootContactIds
     */
    public function handle(
        Contact $contact,
        string $phoneNormalized,
        string $reviewType,
        array $candidateRootContactIds = [],
        ?Message $triggerMessage = null,
        ?string $reason = null,
    ): ContactDuplicateReview {
        $candidateRootContactIds = array_values(array_unique(array_map('intval', $candidateRootContactIds)));
        sort($candidateRootContactIds);

        $existingReview = ContactDuplicateReview::query()
            ->where('contact_id', $contact->id)
            ->where('review_type', $reviewType)
            ->where('phone_normalized', $phoneNormalized)
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->first();

        if ($existingReview instanceof ContactDuplicateReview) {
            $existingReview->forceFill([
                'candidate_root_contact_ids' => $candidateRootContactIds === [] ? null : $candidateRootContactIds,
                'trigger_message_id' => $triggerMessage?->id,
                'reason' => $reason,
            ])->save();

            $this->markContactAsPending($contact);

            return $existingReview->fresh();
        }

        try {
            $review = ContactDuplicateReview::query()->create([
                'contact_id' => $contact->id,
                'phone_normalized' => $phoneNormalized,
                'review_type' => $reviewType,
                'candidate_root_contact_ids' => $candidateRootContactIds === [] ? null : $candidateRootContactIds,
                'trigger_message_id' => $triggerMessage?->id,
                'status' => ContactDuplicateReview::STATUS_OPEN,
                'reason' => $reason,
            ]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            $review = ContactDuplicateReview::query()
                ->where('contact_id', $contact->id)
                ->where('review_type', $reviewType)
                ->where('phone_normalized', $phoneNormalized)
                ->where('status', ContactDuplicateReview::STATUS_OPEN)
                ->firstOrFail();
        }

        $this->markContactAsPending($contact);

        return $review;
    }

    private function markContactAsPending(Contact $contact): void
    {
        if ($contact->duplicate_review_status === Contact::DUPLICATE_REVIEW_STATUS_PENDING) {
            return;
        }

        $contact->forceFill([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ])->save();
    }
}
