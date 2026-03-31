<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactMergeLog;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Models\User;
use App\Services\Contacts\MergeContactsAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergeContactsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_merges_two_root_contacts_and_moves_related_records(): void
    {
        $channel = Channel::factory()->create();
        $assignedUser = User::factory()->create();

        $primary = Contact::factory()->create([
            'first_name' => 'Alice',
            'gender' => 'female',
            'birth_date' => '1990-01-01',
            'age_years' => 35,
            'region' => 'Московская область',
            'is_auto_reply_enabled' => true,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_COUNTRY,
            'data_collection_started_at' => now()->subDay(),
            'data_collection_attempts_count' => 1,
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $secondary = Contact::factory()->create([
            'first_name' => 'Alicia',
            'last_name' => 'Ivanova',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '30_39',
            'assigned_user_id' => $assignedUser->id,
            'is_auto_reply_enabled' => false,
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);

        $primaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'primary-user',
        ]);
        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'secondary-user',
        ]);

        Message::factory()->create([
            'contact_identity_id' => $primaryIdentity->id,
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'text' => 'Primary history',
        ]);
        Message::factory()->create([
            'contact_identity_id' => $primaryIdentity->id,
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'text' => 'Primary second history',
        ]);
        $triggerMessage = Message::factory()->create([
            'contact_identity_id' => $secondaryIdentity->id,
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'text' => 'Secondary history',
        ]);

        $primaryPhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $primary->id,
            'phone_raw' => '+7 999 111 22 33',
            'phone_normalized' => '+79991112233',
            'is_primary' => true,
        ]);
        $triggerPhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $secondary->id,
            'phone_raw' => '+7 999 555 44 33',
            'phone_normalized' => '+79995554433',
            'is_primary' => true,
        ]);
        $duplicatePhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $secondary->id,
            'phone_raw' => '+7 999 111 22 33',
            'phone_normalized' => '+79991112233',
            'is_primary' => false,
        ]);

        ContactDuplicateReview::factory()->create([
            'contact_id' => $primary->id,
            'phone_normalized' => $triggerPhone->phone_normalized,
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
        ContactDuplicateReview::factory()->create([
            'contact_id' => $secondary->id,
            'phone_normalized' => $triggerPhone->phone_normalized,
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
        $remainingReview = ContactDuplicateReview::factory()->create([
            'contact_id' => $secondary->id,
            'phone_normalized' => '+79990000000',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        $result = app(MergeContactsAction::class)->handle(
            left: $primary,
            right: $secondary,
            mergeReason: 'phone_exact_match',
            triggerPhone: $triggerPhone->phone_normalized,
            triggerMessage: $triggerMessage,
        );

        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasNoopSameRoot);
        $this->assertSame($primary->id, $result->primaryContactId);
        $this->assertSame($secondary->id, $result->secondaryContactId);
        $this->assertSame(1, $result->messagesMovedCount);
        $this->assertSame(1, $result->identitiesMovedCount);
        $this->assertSame(1, $result->phonesMovedCount);
        $this->assertArrayHasKey('last_name', $result->fieldsCopied);
        $this->assertArrayHasKey('country', $result->fieldsCopied);
        $this->assertArrayHasKey('city', $result->fieldsCopied);
        $this->assertArrayHasKey('age_range', $result->fieldsCopied);
        $this->assertArrayHasKey('assigned_user_id', $result->fieldsCopied);
        $this->assertArrayHasKey('is_auto_reply_enabled', $result->fieldsCopied);
        $this->assertArrayHasKey('first_name', $result->fieldsConflicted);

        $primary->refresh();
        $secondary->refresh();
        $triggerMessage->refresh();
        $secondaryIdentity->refresh();
        $primaryPhone->refresh();
        $triggerPhone->refresh();
        $duplicatePhone->refresh();

        $this->assertSame('Alice', $primary->first_name);
        $this->assertSame('Ivanova', $primary->last_name);
        $this->assertSame('Россия', $primary->country);
        $this->assertSame('Москва', $primary->city);
        $this->assertSame('Московская область', $primary->region);
        $this->assertSame(Contact::REGION_STATUS_RESOLVED, $primary->region_status);
        $this->assertSame('30_39', $primary->age_range);
        $this->assertSame($assignedUser->id, $primary->assigned_user_id);
        $this->assertFalse($primary->is_auto_reply_enabled);
        $this->assertSame(0, $primary->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $primary->distance_to_moscow_status);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $primary->data_collection_status);
        $this->assertNull($primary->data_collection_current_field);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_RESOLVED, $primary->duplicate_review_status);

        $this->assertSame($primary->id, $secondary->merged_into_contact_id);
        $this->assertNotNull($secondary->merged_at);
        $this->assertSame('phone_exact_match', $secondary->merge_reason);
        $this->assertSame($triggerPhone->phone_normalized, $secondary->merge_trigger_phone);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $secondary->duplicate_review_status);

        $this->assertSame($primary->id, $triggerMessage->contact_id);
        $this->assertSame($secondaryIdentity->id, $triggerMessage->contact_identity_id);
        $this->assertSame($primary->id, $secondaryIdentity->contact_id);

        $this->assertSame($primary->id, $triggerPhone->contact_id);
        $this->assertFalse($triggerPhone->is_primary);
        $this->assertSame($secondary->id, $duplicatePhone->contact_id);
        $this->assertSame($primary->id, $primaryPhone->contact_id);
        $this->assertTrue($primaryPhone->is_primary);

        $this->assertDatabaseHas('contact_merge_logs', [
            'id' => $result->mergeLogId,
            'primary_contact_id' => $primary->id,
            'secondary_contact_id' => $secondary->id,
            'trigger_phone' => $triggerPhone->phone_normalized,
            'trigger_message_id' => $triggerMessage->id,
            'merge_reason' => 'phone_exact_match',
            'messages_moved_count' => 1,
            'identities_moved_count' => 1,
            'phones_moved_count' => 1,
        ]);

        $this->assertDatabaseHas('contact_duplicate_reviews', [
            'contact_id' => $primary->id,
            'phone_normalized' => $triggerPhone->phone_normalized,
            'status' => ContactDuplicateReview::STATUS_RESOLVED,
        ]);
        $this->assertDatabaseHas('contact_duplicate_reviews', [
            'contact_id' => $secondary->id,
            'phone_normalized' => $triggerPhone->phone_normalized,
            'status' => ContactDuplicateReview::STATUS_RESOLVED,
        ]);
        $this->assertDatabaseHas('contact_duplicate_reviews', [
            'id' => $remainingReview->id,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
    }

    public function test_it_returns_noop_when_contacts_already_resolve_to_same_root(): void
    {
        $primary = Contact::factory()->create();
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $primary->id,
            'merged_at' => now(),
            'merge_reason' => 'phone_exact_match',
            'merge_trigger_phone' => '+79995554433',
        ]);

        $result = app(MergeContactsAction::class)->handle($primary, $secondary);

        $this->assertFalse($result->wasMerged);
        $this->assertTrue($result->wasNoopSameRoot);
        $this->assertNull($result->mergeLogId);
        $this->assertDatabaseCount('contact_merge_logs', 0);
    }

    public function test_it_rolls_back_when_merge_log_insert_fails(): void
    {
        $channel = Channel::factory()->create();
        $primary = Contact::factory()->create([
            'first_name' => 'Alice',
            'gender' => 'female',
            'country' => 'Россия',
        ]);
        $secondary = Contact::factory()->create([
            'city' => 'Москва',
        ]);

        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'secondary-user',
        ]);
        $secondaryMessage = Message::factory()->create([
            'contact_identity_id' => $secondaryIdentity->id,
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
        ]);
        $secondaryPhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $secondary->id,
            'phone_normalized' => '+79995554433',
            'is_primary' => true,
        ]);

        ContactMergeLog::factory()->create([
            'secondary_contact_id' => $secondary->id,
        ]);

        $this->expectException(QueryException::class);

        try {
            app(MergeContactsAction::class)->handle(
                left: $primary,
                right: $secondary,
                mergeReason: 'phone_exact_match',
                triggerPhone: '+79995554433',
            );
        } finally {
            $primary->refresh();
            $secondary->refresh();
            $secondaryIdentity->refresh();
            $secondaryMessage->refresh();
            $secondaryPhone->refresh();

            $this->assertNull($secondary->merged_into_contact_id);
            $this->assertNull($secondary->merged_at);
            $this->assertNull($secondary->merge_reason);
            $this->assertNull($secondary->merge_trigger_phone);
            $this->assertSame($secondary->id, $secondaryIdentity->contact_id);
            $this->assertSame($secondary->id, $secondaryMessage->contact_id);
            $this->assertSame($secondary->id, $secondaryPhone->contact_id);
            $this->assertSame('Alice', $primary->first_name);
        }
    }
}
