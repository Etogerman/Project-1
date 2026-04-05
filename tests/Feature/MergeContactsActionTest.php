<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactMergeLog;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Tag;
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

        $primaryDialog = Dialog::factory()->create([
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $primaryIdentity->id,
            'external_chat_id' => 'chat-primary',
            'confirmed_phone_raw' => '+7 999 111 22 33',
            'confirmed_phone_normalized' => '+79991112233',
            'phone_confirmed_at' => now()->subDay(),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
            'last_message_at' => now()->subHours(3),
            'last_inbound_at' => now()->subHours(3),
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $secondaryIdentity->id,
            'external_chat_id' => 'chat-secondary',
            'confirmed_phone_raw' => '+7 999 555 44 33',
            'confirmed_phone_normalized' => '+79995554433',
            'phone_confirmed_at' => now()->subHour(),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
        ]);

        Message::factory()->create([
            'dialog_id' => $primaryDialog->id,
            'contact_identity_id' => $primaryIdentity->id,
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'text' => 'Primary history',
            'external_chat_id' => 'chat-primary',
            'received_at' => now()->subHours(3),
        ]);
        $primaryMessageWithoutDialog = Message::factory()->create([
            'dialog_id' => null,
            'contact_identity_id' => $primaryIdentity->id,
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'text' => 'Primary second history',
            'external_chat_id' => 'chat-primary',
            'received_at' => now()->subHours(2),
        ]);
        $triggerMessage = Message::factory()->create([
            'dialog_id' => $secondaryDialog->id,
            'contact_identity_id' => $secondaryIdentity->id,
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'text' => 'Secondary history',
            'external_chat_id' => 'chat-secondary',
            'received_at' => now()->subHour(),
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
        $primaryOnlyTag = Tag::factory()->create([
            'name' => 'Primary only',
            'color' => Tag::COLOR_PRIMARY,
        ]);
        $sharedTag = Tag::factory()->create([
            'name' => 'Shared tag',
            'color' => Tag::COLOR_SUCCESS,
        ]);
        $secondaryOnlyTag = Tag::factory()->create([
            'name' => 'Secondary only',
            'color' => Tag::COLOR_WARNING,
        ]);

        $primary->tags()->attach([
            $primaryOnlyTag->id => [
                'assigned_at' => now()->subHours(4),
                'assigned_by_user_id' => $assignedUser->id,
                'created_at' => now()->subHours(4),
                'updated_at' => now()->subHours(4),
            ],
            $sharedTag->id => [
                'assigned_at' => now()->subHours(3),
                'assigned_by_user_id' => $assignedUser->id,
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ],
        ]);
        $secondary->tags()->attach([
            $sharedTag->id => [
                'assigned_at' => now()->subHours(2),
                'assigned_by_user_id' => $assignedUser->id,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            $secondaryOnlyTag->id => [
                'assigned_at' => now()->subHour(),
                'assigned_by_user_id' => $assignedUser->id,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ],
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

        $primaryDialog->refresh();
        $primaryMessageWithoutDialog->refresh();
        $this->assertSame($primary->id, $triggerMessage->contact_id);
        $this->assertSame($primaryDialog->id, $triggerMessage->dialog_id);
        $this->assertSame($primaryDialog->id, $primaryMessageWithoutDialog->dialog_id);
        $this->assertSame($secondaryIdentity->id, $triggerMessage->contact_identity_id);
        $this->assertSame($primary->id, $secondaryIdentity->contact_id);
        $this->assertDatabaseMissing('dialogs', [
            'id' => $secondaryDialog->id,
        ]);
        $this->assertDatabaseCount('dialogs', 1);
        $this->assertSame($primary->id, $primaryDialog->contact_id);
        $this->assertSame($secondaryIdentity->id, $primaryDialog->current_contact_identity_id);
        $this->assertSame('chat-secondary', $primaryDialog->external_chat_id);
        $this->assertSame('+7 999 555 44 33', $primaryDialog->confirmed_phone_raw);
        $this->assertSame('+79995554433', $primaryDialog->confirmed_phone_normalized);
        $this->assertSame(
            $secondaryDialog->phone_confirmed_at?->format('Y-m-d H:i:s'),
            $primaryDialog->phone_confirmed_at?->format('Y-m-d H:i:s'),
        );

        $this->assertSame($primary->id, $triggerPhone->contact_id);
        $this->assertFalse($triggerPhone->is_primary);
        $this->assertSame($secondary->id, $duplicatePhone->contact_id);
        $this->assertSame($primary->id, $primaryPhone->contact_id);
        $this->assertTrue($primaryPhone->is_primary);
        $this->assertDatabaseCount('contact_tag', 3);
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $primary->id,
            'tag_id' => $primaryOnlyTag->id,
        ]);
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $primary->id,
            'tag_id' => $sharedTag->id,
        ]);
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $primary->id,
            'tag_id' => $secondaryOnlyTag->id,
        ]);
        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $secondary->id,
            'tag_id' => $sharedTag->id,
        ]);
        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $secondary->id,
            'tag_id' => $secondaryOnlyTag->id,
        ]);

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
        $primaryDialog = Dialog::factory()->create([
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => ContactIdentity::factory()->create([
                'contact_id' => $primary->id,
                'channel_id' => $channel->id,
                'platform' => $channel->platform,
                'external_user_id' => 'primary-user',
            ])->id,
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $secondaryIdentity->id,
            'external_chat_id' => 'rollback-chat',
        ]);
        $secondaryMessage = Message::factory()->create([
            'dialog_id' => $secondaryDialog->id,
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
            $primaryDialog->refresh();
            $secondaryDialog->refresh();

            $this->assertNull($secondary->merged_into_contact_id);
            $this->assertNull($secondary->merged_at);
            $this->assertNull($secondary->merge_reason);
            $this->assertNull($secondary->merge_trigger_phone);
            $this->assertSame($secondary->id, $secondaryIdentity->contact_id);
            $this->assertSame($secondary->id, $secondaryMessage->contact_id);
            $this->assertSame($secondaryDialog->id, $secondaryMessage->dialog_id);
            $this->assertSame($primary->id, $primaryDialog->contact_id);
            $this->assertSame($secondary->id, $secondaryDialog->contact_id);
            $this->assertSame($secondary->id, $secondaryPhone->contact_id);
            $this->assertSame('Alice', $primary->first_name);
        }
    }

    public function test_it_reassigns_secondary_only_dialog_in_different_channel_to_primary(): void
    {
        $primaryChannel = Channel::factory()->create();
        $secondaryChannel = Channel::factory()->create();

        $primary = Contact::factory()->create([
            'first_name' => 'Primary',
        ]);
        $secondary = Contact::factory()->create();

        $primaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $primary->id,
            'channel_id' => $primaryChannel->id,
            'platform' => $primaryChannel->platform,
            'external_user_id' => 'primary-user',
        ]);
        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $secondaryChannel->id,
            'platform' => $secondaryChannel->platform,
            'external_user_id' => 'secondary-user',
        ]);

        $primaryDialog = Dialog::factory()->create([
            'contact_id' => $primary->id,
            'channel_id' => $primaryChannel->id,
            'current_contact_identity_id' => $primaryIdentity->id,
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $secondaryChannel->id,
            'current_contact_identity_id' => $secondaryIdentity->id,
            'external_chat_id' => 'secondary-channel-chat',
        ]);

        $secondaryMessage = Message::factory()->create([
            'dialog_id' => $secondaryDialog->id,
            'contact_id' => $secondary->id,
            'contact_identity_id' => $secondaryIdentity->id,
            'channel_id' => $secondaryChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'secondary-channel-chat',
        ]);
        Message::factory()->create([
            'dialog_id' => $primaryDialog->id,
            'contact_id' => $primary->id,
            'contact_identity_id' => $primaryIdentity->id,
            'channel_id' => $primaryChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
        ]);

        app(MergeContactsAction::class)->handle($primary, $secondary);

        $primary->refresh();
        $secondary->refresh();
        $secondaryDialog->refresh();
        $secondaryMessage->refresh();

        $this->assertSame($primary->id, $secondary->merged_into_contact_id);
        $this->assertSame($primary->id, $secondaryDialog->contact_id);
        $this->assertSame($primary->id, $secondaryMessage->contact_id);
        $this->assertSame($secondaryDialog->id, $secondaryMessage->dialog_id);
        $this->assertSame(2, Dialog::query()->where('contact_id', $primary->id)->count());
        $this->assertDatabaseHas('dialogs', [
            'id' => $primaryDialog->id,
            'contact_id' => $primary->id,
        ]);
        $this->assertDatabaseHas('dialogs', [
            'id' => $secondaryDialog->id,
            'contact_id' => $primary->id,
            'channel_id' => $secondaryChannel->id,
        ]);
        $this->assertSame(0, Dialog::query()->where('contact_id', $secondary->id)->count());
    }

    public function test_it_clears_stale_max_chat_id_when_freshest_merged_route_source_is_user_based(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $primary = Contact::factory()->create([
            'first_name' => 'Primary',
        ]);
        $secondary = Contact::factory()->create();

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
            'external_user_id' => 'fresh-user',
        ]);

        $primaryDialog = Dialog::factory()->create([
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $primaryIdentity->id,
            'external_chat_id' => 'stale-primary-chat',
            'last_message_at' => now()->subDay(),
            'last_inbound_at' => now()->subDay(),
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $secondaryIdentity->id,
            'external_chat_id' => 'stale-secondary-chat',
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
        ]);

        Message::factory()->create([
            'dialog_id' => $primaryDialog->id,
            'contact_id' => $primary->id,
            'contact_identity_id' => $primaryIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'old-chat',
            'received_at' => now()->subDay(),
        ]);
        $secondaryMessage = Message::factory()->create([
            'dialog_id' => $secondaryDialog->id,
            'contact_id' => $secondary->id,
            'contact_identity_id' => $secondaryIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => '',
            'received_at' => now()->subMinutes(5),
        ]);

        app(MergeContactsAction::class)->handle($primary, $secondary);

        $primary->refresh();
        $secondary->refresh();
        $primaryDialog->refresh();
        $secondaryMessage->refresh();

        $this->assertSame($primary->id, $secondary->merged_into_contact_id);
        $this->assertDatabaseMissing('dialogs', [
            'id' => $secondaryDialog->id,
        ]);
        $this->assertSame($primary->id, $secondaryMessage->contact_id);
        $this->assertSame($primaryDialog->id, $secondaryMessage->dialog_id);
        $this->assertSame($secondaryIdentity->id, $primaryDialog->current_contact_identity_id);
        $this->assertNull($primaryDialog->external_chat_id);
    }

    public function test_it_resets_collector_prompt_marker_when_merge_keeps_same_active_field(): void
    {
        $primary = Contact::factory()->create([
            'first_name' => null,
            'country' => null,
            'city' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now()->subHour(),
            'data_collection_current_field_started_at' => now()->subMinutes(30),
        ]);
        $secondary = Contact::factory()->create([
            'first_name' => null,
            'country' => null,
            'city' => null,
        ]);

        $originalFieldStartedAt = $primary->data_collection_current_field_started_at;

        app(MergeContactsAction::class)->handle($primary, $secondary);

        $primary->refresh();

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $primary->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $primary->data_collection_current_field);
        $this->assertNull($primary->data_collection_last_prompted_field);
        $this->assertNotNull($primary->data_collection_current_field_started_at);
        $this->assertNotSame(
            $originalFieldStartedAt?->toDateTimeString(),
            $primary->data_collection_current_field_started_at?->toDateTimeString(),
        );
    }
}
