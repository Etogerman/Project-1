<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;

class RefreshContactDuplicateReviewStatusAction
{
    public function handle(Contact $contact): void
    {
        $hasOpenReviews = $contact->duplicateReviews()
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->exists();

        $hasOpenExternalCrossChannelCandidateReviews = ContactDuplicateReview::query()
            ->where('review_type', ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY)
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->where('contact_id', '!=', $contact->id)
            ->whereJsonContains('candidate_root_contact_ids', $contact->id)
            ->exists();

        if ($hasOpenReviews || $hasOpenExternalCrossChannelCandidateReviews) {
            $status = Contact::DUPLICATE_REVIEW_STATUS_PENDING;
        } elseif (
            $contact->duplicate_review_status === Contact::DUPLICATE_REVIEW_STATUS_PENDING
            || $contact->duplicateReviews()->exists()
        ) {
            $status = Contact::DUPLICATE_REVIEW_STATUS_RESOLVED;
        } else {
            $status = Contact::DUPLICATE_REVIEW_STATUS_NONE;
        }

        if ($contact->duplicate_review_status === $status) {
            return;
        }

        $contact->forceFill([
            'duplicate_review_status' => $status,
        ])->save();
    }
}
