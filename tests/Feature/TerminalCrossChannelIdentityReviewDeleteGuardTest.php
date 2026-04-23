<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Services\Contacts\ContactPinnedByTerminalCrossChannelIdentityReviewException;
use App\Services\Contacts\DeleteContactAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalCrossChannelIdentityReviewDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_contact_action_blocks_when_aggregate_contains_owner_of_resolved_cross_channel_identity_review(): void
    {
        $selectedRoot = Contact::factory()->create();
        $anchorContact = Contact::factory()->create([
            'merged_into_contact_id' => $selectedRoot->id,
            'merged_at' => now(),
        ]);
        $candidateRoot = Contact::factory()->create();

        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:terminal-delete-guard-1',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$selectedRoot->id, $candidateRoot->id],
            'routed_contact_id' => $selectedRoot->id,
            'status' => ContactDuplicateReview::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        $this->expectException(ContactPinnedByTerminalCrossChannelIdentityReviewException::class);
        $this->expectExceptionMessage('Удаление контакта заблокировано');

        try {
            app(DeleteContactAction::class)->handle($anchorContact);
        } finally {
            $this->assertModelExists($selectedRoot);
            $this->assertModelExists($anchorContact);
            $this->assertModelExists($candidateRoot);
            $this->assertModelExists($review);
        }
    }

    public function test_delete_contact_action_blocks_when_contact_owns_dismissed_cross_channel_identity_review(): void
    {
        $anchorContact = Contact::factory()->create();
        $candidateRoot = Contact::factory()->create();

        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:terminal-delete-guard-2',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$candidateRoot->id],
            'routed_contact_id' => $anchorContact->id,
            'status' => ContactDuplicateReview::STATUS_DISMISSED,
            'resolved_at' => now(),
        ]);

        $this->expectException(ContactPinnedByTerminalCrossChannelIdentityReviewException::class);
        $this->expectExceptionMessage('Удаление контакта заблокировано');

        try {
            app(DeleteContactAction::class)->handle($anchorContact);
        } finally {
            $this->assertModelExists($anchorContact);
            $this->assertModelExists($candidateRoot);
            $this->assertModelExists($review);
        }
    }
}
