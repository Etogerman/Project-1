<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactMergeLog;
use App\Models\Message;
use App\Services\Contacts\DismissCrossChannelIdentityAmbiguityReviewAction;
use App\Services\Contacts\ResolveCrossChannelIdentityAmbiguityReviewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossChannelIdentityAmbiguityReviewLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_cross_channel_identity_review_into_selected_root_and_merges_anchor(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $anchorContact = Contact::factory()->create([
            'name' => 'Anchor contact',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $selectedRootContact = Contact::factory()->create([
            'name' => 'Selected root',
        ]);

        $anchorIdentity = ContactIdentity::factory()->create([
            'contact_id' => $anchorContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'cross-user-resolve-1',
        ]);
        $triggerMessage = Message::factory()->create([
            'contact_identity_id' => $anchorIdentity->id,
            'contact_id' => $anchorContact->id,
            'channel_id' => $channel->id,
        ]);
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-resolve-1',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$selectedRootContact->id],
            'trigger_message_id' => $triggerMessage->id,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        $effectiveContact = app(ResolveCrossChannelIdentityAmbiguityReviewAction::class)->handle(
            review: $review,
            selectedContact: $selectedRootContact,
        );

        $review->refresh();
        $anchorContact->refresh();
        $selectedRootContact->refresh();
        $anchorIdentity->refresh();

        $this->assertSame($selectedRootContact->id, $effectiveContact->id);
        $this->assertSame(ContactDuplicateReview::STATUS_RESOLVED, $review->status);
        $this->assertSame($selectedRootContact->id, $review->routed_contact_id);
        $this->assertNotNull($review->resolved_at);
        $this->assertSame($selectedRootContact->id, $anchorContact->merged_into_contact_id);
        $this->assertSame($selectedRootContact->id, $anchorIdentity->contact_id);
        $this->assertDatabaseHas('contact_merge_logs', [
            'primary_contact_id' => $selectedRootContact->id,
            'secondary_contact_id' => $anchorContact->id,
            'merge_reason' => 'cross_channel_identity_resolution',
            'trigger_message_id' => $triggerMessage->id,
            'created_by_type' => ContactMergeLog::CREATED_BY_TYPE_SYSTEM,
        ]);
    }

    public function test_it_resolves_cross_channel_identity_review_into_anchor_without_merge(): void
    {
        $anchorContact = Contact::factory()->create([
            'name' => 'Anchor root',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $candidateRoot = Contact::factory()->create([
            'name' => 'Candidate root',
        ]);
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-resolve-2',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$candidateRoot->id],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        $effectiveContact = app(ResolveCrossChannelIdentityAmbiguityReviewAction::class)->handle(
            review: $review,
            selectedContact: $anchorContact,
        );

        $review->refresh();
        $anchorContact->refresh();

        $this->assertSame($anchorContact->id, $effectiveContact->id);
        $this->assertSame(ContactDuplicateReview::STATUS_RESOLVED, $review->status);
        $this->assertSame($anchorContact->id, $review->routed_contact_id);
        $this->assertNull($anchorContact->merged_into_contact_id);
        $this->assertDatabaseCount('contact_merge_logs', 0);
    }

    public function test_it_dismisses_cross_channel_identity_review_and_keeps_anchor_as_routed_contact(): void
    {
        $anchorContact = Contact::factory()->create([
            'name' => 'Anchor dismiss',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $candidateRoot = Contact::factory()->create([
            'name' => 'Candidate dismiss',
        ]);
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-dismiss-1',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$candidateRoot->id],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        $effectiveContact = app(DismissCrossChannelIdentityAmbiguityReviewAction::class)->handle($review);

        $review->refresh();
        $anchorContact->refresh();

        $this->assertSame($anchorContact->id, $effectiveContact->id);
        $this->assertSame(ContactDuplicateReview::STATUS_DISMISSED, $review->status);
        $this->assertSame($anchorContact->id, $review->routed_contact_id);
        $this->assertNotNull($review->resolved_at);
        $this->assertNull($anchorContact->merged_into_contact_id);
        $this->assertDatabaseCount('contact_merge_logs', 0);
    }
}
