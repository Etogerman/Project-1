<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\Message;
use App\Services\Contacts\CreateContactDuplicateReviewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateContactDuplicateReviewActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_duplicate_review_and_marks_contact_as_pending(): void
    {
        $contact = Contact::factory()->create();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
        ]);

        $review = app(CreateContactDuplicateReviewAction::class)->handle(
            contact: $contact,
            phoneNormalized: '+79991234567',
            reviewType: ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            candidateRootContactIds: [11, 22],
            triggerMessage: $message,
            reason: 'Potential duplicate detected.',
        );

        $this->assertSame(ContactDuplicateReview::STATUS_OPEN, $review->status);
        $this->assertSame([11, 22], $review->candidate_root_contact_ids);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $contact->fresh()->duplicate_review_status);
    }

    public function test_it_returns_the_existing_open_review_instead_of_creating_a_duplicate(): void
    {
        $contact = Contact::factory()->create();

        $first = app(CreateContactDuplicateReviewAction::class)->handle(
            contact: $contact,
            phoneNormalized: '+79991234567',
            reviewType: ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            candidateRootContactIds: [10],
            reason: 'First reason.',
        );

        $second = app(CreateContactDuplicateReviewAction::class)->handle(
            contact: $contact,
            phoneNormalized: '+79991234567',
            reviewType: ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            candidateRootContactIds: [10, 20],
            reason: 'Updated reason.',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('contact_duplicate_reviews', 1);
        $this->assertSame([10, 20], $second->fresh()->candidate_root_contact_ids);
        $this->assertSame('Updated reason.', $second->fresh()->reason);
    }

    public function test_it_reuses_existing_open_cross_channel_identity_review_by_identity_key(): void
    {
        $anchorContact = Contact::factory()->create();
        $newContact = Contact::factory()->create();

        $existingReview = ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-100',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [10],
            'context_payload' => ['last_seen_channel_id' => 1],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        $reusedReview = app(CreateContactDuplicateReviewAction::class)->handle(
            contact: $newContact,
            phoneNormalized: null,
            reviewType: ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            candidateRootContactIds: [10, 20],
            reason: 'Updated ambiguity context.',
            identityKey: 'telegram:cross-user-100',
            contextPayload: ['last_seen_channel_id' => 2],
        );

        $this->assertSame($existingReview->id, $reusedReview->id);
        $this->assertSame($anchorContact->id, $reusedReview->contact_id);
        $this->assertSame([10, 20], $reusedReview->fresh()->candidate_root_contact_ids);
        $this->assertSame(['last_seen_channel_id' => 2], $reusedReview->fresh()->context_payload);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $anchorContact->fresh()->duplicate_review_status);
        $this->assertDatabaseCount('contact_duplicate_reviews', 1);
    }

    public function test_it_marks_cross_channel_candidate_roots_as_pending(): void
    {
        $anchorContact = Contact::factory()->create();
        $candidateRoot = Contact::factory()->create();

        app(CreateContactDuplicateReviewAction::class)->handle(
            contact: $anchorContact,
            phoneNormalized: null,
            reviewType: ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            candidateRootContactIds: [$candidateRoot->id],
            identityKey: 'telegram:cross-user-700',
        );

        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $anchorContact->fresh()->duplicate_review_status);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $candidateRoot->fresh()->duplicate_review_status);
    }

    public function test_it_refreshes_removed_cross_channel_candidate_root_out_of_pending_state_when_review_is_reused(): void
    {
        $anchorContact = Contact::factory()->create();
        $keptCandidateRoot = Contact::factory()->create();
        $removedCandidateRoot = Contact::factory()->create();

        app(CreateContactDuplicateReviewAction::class)->handle(
            contact: $anchorContact,
            phoneNormalized: null,
            reviewType: ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            candidateRootContactIds: [$keptCandidateRoot->id, $removedCandidateRoot->id],
            identityKey: 'telegram:cross-user-701',
        );

        app(CreateContactDuplicateReviewAction::class)->handle(
            contact: $anchorContact,
            phoneNormalized: null,
            reviewType: ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            candidateRootContactIds: [$keptCandidateRoot->id],
            identityKey: 'telegram:cross-user-701',
        );

        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $anchorContact->fresh()->duplicate_review_status);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $keptCandidateRoot->fresh()->duplicate_review_status);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_RESOLVED, $removedCandidateRoot->fresh()->duplicate_review_status);
    }
}
