<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Services\Contacts\CleanupExternalDuplicateReviewsForDeletedAggregateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupExternalDuplicateReviewsForDeletedAggregateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repoints_terminal_cross_channel_review_when_deleted_aggregate_contains_routed_contact(): void
    {
        $ownerContact = Contact::factory()->create([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $deletedRoutedContact = Contact::factory()->create();
        $survivingCandidate = Contact::factory()->create();

        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $ownerContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cleanup-cross-channel-1',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$deletedRoutedContact->id, $survivingCandidate->id],
            'routed_contact_id' => $deletedRoutedContact->id,
            'status' => ContactDuplicateReview::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        $summary = app(CleanupExternalDuplicateReviewsForDeletedAggregateAction::class)->handle([
            $deletedRoutedContact->id,
        ]);

        $review->refresh();

        $this->assertSame($ownerContact->id, $review->routed_contact_id);
        $this->assertSame([$survivingCandidate->id], $review->candidate_root_contact_ids);
        $this->assertSame(1, $summary['reviewsTouchedCount']);
        $this->assertSame(0, $summary['reviewsResolvedCount']);
        $this->assertSame(0, $summary['reviewsDowngradedCount']);
    }
}
