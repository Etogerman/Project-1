<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDuplicateReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_only_one_open_review_per_contact_type_and_phone(): void
    {
        $contact = Contact::factory()->create();

        ContactDuplicateReview::factory()->create([
            'contact_id' => $contact->id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        $this->expectException(QueryException::class);

        ContactDuplicateReview::factory()->create([
            'contact_id' => $contact->id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
    }

    public function test_it_allows_creating_a_new_open_review_after_the_previous_one_is_resolved(): void
    {
        $contact = Contact::factory()->create();

        ContactDuplicateReview::factory()->create([
            'contact_id' => $contact->id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            'status' => ContactDuplicateReview::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        ContactDuplicateReview::factory()->create([
            'contact_id' => $contact->id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        $this->assertDatabaseCount('contact_duplicate_reviews', 2);
    }
}
