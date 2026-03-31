<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\StoredInboundMessageResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Services\Bots\StoreInboundMessageAction;
use App\Services\Contacts\ContactMergeException;
use App\Services\Contacts\MergeContactsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

class StoreInboundMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_inbound_message_saves_phone_from_contact_share(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-101',
                externalMessageId: '101',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '200',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 18:00:00'),
            ),
        );

        $storedMessage = $storedResult->message;

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW, $storedResult->phoneCaptureStatus);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'source' => 'telegram_contact_share',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
    }

    public function test_store_inbound_message_skips_phone_capture_on_sender_mismatch(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-102',
                externalMessageId: '102',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '999',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67', 'user_id' => '999']]],
                receivedAt: Carbon::parse('2026-03-28 18:00:00'),
            ),
        );

        $storedMessage = $storedResult->message;

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_SENDER_MISMATCH, $storedResult->phoneCaptureStatus);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_skipped_sender_mismatch',
        ]);
    }

    public function test_store_inbound_message_marks_same_root_duplicate_when_number_already_exists_without_other_roots(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $firstResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-201',
                externalMessageId: '201',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '200',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 18:00:00'),
            ),
        );

        $duplicateResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-201',
                externalMessageId: '201',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '200',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 18:00:01'),
            ),
        );

        $this->assertTrue($firstResult->message->is($duplicateResult->message));
        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_DUPLICATE_SAME_ROOT, $duplicateResult->phoneCaptureStatus);
        $this->assertDatabaseCount('contact_phone_numbers', 1);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_duplicate_same_root_detected',
        ]);
    }

    public function test_store_inbound_message_merges_when_phone_matches_single_other_root(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $otherRoot = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $otherRoot->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '301',
                externalUserId: '201',
                providerEventKey: 'telegram-update-301',
                externalMessageId: '301',
                externalUsername: 'telegram_user_301',
                contactName: 'Тестовый контакт 301',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '8 (999) 123-45-67',
                sharedContactUserId: '201',
                rawPayload: ['message' => ['contact' => ['phone_number' => '8 (999) 123-45-67']]],
                receivedAt: Carbon::parse('2026-03-28 19:00:00'),
            ),
        );

        $storedMessage = $storedResult->message;
        $currentContact = Contact::query()->findOrFail($storedMessage->contact_id);
        $identity = ContactIdentity::query()->findOrFail($storedMessage->contact_identity_id);
        $savedPhone = ContactPhoneNumber::query()
            ->where('contact_id', $currentContact->id)
            ->where('phone_normalized', '+79991234567')
            ->firstOrFail();

        $mergedSecondary = Contact::query()
            ->where('merged_into_contact_id', $otherRoot->id)
            ->firstOrFail();

        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT, $storedResult->phoneCaptureStatus);
        $this->assertSame($otherRoot->id, $storedMessage->contact_id);
        $this->assertSame($otherRoot->id, $currentContact->id);
        $this->assertSame($otherRoot->id, $identity->contact_id);
        $this->assertSame($otherRoot->id, $savedPhone->contact_id);
        $this->assertSame($otherRoot->id, $mergedSecondary->merged_into_contact_id);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseHas('contact_merge_logs', [
            'primary_contact_id' => $otherRoot->id,
            'secondary_contact_id' => $mergedSecondary->id,
            'trigger_phone' => '+79991234567',
            'trigger_message_id' => $storedMessage->id,
            'merge_reason' => 'phone_exact_match',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_merged_to_existing_root',
        ]);
    }

    public function test_store_inbound_message_creates_review_when_phone_matches_multiple_other_roots(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $firstRoot = Contact::factory()->create();
        $secondRoot = Contact::factory()->create();

        foreach ([$firstRoot, $secondRoot] as $contact) {
            ContactPhoneNumber::factory()->create([
                'contact_id' => $contact->id,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
                'is_primary' => true,
            ]);
        }

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '302',
                externalUserId: '202',
                providerEventKey: 'telegram-update-302',
                externalMessageId: '302',
                externalUsername: 'telegram_user_302',
                contactName: 'Тестовый контакт 302',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '202',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 19:05:00'),
            ),
        );

        $storedMessage = $storedResult->message;
        $currentContact = Contact::query()->findOrFail($storedMessage->contact_id);

        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING, $storedResult->phoneCaptureStatus);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $currentContact->duplicate_review_status);
        $this->assertDatabaseHas('contact_duplicate_reviews', [
            'contact_id' => $currentContact->id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
        $this->assertSame(
            [$firstRoot->id, $secondRoot->id],
            ContactDuplicateReview::query()->firstOrFail()->candidate_root_contact_ids,
        );
        $this->assertDatabaseCount('contact_merge_logs', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_review_pending_multiple_roots',
        ]);
    }

    public function test_store_inbound_message_keeps_merge_idempotent_for_repeated_webhook(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $otherRoot = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $otherRoot->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $payloadMessage = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: '303',
            externalUserId: '203',
            providerEventKey: 'telegram-update-303',
            externalMessageId: '303',
            externalUsername: 'telegram_user_303',
            contactName: 'Тестовый контакт 303',
            text: null,
            inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
            sharedPhoneNumber: '+7 999 123 45 67',
            sharedContactUserId: '203',
            rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
            receivedAt: Carbon::parse('2026-03-28 19:10:00'),
        );

        $firstResult = app(StoreInboundMessageAction::class)->handle($channel, $payloadMessage);
        $secondResult = app(StoreInboundMessageAction::class)->handle($channel, $payloadMessage);

        $this->assertTrue($firstResult->message->is($secondResult->message));
        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT, $firstResult->phoneCaptureStatus);
        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_DUPLICATE_SAME_ROOT, $secondResult->phoneCaptureStatus);
        $this->assertSame($otherRoot->id, $secondResult->message->fresh()->contact_id);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseCount('contact_merge_logs', 1);
    }

    public function test_store_inbound_message_falls_back_to_review_pending_when_merge_fails(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $otherRoot = Contact::factory()->create([
            'first_name' => 'Герман',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $otherRoot->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $this->mock(MergeContactsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new ContactMergeException('Identity conflict.'));
        });

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '304',
                externalUserId: '204',
                providerEventKey: 'telegram-update-304',
                externalMessageId: '304',
                externalUsername: 'telegram_user_304',
                contactName: 'Тестовый контакт 304',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '204',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 19:15:00'),
            ),
        );

        $currentContact = Contact::query()->findOrFail($storedResult->message->contact_id);

        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING, $storedResult->phoneCaptureStatus);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $currentContact->duplicate_review_status);
        $this->assertDatabaseHas('contact_duplicate_reviews', [
            'contact_id' => $currentContact->id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
        $this->assertDatabaseCount('contact_merge_logs', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_merge_failed_review_pending',
        ]);
    }
}
