<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\StoredInboundMessageResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\ContactStartTag;
use App\Models\Dialog;
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

    public function test_store_inbound_message_assigns_start_tag_for_telegram_start_payload(): void
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
                providerEventKey: 'telegram-update-start-1',
                externalMessageId: 'start-1',
                externalUsername: 'telegram_user',
                contactName: 'Тестовый контакт',
                text: '/start TEXT_1',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => '/start TEXT_1']],
                receivedAt: Carbon::parse('2026-04-03 14:00:00'),
            ),
        );

        $this->assertDatabaseHas('contact_start_tags', [
            'contact_id' => $storedResult->message->contact_id,
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => 'TEXT_1',
            'source' => ContactStartTag::SOURCE_TELEGRAM_START,
            'source_message_id' => $storedResult->message->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $storedResult->message->id,
            'message_parameter' => 'TEXT_1',
        ]);
    }

    public function test_store_inbound_message_assigns_start_tag_for_max_bot_started_payload(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '700',
                externalUserId: '500',
                providerEventKey: 'max-bot-started:700:2026-04-03T14:10:00+03:00',
                externalMessageId: null,
                externalUsername: 'max_user',
                contactName: 'MAX контакт',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: [
                    'update_type' => 'bot_started',
                    'payload' => 'TEXT_1',
                ],
                receivedAt: Carbon::parse('2026-04-03 14:10:00'),
            ),
        );

        $this->assertDatabaseHas('contact_start_tags', [
            'contact_id' => $storedResult->message->contact_id,
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => 'TEXT_1',
            'source' => ContactStartTag::SOURCE_MAX_START,
            'source_message_id' => $storedResult->message->id,
        ]);
    }

    public function test_store_inbound_message_replay_does_not_duplicate_start_tag(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payloadMessage = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: '300',
            externalUserId: '200',
            providerEventKey: 'telegram-update-start-replay',
            externalMessageId: 'start-replay',
            externalUsername: 'telegram_user',
            contactName: 'Тестовый контакт',
            text: '/start TEXT_1',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => '/start TEXT_1']],
            receivedAt: Carbon::parse('2026-04-03 14:20:00'),
        );

        $firstResult = app(StoreInboundMessageAction::class)->handle($channel, $payloadMessage);
        $secondResult = app(StoreInboundMessageAction::class)->handle($channel, $payloadMessage);

        $this->assertTrue($firstResult->message->is($secondResult->message));
        $this->assertDatabaseCount('contact_start_tags', 1);
        $this->assertDatabaseHas('contact_start_tags', [
            'contact_id' => $firstResult->message->contact_id,
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => 'TEXT_1',
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $firstResult->message->id,
            'message_parameter' => 'TEXT_1',
        ]);
    }

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
        $dialog = Dialog::query()
            ->where('contact_id', $storedMessage->contact_id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertSame(Message::SENT_BY_TYPE_CONTACT, $storedMessage->fresh()->sent_by_type);
        $this->assertSame($dialog->id, $storedMessage->fresh()->dialog_id);
        $this->assertSame($storedMessage->contact_identity_id, $dialog->current_contact_identity_id);
        $this->assertSame('300', $dialog->external_chat_id);
        $this->assertSame('2026-03-28 18:00:00', $dialog->last_message_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-28 18:00:00', $dialog->last_inbound_at?->format('Y-m-d H:i:s'));
        $this->assertNull($dialog->last_outbound_at);
        $this->assertSame('+7 999 123 45 67', $dialog->confirmed_phone_raw);
        $this->assertSame('+79991234567', $dialog->confirmed_phone_normalized);
        $this->assertSame('2026-03-28 18:00:00', $dialog->phone_confirmed_at?->format('Y-m-d H:i:s'));
        $this->assertSame(\App\Models\Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE, $dialog->phone_confirmed_via);
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
        $this->assertDatabaseHas('dialogs', [
            'contact_id' => $storedMessage->contact_id,
            'channel_id' => $channel->id,
            'confirmed_phone_raw' => null,
            'confirmed_phone_normalized' => null,
            'phone_confirmed_at' => null,
            'phone_confirmed_via' => null,
        ]);
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
        $this->assertNotNull($duplicateResult->message->fresh()->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_CONTACT, $duplicateResult->message->fresh()->sent_by_type);
        $this->assertDatabaseHas('dialogs', [
            'id' => $duplicateResult->message->fresh()->dialog_id,
            'confirmed_phone_raw' => '+7 999 123 45 67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_via' => \App\Models\Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);
        $this->assertDatabaseCount('contact_phone_numbers', 1);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_duplicate_same_root_detected',
        ]);
    }

    public function test_store_inbound_message_replay_self_heals_dialog_metadata_for_legacy_message(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Legacy contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $legacyMessage = Message::factory()->create([
            'dialog_id' => null,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-legacy',
            'external_chat_id' => '300',
            'external_message_id' => 'legacy-1',
            'text' => 'Привет',
            'received_at' => Carbon::parse('2026-03-28 18:00:00'),
            'sent_by_type' => null,
            'sent_by_user_id' => null,
            'sent_by_system_code' => null,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '300',
                externalUserId: '200',
                providerEventKey: 'telegram-update-legacy',
                externalMessageId: 'legacy-1',
                externalUsername: 'telegram_user',
                contactName: 'Legacy contact',
                text: 'Привет',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет']],
                receivedAt: Carbon::parse('2026-03-28 18:05:00'),
            ),
        );

        $dialog = Dialog::query()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertTrue($legacyMessage->is($storedResult->message));
        $this->assertSame($dialog->id, $storedResult->message->fresh()->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_CONTACT, $storedResult->message->fresh()->sent_by_type);
        $this->assertSame(1, Dialog::query()->count());
        $this->assertSame(1, Message::query()->count());
    }

    public function test_store_inbound_message_replay_self_heals_dialog_confirmed_phone_for_legacy_contact_share(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Legacy contact share',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '201',
            'external_username' => 'telegram_user_201',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '301',
            'confirmed_phone_raw' => null,
            'confirmed_phone_normalized' => null,
            'phone_confirmed_at' => null,
            'phone_confirmed_via' => null,
        ]);

        $legacyMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'provider_event_key' => 'telegram-update-legacy-share',
            'external_chat_id' => '301',
            'external_message_id' => 'legacy-share-1',
            'text' => null,
            'received_at' => Carbon::parse('2026-03-28 18:10:00'),
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '301',
                externalUserId: '201',
                providerEventKey: 'telegram-update-legacy-share',
                externalMessageId: 'legacy-share-1',
                externalUsername: 'telegram_user_201',
                contactName: 'Legacy contact share',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '201',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 18:10:05'),
            ),
        );

        $this->assertTrue($legacyMessage->is($storedResult->message));
        $this->assertDatabaseHas('dialogs', [
            'id' => $dialog->id,
            'confirmed_phone_raw' => '+7 999 123 45 67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_via' => \App\Models\Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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
        $this->assertNotNull($storedMessage->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_CONTACT, $storedMessage->sent_by_type);
        $this->assertDatabaseHas('dialogs', [
            'id' => $storedMessage->dialog_id,
            'contact_id' => $otherRoot->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '301',
            'confirmed_phone_raw' => '8 (999) 123-45-67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_via' => \App\Models\Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);
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
        $this->assertDatabaseHas('dialogs', [
            'id' => $storedMessage->dialog_id,
            'confirmed_phone_raw' => '+7 999 123 45 67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_via' => \App\Models\Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);
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
        $this->assertDatabaseHas('dialogs', [
            'id' => $secondResult->message->fresh()->dialog_id,
            'contact_id' => $otherRoot->id,
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_via' => \App\Models\Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);
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
        $this->assertDatabaseHas('dialogs', [
            'id' => $storedResult->message->dialog_id,
            'confirmed_phone_raw' => '+7 999 123 45 67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_via' => \App\Models\Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);
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

    public function test_store_inbound_message_leaves_dialog_confirmed_phone_empty_for_unknown_format(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '900',
                externalUserId: '700',
                providerEventKey: 'max-update-unknown-phone',
                externalMessageId: 'unknown-phone-1',
                externalUsername: 'max_user',
                contactName: 'MAX contact',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: null,
                sharedContactUserId: '700',
                rawPayload: ['message' => ['body' => ['contact' => ['name' => 'MAX contact']]]],
                receivedAt: Carbon::parse('2026-03-28 19:20:00'),
            ),
        );

        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_UNKNOWN_FORMAT, $storedResult->phoneCaptureStatus);
        $this->assertDatabaseHas('dialogs', [
            'id' => $storedResult->message->dialog_id,
            'confirmed_phone_raw' => null,
            'confirmed_phone_normalized' => null,
            'phone_confirmed_at' => null,
            'phone_confirmed_via' => null,
        ]);
    }

    public function test_newer_max_inbound_without_chat_id_clears_stale_dialog_chat_id(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '700',
            'external_username' => 'max_user_700',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '900',
            'last_message_at' => Carbon::parse('2026-03-28 18:00:00'),
            'last_inbound_at' => Carbon::parse('2026-03-28 18:00:00'),
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '',
                externalUserId: '700',
                providerEventKey: 'max-update-user-route-fresh',
                externalMessageId: 'max-user-route-fresh-1',
                externalUsername: 'max_user_700',
                contactName: 'MAX contact',
                text: 'Привет из MAX',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['body' => ['text' => 'Привет из MAX']]],
                receivedAt: Carbon::parse('2026-03-28 18:05:00'),
            ),
        );

        $dialog->refresh();

        $this->assertSame($dialog->id, $storedResult->message->dialog_id);
        $this->assertSame($identity->id, $dialog->current_contact_identity_id);
        $this->assertNull($dialog->external_chat_id);
        $this->assertSame('2026-03-28 18:05:00', $dialog->last_message_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-28 18:05:00', $dialog->last_inbound_at?->format('Y-m-d H:i:s'));
    }

    public function test_store_inbound_message_reuses_existing_contact_for_same_platform_user_on_another_channel(): void
    {
        $firstChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $secondChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Existing contact',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $firstChannel->id,
            'platform' => $firstChannel->platform,
            'external_user_id' => 'cross-user-100',
            'external_username' => 'telegram_cross_100',
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $secondChannel,
            new IncomingBotMessage(
                platform: $secondChannel->platform,
                channelId: $secondChannel->id,
                externalChatId: 'cross-chat-100',
                externalUserId: 'cross-user-100',
                providerEventKey: 'telegram-cross-identity-100',
                externalMessageId: 'cross-100',
                externalUsername: 'telegram_cross_100',
                contactName: 'Existing contact',
                text: 'Привет со второго бота',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет со второго бота']],
                receivedAt: Carbon::parse('2026-04-07 16:40:00'),
            ),
        );

        $newIdentity = ContactIdentity::query()
            ->where('channel_id', $secondChannel->id)
            ->where('external_user_id', 'cross-user-100')
            ->firstOrFail();

        $this->assertSame($contact->id, $storedResult->message->contact_id);
        $this->assertSame($contact->id, $newIdentity->contact_id);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 2);
        $this->assertDatabaseHas('dialogs', [
            'contact_id' => $contact->id,
            'channel_id' => $secondChannel->id,
            'current_contact_identity_id' => $newIdentity->id,
            'external_chat_id' => 'cross-chat-100',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $secondChannel->id,
            'event' => 'contact.cross_channel_identity_linked',
        ]);
    }

    public function test_store_inbound_message_keeps_same_channel_identity_reuse_when_external_user_id_is_blank(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Legacy blank user',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '',
            'external_username' => 'legacy_blank_user',
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '',
                externalUserId: '',
                providerEventKey: 'max-blank-user-same-channel',
                externalMessageId: 'max-blank-user-1',
                externalUsername: 'legacy_blank_user',
                contactName: 'Legacy blank user',
                text: 'Привет из MAX',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['body' => ['text' => 'Привет из MAX']]],
                receivedAt: Carbon::parse('2026-04-07 16:45:00'),
            ),
        );

        $this->assertSame($contact->id, $storedResult->message->contact_id);
        $this->assertSame($identity->id, $storedResult->message->contact_identity_id);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
    }

    public function test_store_inbound_message_does_not_cross_link_same_external_user_id_across_platforms(): void
    {
        $telegramChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $maxChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $telegramContact = Contact::factory()->create([
            'name' => 'Telegram contact',
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $telegramContact->id,
            'channel_id' => $telegramChannel->id,
            'platform' => $telegramChannel->platform,
            'external_user_id' => 'cross-user-200',
            'external_username' => 'telegram_cross_200',
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $maxChannel,
            new IncomingBotMessage(
                platform: $maxChannel->platform,
                channelId: $maxChannel->id,
                externalChatId: 'max-chat-200',
                externalUserId: 'cross-user-200',
                providerEventKey: 'max-cross-identity-200',
                externalMessageId: 'max-cross-200',
                externalUsername: 'max_cross_200',
                contactName: 'MAX contact',
                text: 'Привет из MAX',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['body' => ['text' => 'Привет из MAX']]],
                receivedAt: Carbon::parse('2026-04-07 16:41:00'),
            ),
        );

        $this->assertNotSame($telegramContact->id, $storedResult->message->contact_id);
        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $maxChannel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => 'cross-user-200',
        ]);
    }

    public function test_store_inbound_message_links_new_channel_identity_to_root_contact_from_merged_chain(): void
    {
        $firstChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $secondChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $rootContact = Contact::factory()->create([
            'name' => 'Root contact',
        ]);
        $mergedContact = Contact::factory()->create([
            'name' => 'Merged contact',
            'merged_into_contact_id' => $rootContact->id,
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $mergedContact->id,
            'channel_id' => $firstChannel->id,
            'platform' => $firstChannel->platform,
            'external_user_id' => 'cross-user-300',
            'external_username' => 'telegram_cross_300',
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $secondChannel,
            new IncomingBotMessage(
                platform: $secondChannel->platform,
                channelId: $secondChannel->id,
                externalChatId: 'cross-chat-300',
                externalUserId: 'cross-user-300',
                providerEventKey: 'telegram-cross-identity-300',
                externalMessageId: 'cross-300',
                externalUsername: 'telegram_cross_300',
                contactName: 'Root contact',
                text: 'Привет после merge',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет после merge']],
                receivedAt: Carbon::parse('2026-04-07 16:42:00'),
            ),
        );

        $newIdentity = ContactIdentity::query()
            ->where('channel_id', $secondChannel->id)
            ->where('external_user_id', 'cross-user-300')
            ->firstOrFail();

        $this->assertSame($rootContact->id, $storedResult->message->contact_id);
        $this->assertSame($rootContact->id, $newIdentity->contact_id);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $secondChannel->id,
            'event' => 'contact.cross_channel_identity_linked',
        ]);
    }

    public function test_store_inbound_message_logs_ambiguous_cross_channel_identity_and_falls_back_to_new_contact(): void
    {
        $firstChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $secondChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $thirdChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $firstRoot = Contact::factory()->create([
            'name' => 'First root',
        ]);
        $secondRoot = Contact::factory()->create([
            'name' => 'Second root',
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $firstRoot->id,
            'channel_id' => $firstChannel->id,
            'platform' => $firstChannel->platform,
            'external_user_id' => 'cross-user-400',
            'external_username' => 'telegram_cross_400_a',
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $secondRoot->id,
            'channel_id' => $secondChannel->id,
            'platform' => $secondChannel->platform,
            'external_user_id' => 'cross-user-400',
            'external_username' => 'telegram_cross_400_b',
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $thirdChannel,
            new IncomingBotMessage(
                platform: $thirdChannel->platform,
                channelId: $thirdChannel->id,
                externalChatId: 'cross-chat-400',
                externalUserId: 'cross-user-400',
                providerEventKey: 'telegram-cross-identity-400',
                externalMessageId: 'cross-400',
                externalUsername: 'telegram_cross_400_c',
                contactName: 'Fallback contact',
                text: 'Привет с ambiguous identity',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет с ambiguous identity']],
                receivedAt: Carbon::parse('2026-04-07 16:43:00'),
            ),
        );

        $this->assertNotContains($storedResult->message->contact_id, [$firstRoot->id, $secondRoot->id]);
        $this->assertDatabaseCount('contacts', 3);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $thirdChannel->id,
            'event' => 'contact.cross_channel_identity_ambiguous',
        ]);
    }

    public function test_store_inbound_message_falls_back_to_new_contact_when_cross_channel_identity_has_broken_merge_chain(): void
    {
        $firstChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $secondChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $brokenContact = Contact::factory()->create([
            'name' => 'Broken contact',
            'merged_into_contact_id' => 999999,
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $brokenContact->id,
            'channel_id' => $firstChannel->id,
            'platform' => $firstChannel->platform,
            'external_user_id' => 'cross-user-500',
            'external_username' => 'telegram_cross_500',
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $secondChannel,
            new IncomingBotMessage(
                platform: $secondChannel->platform,
                channelId: $secondChannel->id,
                externalChatId: 'cross-chat-500',
                externalUserId: 'cross-user-500',
                providerEventKey: 'telegram-cross-identity-500',
                externalMessageId: 'cross-500',
                externalUsername: 'telegram_cross_500',
                contactName: 'Fallback after broken chain',
                text: 'Привет после broken chain',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет после broken chain']],
                receivedAt: Carbon::parse('2026-04-07 16:44:00'),
            ),
        );

        $this->assertNotSame($brokenContact->id, $storedResult->message->contact_id);
        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $secondChannel->id,
            'event' => 'contact.cross_channel_identity_broken_merge_chain',
        ]);
    }

    public function test_older_inbound_contact_share_does_not_override_newer_dialog_confirmed_phone(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '205',
            'external_username' => 'telegram_user_205',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '305',
            'confirmed_phone_raw' => '+7 999 111 11 11',
            'confirmed_phone_normalized' => '+79991111111',
            'phone_confirmed_at' => Carbon::parse('2026-03-28 20:00:00'),
            'phone_confirmed_via' => \App\Models\Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
            'last_message_at' => Carbon::parse('2026-03-28 20:00:00'),
            'last_inbound_at' => Carbon::parse('2026-03-28 20:00:00'),
        ]);

        app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '305',
                externalUserId: '205',
                providerEventKey: 'telegram-update-older-phone',
                externalMessageId: 'older-phone-1',
                externalUsername: 'telegram_user_205',
                contactName: 'Older phone',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 222 22 22',
                sharedContactUserId: '205',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 222 22 22']]],
                receivedAt: Carbon::parse('2026-03-28 19:00:00'),
            ),
        );

        $dialog->refresh();

        $this->assertSame('+7 999 111 11 11', $dialog->confirmed_phone_raw);
        $this->assertSame('+79991111111', $dialog->confirmed_phone_normalized);
        $this->assertSame('2026-03-28 20:00:00', $dialog->phone_confirmed_at?->format('Y-m-d H:i:s'));
    }
}
