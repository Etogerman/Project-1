<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactMergeLog;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\BrokenContactMergeChainException;
use App\Services\Contacts\CleanupExternalDuplicateReviewsForDeletedAggregateAction;
use App\Services\Contacts\DeleteContactAction;
use App\Services\Contacts\DeleteContactPhoneAction;
use App\Services\Contacts\ResolveContactAggregateAction;
use App\Services\Contacts\UpdateContactPhoneAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MergedContactGuardActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_contact_phone_action_rejects_phone_of_merged_contact(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $merged->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Номер относится к архивному дублю. Измените номер у основного контакта.');

        app(UpdateContactPhoneAction::class)->handle($phoneNumber, '+7 999 555 55 55');
    }

    public function test_delete_contact_phone_action_rejects_phone_of_merged_contact(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $merged->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Номер относится к архивному дублю. Удалите номер у основного контакта.');

        app(DeleteContactPhoneAction::class)->handle($phoneNumber);
    }

    public function test_standalone_contact_delete_removes_cascaded_children(): void
    {
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
        ]);
        $dialog = Dialog::factory()->create([
            'current_contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $identity->channel_id,
        ]);
        $message = Message::factory()->create([
            'contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $identity->channel_id,
            'dialog_id' => $dialog->id,
        ]);
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
        ]);
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $contact->id,
        ]);

        app(DeleteContactAction::class)->handle($contact);

        $this->assertModelMissing($contact);
        $this->assertModelMissing($identity);
        $this->assertModelMissing($dialog);
        $this->assertModelMissing($message);
        $this->assertModelMissing($phoneNumber);
        $this->assertModelMissing($review);
    }

    public function test_root_aggregate_delete_removes_all_descendants(): void
    {
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $tertiary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $secondary->id,
        ]);
        ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $tertiary->id,
        ]);

        app(DeleteContactAction::class)->handle($root);

        $this->assertModelMissing($root);
        $this->assertModelMissing($secondary);
        $this->assertModelMissing($tertiary);
    }

    public function test_delete_called_on_secondary_resolves_root_and_removes_aggregate(): void
    {
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $mergeLog = ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $secondary->id,
        ]);

        app(DeleteContactAction::class)->handle($secondary);

        $this->assertModelMissing($root);
        $this->assertModelMissing($secondary);
        $this->assertModelMissing($mergeLog);
    }

    public function test_aggregate_delete_removes_contact_merge_logs(): void
    {
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $mergeLog = ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $secondary->id,
        ]);

        app(DeleteContactAction::class)->handle($root);

        $this->assertModelMissing($mergeLog);
    }

    public function test_aggregate_delete_removes_historical_secondary_dialog_via_cascade(): void
    {
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $secondary->id,
        ]);
        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'current_contact_identity_id' => $secondaryIdentity->id,
            'contact_id' => $secondary->id,
            'channel_id' => $secondaryIdentity->channel_id,
        ]);

        app(DeleteContactAction::class)->handle($root);

        $this->assertModelMissing($secondaryDialog);
        $this->assertModelMissing($secondaryIdentity);
    }

    public function test_merge_chain_delete_works_for_any_entry_point(): void
    {
        $root = Contact::factory()->create();
        $middle = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $leaf = Contact::factory()->create([
            'merged_into_contact_id' => $middle->id,
            'merged_at' => now(),
        ]);
        ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $middle->id,
        ]);
        ContactMergeLog::factory()->create([
            'primary_contact_id' => $middle->id,
            'secondary_contact_id' => $leaf->id,
        ]);

        app(DeleteContactAction::class)->handle($leaf);

        $this->assertModelMissing($root);
        $this->assertModelMissing($middle);
        $this->assertModelMissing($leaf);
    }

    public function test_broken_merge_chain_aborts_delete(): void
    {
        $contact = Contact::factory()->create();

        Log::shouldReceive('error')
            ->once()
            ->with('contact.aggregate_delete_broken_merge_chain', Mockery::on(
                fn (array $context): bool => $context['contact_id'] === $contact->id
                    && str_contains($context['error'], 'missing merged parent')
            ));

        $this->mock(ResolveContactAggregateAction::class, function (MockInterface $mock) use ($contact): void {
            $mock->shouldReceive('handle')
                ->once()
                ->withArgs(fn (Contact $incomingContact): bool => $incomingContact->is($contact))
                ->andThrow(BrokenContactMergeChainException::missingMergedParent($contact->id, 999));
        });

        $this->expectException(BrokenContactMergeChainException::class);

        try {
            app(DeleteContactAction::class)->handle($contact);
        } finally {
            $this->assertModelExists($contact);
        }
    }

    public function test_delete_transaction_rolls_back_on_failure(): void
    {
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $mergeLog = ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $secondary->id,
        ]);
        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'current_contact_identity_id' => $secondaryIdentity->id,
            'contact_id' => $secondary->id,
            'channel_id' => $secondaryIdentity->channel_id,
        ]);

        $dispatcher = Contact::getEventDispatcher();

        Contact::deleting(function (Contact $contact) use ($root): void {
            if ($contact->is($root)) {
                throw new RuntimeException('Forced delete failure.');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forced delete failure.');

        try {
            app(DeleteContactAction::class)->handle($root);
        } finally {
            Contact::setEventDispatcher($dispatcher);

            $this->assertModelExists($root);
            $this->assertModelExists($secondary);
            $this->assertModelExists($mergeLog);
            $this->assertModelExists($secondaryIdentity);
            $this->assertModelExists($secondaryDialog);
        }
    }

    public function test_aggregate_delete_cleans_external_candidate_root_ids(): void
    {
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $secondary->id,
        ]);
        $externalContact = Contact::factory()->create([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $survivingCandidate = Contact::factory()->create();
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $externalContact->id,
            'review_type' => ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            'candidate_root_contact_ids' => [$root->id, $secondary->id, $survivingCandidate->id],
        ]);

        app(DeleteContactAction::class)->handle($root);

        $review->refresh();

        $this->assertSame(ContactDuplicateReview::STATUS_OPEN, $review->status);
        $this->assertSame(ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE, $review->review_type);
        $this->assertSame([$survivingCandidate->id], $review->candidate_root_contact_ids);
    }

    public function test_external_review_becomes_resolved_when_no_candidates_left(): void
    {
        $contactToDelete = Contact::factory()->create();
        $externalContact = Contact::factory()->create([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $externalContact->id,
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'candidate_root_contact_ids' => [$contactToDelete->id],
        ]);

        app(DeleteContactAction::class)->handle($contactToDelete);

        $review->refresh();
        $externalContact->refresh();

        $this->assertSame(ContactDuplicateReview::STATUS_RESOLVED, $review->status);
        $this->assertNotNull($review->resolved_at);
        $this->assertNull($review->candidate_root_contact_ids);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_RESOLVED, $externalContact->duplicate_review_status);
    }

    public function test_external_review_stays_open_when_multiple_candidates_remain(): void
    {
        $contactToDelete = Contact::factory()->create();
        $externalContact = Contact::factory()->create([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $candidateA = Contact::factory()->create();
        $candidateB = Contact::factory()->create();
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $externalContact->id,
            'review_type' => ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            'candidate_root_contact_ids' => [$contactToDelete->id, $candidateA->id, $candidateB->id],
        ]);

        app(DeleteContactAction::class)->handle($contactToDelete);

        $review->refresh();
        $externalContact->refresh();

        $this->assertSame(ContactDuplicateReview::STATUS_OPEN, $review->status);
        $this->assertNull($review->resolved_at);
        $this->assertSame(ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS, $review->review_type);
        $this->assertSame([$candidateA->id, $candidateB->id], $review->candidate_root_contact_ids);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $externalContact->duplicate_review_status);
    }

    public function test_affected_contact_duplicate_review_status_stays_pending_when_other_open_reviews_exist(): void
    {
        $contactToDelete = Contact::factory()->create();
        $externalContact = Contact::factory()->create([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $survivingCandidate = Contact::factory()->create();

        ContactDuplicateReview::factory()->create([
            'contact_id' => $externalContact->id,
            'review_type' => ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            'candidate_root_contact_ids' => [$contactToDelete->id, $survivingCandidate->id],
        ]);

        ContactDuplicateReview::factory()->create([
            'contact_id' => $externalContact->id,
            'review_type' => ContactDuplicateReview::TYPE_MERGE_CONFLICT,
            'status' => ContactDuplicateReview::STATUS_OPEN,
            'candidate_root_contact_ids' => null,
        ]);

        app(DeleteContactAction::class)->handle($contactToDelete);

        $externalContact->refresh();

        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $externalContact->duplicate_review_status);
    }

    public function test_delete_transaction_rolls_back_when_external_review_cleanup_fails(): void
    {
        $root = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $mergeLog = ContactMergeLog::factory()->create([
            'primary_contact_id' => $root->id,
            'secondary_contact_id' => $secondary->id,
        ]);
        $externalContact = Contact::factory()->create([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $externalReview = ContactDuplicateReview::factory()->create([
            'contact_id' => $externalContact->id,
            'candidate_root_contact_ids' => [$root->id],
        ]);

        $this->mock(CleanupExternalDuplicateReviewsForDeletedAggregateAction::class, function (MockInterface $mock) use ($root, $secondary): void {
            $mock->shouldReceive('handle')
                ->once()
                ->withArgs(fn (array $aggregateContactIds): bool => $aggregateContactIds === [$root->id, $secondary->id])
                ->andThrow(new RuntimeException('Forced review cleanup failure.'));
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forced review cleanup failure.');

        try {
            app(DeleteContactAction::class)->handle($root);
        } finally {
            $this->assertModelExists($root);
            $this->assertModelExists($secondary);
            $this->assertModelExists($mergeLog);
            $this->assertModelExists($externalReview);
            $this->assertSame([$root->id], $externalReview->fresh()->candidate_root_contact_ids);
        }
    }
}
