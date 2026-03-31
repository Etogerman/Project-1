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
}
