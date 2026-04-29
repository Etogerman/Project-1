<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\StoredInboundMessageResult;
use App\Jobs\SyncContactIdentityAvatarJob;
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
use App\Services\Dialogs\DialogConsolidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class StoreInboundMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_inbound_message_persists_received_at_in_application_timezone(): void
    {
        config()->set('app.timezone', 'Europe/Moscow');
        date_default_timezone_set('Europe/Moscow');

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-timezone-1',
                externalMessageId: 'timezone-1',
                text: 'Привет',
                messageParameter: null,
                receivedAt: Carbon::parse('2026-04-17 09:00:00', 'UTC'),
            ),
        );

        $storedResult->message->refresh();

        $this->assertSame('2026-04-17 12:00:00', $storedResult->message->received_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('messages', [
            'id' => $storedResult->message->id,
            'received_at' => '2026-04-17 12:00:00',
        ]);
    }

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
                messageParameter: 'TEXT_1',
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

    public function test_store_inbound_message_queues_telegram_avatar_sync_job(): void
    {
        Queue::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-avatar-1',
                externalMessageId: 'telegram-avatar-1',
                text: 'Привет',
                messageParameter: null,
                receivedAt: Carbon::parse('2026-04-17 15:00:00'),
                externalUserId: 'telegram-user-avatar',
                externalChatId: 'telegram-chat-avatar',
            ),
        );

        Queue::assertPushed(SyncContactIdentityAvatarJob::class, function (SyncContactIdentityAvatarJob $job) use ($storedResult): bool {
            return $job->contactIdentityId === $storedResult->message->contact_identity_id
                && $job->avatarUrl === null;
        });
    }

    public function test_store_inbound_message_queues_max_avatar_sync_job_when_payload_contains_avatar_url(): void
    {
        Queue::fake();

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
                providerEventKey: 'max-avatar-1',
                externalMessageId: 'max-avatar-1',
                externalUsername: 'max_user',
                contactName: 'MAX контакт',
                text: 'Привет из MAX',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['body' => ['text' => 'Привет из MAX']]],
                receivedAt: Carbon::parse('2026-04-17 15:05:00'),
                avatarUrl: 'https://cdn.max.example/avatar.png',
            ),
        );

        Queue::assertPushed(SyncContactIdentityAvatarJob::class, function (SyncContactIdentityAvatarJob $job) use ($storedResult): bool {
            return $job->contactIdentityId === $storedResult->message->contact_identity_id
                && $job->avatarUrl === 'https://cdn.max.example/avatar.png'
                && $job->externalChatId === '700';
        });
    }

    public function test_store_inbound_message_queues_max_avatar_sync_job_when_payload_has_chat_id_without_avatar_url(): void
    {
        Queue::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '701',
                externalUserId: '501',
                providerEventKey: 'max-avatar-chat-fallback-1',
                externalMessageId: 'max-avatar-chat-fallback-1',
                externalUsername: 'max_user',
                contactName: 'MAX контакт',
                text: 'Привет из MAX',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['body' => ['text' => 'Привет из MAX']]],
                receivedAt: Carbon::parse('2026-04-19 10:00:00'),
                avatarUrl: null,
            ),
        );

        Queue::assertPushed(SyncContactIdentityAvatarJob::class, function (SyncContactIdentityAvatarJob $job) use ($storedResult): bool {
            return $job->contactIdentityId === $storedResult->message->contact_identity_id
                && $job->avatarUrl === null
                && $job->externalChatId === '701';
        });
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
            messageParameter: 'TEXT_1',
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

    public function test_store_inbound_message_sets_auto_first_name_and_identity_display_name_for_new_contact(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: 'new-chat-1',
                externalUserId: 'new-user-1',
                providerEventKey: 'telegram-new-user-1',
                externalMessageId: 'new-user-1',
                externalUsername: 'telegram_new_user',
                contactName: 'Новое имя профиля',
                text: 'Здравствуйте',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Здравствуйте']],
                receivedAt: Carbon::parse('2026-04-12 15:00:00'),
            ),
        );

        $contact = $storedResult->message->contact()->firstOrFail()->fresh();
        $identity = $storedResult->message->contactIdentity()->firstOrFail()->fresh();

        $this->assertSame('Новое имя профиля', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertNull($contact->name);
        $this->assertSame('Новое имя профиля', $identity->display_name);
    }

    public function test_store_inbound_message_uses_latest_inbound_wins_for_auto_name(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Старое имя',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'auto-user-1',
            'display_name' => 'Старый профиль',
        ]);

        app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: 'auto-chat-1',
                externalUserId: 'auto-user-1',
                providerEventKey: 'telegram-auto-user-1',
                externalMessageId: 'auto-user-1',
                externalUsername: 'auto_user_1',
                contactName: 'Новое имя профиля',
                text: 'Привет',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет']],
                receivedAt: Carbon::parse('2026-04-12 15:05:00'),
            ),
        );

        $this->assertSame('Новое имя профиля', $contact->fresh()->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->fresh()->first_name_source);
        $this->assertSame('Новое имя профиля', $identity->fresh()->display_name);
    }

    public function test_store_inbound_message_replay_does_not_roll_back_newer_auto_name_state(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Старое имя',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'auto-replay-user-1',
            'display_name' => 'Старый профиль',
            'external_username' => 'older_username',
        ]);

        $olderInbound = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: 'auto-replay-chat-1',
            externalUserId: 'auto-replay-user-1',
            providerEventKey: 'telegram-auto-replay-older',
            externalMessageId: 'auto-replay-older',
            externalUsername: 'older_username',
            contactName: 'Более старое имя',
            text: 'Привет',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => 'Привет']],
            receivedAt: Carbon::parse('2026-04-12 15:20:00'),
        );

        $newerInbound = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: 'auto-replay-chat-1',
            externalUserId: 'auto-replay-user-1',
            providerEventKey: 'telegram-auto-replay-newer',
            externalMessageId: 'auto-replay-newer',
            externalUsername: 'newer_username',
            contactName: 'Более новое имя',
            text: 'Снова привет',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => 'Снова привет']],
            receivedAt: Carbon::parse('2026-04-12 15:25:00'),
        );

        app(StoreInboundMessageAction::class)->handle($channel, $olderInbound);
        app(StoreInboundMessageAction::class)->handle($channel, $newerInbound);
        app(StoreInboundMessageAction::class)->handle($channel, $olderInbound);

        $contact->refresh();
        $identity->refresh();

        $this->assertSame('Более новое имя', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertSame('Более новое имя', $identity->display_name);
        $this->assertSame('newer_username', $identity->external_username);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_store_inbound_message_stale_unique_inbound_does_not_roll_back_newer_auto_name_state(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Старое имя',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'auto-stale-user-1',
            'display_name' => 'Старый профиль',
            'external_username' => 'initial_username',
        ]);

        $newerInbound = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: 'auto-stale-chat-1',
            externalUserId: 'auto-stale-user-1',
            providerEventKey: 'telegram-auto-stale-newer',
            externalMessageId: 'auto-stale-newer',
            externalUsername: 'newer_username',
            contactName: 'Более новое имя',
            text: 'Снова привет',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => 'Снова привет']],
            receivedAt: Carbon::parse('2026-04-12 15:25:00'),
        );

        $staleUniqueInbound = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: 'auto-stale-chat-1',
            externalUserId: 'auto-stale-user-1',
            providerEventKey: 'telegram-auto-stale-older-unique',
            externalMessageId: 'auto-stale-older-unique',
            externalUsername: 'older_username',
            contactName: 'Более старое имя',
            text: 'Привет',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => 'Привет']],
            receivedAt: Carbon::parse('2026-04-12 15:20:00'),
        );

        app(StoreInboundMessageAction::class)->handle($channel, $newerInbound);
        app(StoreInboundMessageAction::class)->handle($channel, $staleUniqueInbound);

        $contact->refresh();
        $identity->refresh();

        $this->assertSame('Более новое имя', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertSame('Более новое имя', $identity->display_name);
        $this->assertSame('newer_username', $identity->external_username);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_store_inbound_message_equal_timestamp_unique_inbound_does_not_roll_back_auto_name_state(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Старое имя',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'auto-same-ts-user-1',
            'display_name' => 'Старый профиль',
            'external_username' => 'initial_username',
        ]);

        $newerInbound = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: 'auto-same-ts-chat-1',
            externalUserId: 'auto-same-ts-user-1',
            providerEventKey: 'telegram-auto-same-ts-newer',
            externalMessageId: 'auto-same-ts-newer',
            externalUsername: 'newer_username',
            contactName: 'Более новое имя',
            text: 'Снова привет',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => 'Снова привет']],
            receivedAt: Carbon::parse('2026-04-12 15:25:00'),
        );

        $staleUniqueInbound = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: 'auto-same-ts-chat-1',
            externalUserId: 'auto-same-ts-user-1',
            providerEventKey: 'telegram-auto-same-ts-older-unique',
            externalMessageId: 'auto-same-ts-older-unique',
            externalUsername: 'older_username',
            contactName: 'Более старое имя',
            text: 'Привет',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => 'Привет']],
            receivedAt: Carbon::parse('2026-04-12 15:25:00'),
        );

        app(StoreInboundMessageAction::class)->handle($channel, $newerInbound);
        app(StoreInboundMessageAction::class)->handle($channel, $staleUniqueInbound);

        $contact->refresh();
        $identity->refresh();

        $this->assertSame('Более новое имя', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertSame('Более новое имя', $identity->display_name);
        $this->assertSame('newer_username', $identity->external_username);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_store_inbound_message_same_second_telegram_update_id_refreshes_auto_name_state(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Старое имя',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'auto-same-second-user-1',
            'display_name' => 'Старый профиль',
            'external_username' => 'initial_username',
        ]);

        $olderInbound = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: 'auto-same-second-chat-1',
            externalUserId: 'auto-same-second-user-1',
            providerEventKey: '101',
            externalMessageId: 'same-second-older',
            externalUsername: 'older_username',
            contactName: 'Более старое имя',
            text: 'Привет',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => 'Привет']],
            receivedAt: Carbon::parse('2026-04-12 15:25:00'),
        );

        $newerInbound = new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: 'auto-same-second-chat-1',
            externalUserId: 'auto-same-second-user-1',
            providerEventKey: '102',
            externalMessageId: 'same-second-newer',
            externalUsername: 'newer_username',
            contactName: 'Более новое имя',
            text: 'Снова привет',
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => 'Снова привет']],
            receivedAt: Carbon::parse('2026-04-12 15:25:00'),
        );

        app(StoreInboundMessageAction::class)->handle($channel, $olderInbound);
        app(StoreInboundMessageAction::class)->handle($channel, $newerInbound);

        $contact->refresh();
        $identity->refresh();

        $this->assertSame('Более новое имя', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertSame('Более новое имя', $identity->display_name);
        $this->assertSame('newer_username', $identity->external_username);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_store_inbound_message_keeps_confirmed_first_name_and_refreshes_identity_display_name(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Подтверждённое имя',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'confirmed-user-1',
            'display_name' => 'Старое имя профиля',
        ]);

        app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: 'confirmed-chat-1',
                externalUserId: 'confirmed-user-1',
                providerEventKey: 'telegram-confirmed-user-1',
                externalMessageId: 'confirmed-user-1',
                externalUsername: 'confirmed_user_1',
                contactName: 'Новое имя из профиля',
                text: 'Привет',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет']],
                receivedAt: Carbon::parse('2026-04-12 15:10:00'),
            ),
        );

        $contact->refresh();
        $identity->refresh();

        $this->assertSame('Подтверждённое имя', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertSame('Новое имя из профиля', $identity->display_name);
    }

    public function test_store_inbound_message_saves_pending_auto_reply_source_for_parameter_when_final_gate_is_not_ready(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-pending-parameter-1',
                externalMessageId: 'pending-parameter-1',
                text: '/start PROMO_1',
                messageParameter: 'PROMO_1',
                receivedAt: Carbon::parse('2026-04-09 11:00:00'),
            ),
        );

        $dialog = Dialog::query()
            ->where('contact_id', $storedResult->message->contact_id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertSame($storedResult->message->id, $dialog->pending_auto_reply_source_message_id);
    }

    public function test_store_inbound_message_does_not_save_pending_auto_reply_source_without_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-without-parameter-1',
                externalMessageId: 'without-parameter-1',
                text: 'Просто сообщение',
                messageParameter: null,
                receivedAt: Carbon::parse('2026-04-09 11:05:00'),
            ),
        );

        $dialog = Dialog::query()
            ->where('contact_id', $storedResult->message->contact_id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertNull($dialog->pending_auto_reply_source_message_id);
    }

    public function test_store_inbound_message_does_not_save_pending_auto_reply_source_for_live_ready_contact(): void
    {
        config()->set('bitrix24.features.openlines_enabled', true);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        [$contact] = $this->createLiveReadyContact($channel, 'tg-live-user-1');

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                externalUserId: 'tg-live-user-1',
                providerEventKey: 'telegram-live-ready-parameter-1',
                externalMessageId: 'live-ready-parameter-1',
                text: '/start PROMO_READY',
                messageParameter: 'PROMO_READY',
                receivedAt: Carbon::parse('2026-04-09 11:10:00'),
            ),
        );

        $dialog = Dialog::query()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertSame($contact->id, $storedResult->message->contact_id);
        $this->assertNull($dialog->pending_auto_reply_source_message_id);
    }

    public function test_newer_parameter_inbound_overwrites_existing_pending_auto_reply_source(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $firstResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-parameter-overwrite-1',
                externalMessageId: 'parameter-overwrite-1',
                text: '/start PROMO_OLD',
                messageParameter: 'PROMO_OLD',
                receivedAt: Carbon::parse('2026-04-09 11:15:00'),
            ),
        );

        $secondResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-parameter-overwrite-2',
                externalMessageId: 'parameter-overwrite-2',
                text: '/start PROMO_NEW',
                messageParameter: 'PROMO_NEW',
                receivedAt: Carbon::parse('2026-04-09 11:16:00'),
            ),
        );

        $dialog = Dialog::query()
            ->where('contact_id', $firstResult->message->contact_id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertSame($secondResult->message->id, $dialog->pending_auto_reply_source_message_id);
    }

    public function test_older_parameter_inbound_does_not_override_newer_pending_auto_reply_source(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $newerResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-parameter-newer-first',
                externalMessageId: 'parameter-newer-first',
                text: '/start PROMO_NEW',
                messageParameter: 'PROMO_NEW',
                receivedAt: Carbon::parse('2026-04-09 11:20:00'),
            ),
        );

        $olderResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-parameter-older-second',
                externalMessageId: 'parameter-older-second',
                text: '/start PROMO_OLD',
                messageParameter: 'PROMO_OLD',
                receivedAt: Carbon::parse('2026-04-09 11:19:00'),
            ),
        );

        $dialog = Dialog::query()
            ->where('contact_id', $newerResult->message->contact_id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertNotSame($newerResult->message->id, $olderResult->message->id);
        $this->assertSame($newerResult->message->id, $dialog->pending_auto_reply_source_message_id);
    }

    public function test_immediate_ready_parameter_inbound_clears_stale_pending_auto_reply_source(): void
    {
        config()->set('bitrix24.features.openlines_enabled', true);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        [$contact] = $this->createLiveReadyContact($channel, 'tg-live-user-2');

        $staleResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                externalUserId: 'tg-live-user-2',
                providerEventKey: 'telegram-stale-parameter-1',
                externalMessageId: 'stale-parameter-1',
                text: '/start PROMO_STALE',
                messageParameter: 'PROMO_STALE',
                receivedAt: Carbon::parse('2026-04-09 11:25:00'),
            ),
        );

        $dialog = Dialog::query()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $staleResult->message->id,
        ])->save();

        app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                externalUserId: 'tg-live-user-2',
                providerEventKey: 'telegram-immediate-parameter-2',
                externalMessageId: 'immediate-parameter-2',
                text: '/start PROMO_IMMEDIATE',
                messageParameter: 'PROMO_IMMEDIATE',
                receivedAt: Carbon::parse('2026-04-09 11:26:00'),
            ),
        );

        $this->assertNull($dialog->fresh()->pending_auto_reply_source_message_id);
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
        $this->assertSame(Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE, $dialog->phone_confirmed_via);
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
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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

    public function test_store_inbound_message_falls_back_to_review_pending_when_merge_fails_with_dialog_consolidation_exception(): void
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
                ->andThrow(new DialogConsolidationException('Active scenario run blocks dialog consolidation.'));
        });

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: '305',
                externalUserId: '205',
                providerEventKey: 'telegram-update-305',
                externalMessageId: '305',
                externalUsername: 'telegram_user_305',
                contactName: 'Тестовый контакт 305',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: '205',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-03-28 19:20:00'),
            ),
        );

        $currentContact = Contact::query()->findOrFail($storedResult->message->contact_id);

        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING, $storedResult->phoneCaptureStatus);
        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $currentContact->duplicate_review_status);
        $this->assertDatabaseHas('dialogs', [
            'id' => $storedResult->message->dialog_id,
            'confirmed_phone_raw' => '+7 999 123 45 67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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

    public function test_store_inbound_message_does_not_auto_merge_phone_when_contact_belongs_to_frozen_cross_channel_identity_review_set(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $anchorContact = Contact::factory()->create([
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $otherRoot = Contact::factory()->create();

        ContactIdentity::factory()->create([
            'contact_id' => $anchorContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'freeze-user-500',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $otherRoot->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:freeze-user-500',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$otherRoot->id],
            'context_payload' => ['last_seen_channel_id' => $channel->id],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            new IncomingBotMessage(
                platform: $channel->platform,
                channelId: $channel->id,
                externalChatId: 'freeze-chat-500',
                externalUserId: 'freeze-user-500',
                providerEventKey: 'telegram-freeze-500',
                externalMessageId: 'freeze-500',
                externalUsername: 'freeze_user_500',
                contactName: 'Frozen anchor',
                text: null,
                inboundKind: IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE,
                sharedPhoneNumber: '+7 999 123 45 67',
                sharedContactUserId: 'freeze-user-500',
                rawPayload: ['message' => ['contact' => ['phone_number' => '+7 999 123 45 67']]],
                receivedAt: Carbon::parse('2026-04-07 16:50:00'),
            ),
        );

        $review = ContactDuplicateReview::query()->firstOrFail();

        $this->assertSame(StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING, $storedResult->phoneCaptureStatus);
        $this->assertSame($anchorContact->id, $storedResult->message->contact_id);
        $this->assertNull($otherRoot->fresh()->merged_into_contact_id);
        $this->assertDatabaseCount('contact_merge_logs', 0);
        $this->assertDatabaseCount('contact_duplicate_reviews', 1);
        $this->assertNull($review->trigger_message_id);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_merge_blocked_by_cross_channel_identity_review',
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

    public function test_store_inbound_message_creates_open_cross_channel_identity_review_and_anchor_contact(): void
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

        $anchorContactId = $storedResult->message->contact_id;
        $review = ContactDuplicateReview::query()->firstOrFail();

        $this->assertNotContains($anchorContactId, [$firstRoot->id, $secondRoot->id]);
        $this->assertDatabaseCount('contacts', 3);
        $this->assertDatabaseCount('contact_duplicate_reviews', 1);
        $this->assertSame($anchorContactId, $review->contact_id);
        $this->assertSame(ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY, $review->review_type);
        $this->assertSame('telegram:cross-user-400', $review->identity_key);
        $this->assertSame([$firstRoot->id, $secondRoot->id], $review->candidate_root_contact_ids);
        $this->assertSame($storedResult->message->id, $review->trigger_message_id);
        $this->assertNull($review->routed_contact_id);
        $this->assertSame($thirdChannel->id, data_get($review->context_payload, 'last_seen_channel_id'));
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $thirdChannel->id,
            'event' => 'contact.cross_channel_identity_ambiguous',
        ]);
    }

    public function test_store_inbound_message_reuses_existing_open_cross_channel_identity_review_and_anchor_contact(): void
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
        $fourthChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $firstRoot = Contact::factory()->create();
        $secondRoot = Contact::factory()->create();

        ContactIdentity::factory()->create([
            'contact_id' => $firstRoot->id,
            'channel_id' => $firstChannel->id,
            'platform' => $firstChannel->platform,
            'external_user_id' => 'cross-user-401',
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $secondRoot->id,
            'channel_id' => $secondChannel->id,
            'platform' => $secondChannel->platform,
            'external_user_id' => 'cross-user-401',
        ]);

        $firstResult = app(StoreInboundMessageAction::class)->handle(
            $thirdChannel,
            new IncomingBotMessage(
                platform: $thirdChannel->platform,
                channelId: $thirdChannel->id,
                externalChatId: 'cross-chat-401-a',
                externalUserId: 'cross-user-401',
                providerEventKey: 'telegram-cross-identity-401-a',
                externalMessageId: 'cross-401-a',
                externalUsername: 'telegram_cross_401_a',
                contactName: 'Anchor A',
                text: 'Первый ambiguous inbound',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Первый ambiguous inbound']],
                receivedAt: Carbon::parse('2026-04-07 16:43:00'),
            ),
        );

        $secondResult = app(StoreInboundMessageAction::class)->handle(
            $fourthChannel,
            new IncomingBotMessage(
                platform: $fourthChannel->platform,
                channelId: $fourthChannel->id,
                externalChatId: 'cross-chat-401-b',
                externalUserId: 'cross-user-401',
                providerEventKey: 'telegram-cross-identity-401-b',
                externalMessageId: 'cross-401-b',
                externalUsername: 'telegram_cross_401_b',
                contactName: 'Anchor B',
                text: 'Второй ambiguous inbound',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Второй ambiguous inbound']],
                receivedAt: Carbon::parse('2026-04-07 16:44:00'),
            ),
        );

        $review = ContactDuplicateReview::query()->firstOrFail();

        $this->assertSame($firstResult->message->contact_id, $secondResult->message->contact_id);
        $this->assertSame($secondResult->message->contact_id, $review->contact_id);
        $this->assertSame($secondResult->message->id, $review->trigger_message_id);
        $this->assertSame($fourthChannel->id, data_get($review->context_payload, 'last_seen_channel_id'));
        $this->assertDatabaseCount('contacts', 3);
        $this->assertDatabaseCount('contact_duplicate_reviews', 1);
        $this->assertDatabaseHas('contact_identities', [
            'contact_id' => $review->contact_id,
            'channel_id' => $thirdChannel->id,
            'external_user_id' => 'cross-user-401',
        ]);
        $this->assertDatabaseHas('contact_identities', [
            'contact_id' => $review->contact_id,
            'channel_id' => $fourthChannel->id,
            'external_user_id' => 'cross-user-401',
        ]);
    }

    public function test_store_inbound_message_does_not_repoint_ambiguity_trigger_message_for_regular_follow_up_on_anchor_channel(): void
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
        $firstRoot = Contact::factory()->create();
        $secondRoot = Contact::factory()->create();

        ContactIdentity::factory()->create([
            'contact_id' => $firstRoot->id,
            'channel_id' => $firstChannel->id,
            'platform' => $firstChannel->platform,
            'external_user_id' => 'cross-user-402',
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $secondRoot->id,
            'channel_id' => $secondChannel->id,
            'platform' => $secondChannel->platform,
            'external_user_id' => 'cross-user-402',
        ]);

        $firstResult = app(StoreInboundMessageAction::class)->handle(
            $thirdChannel,
            new IncomingBotMessage(
                platform: $thirdChannel->platform,
                channelId: $thirdChannel->id,
                externalChatId: 'cross-chat-402-a',
                externalUserId: 'cross-user-402',
                providerEventKey: 'telegram-cross-identity-402-a',
                externalMessageId: 'cross-402-a',
                externalUsername: 'telegram_cross_402_a',
                contactName: 'Anchor first',
                text: 'Первый ambiguous inbound',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Первый ambiguous inbound']],
                receivedAt: Carbon::parse('2026-04-07 16:45:00'),
            ),
        );

        $secondResult = app(StoreInboundMessageAction::class)->handle(
            $thirdChannel,
            new IncomingBotMessage(
                platform: $thirdChannel->platform,
                channelId: $thirdChannel->id,
                externalChatId: 'cross-chat-402-a',
                externalUserId: 'cross-user-402',
                providerEventKey: 'telegram-cross-identity-402-b',
                externalMessageId: 'cross-402-b',
                externalUsername: 'telegram_cross_402_a',
                contactName: 'Anchor first',
                text: 'Обычный follow-up',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Обычный follow-up']],
                receivedAt: Carbon::parse('2026-04-07 16:46:00'),
            ),
        );

        $review = ContactDuplicateReview::query()->firstOrFail();

        $this->assertSame($firstResult->message->contact_id, $secondResult->message->contact_id);
        $this->assertSame($firstResult->message->id, $review->trigger_message_id);
        $this->assertSame($thirdChannel->id, data_get($review->context_payload, 'last_seen_channel_id'));
    }

    public function test_store_inbound_message_routes_new_channel_to_current_root_from_terminal_resolved_identity_review(): void
    {
        $newChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $anchorContact = Contact::factory()->create();
        $historicalRoutedContact = Contact::factory()->create([
            'merged_into_contact_id' => null,
        ]);
        $currentRootContact = Contact::factory()->create([
            'name' => 'Current routed root',
        ]);

        $historicalRoutedContact->forceFill([
            'merged_into_contact_id' => $currentRootContact->id,
            'merged_at' => Carbon::parse('2026-04-07 16:47:00'),
            'merge_reason' => 'cross_channel_identity_resolution',
        ])->save();

        ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-403',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$historicalRoutedContact->id],
            'routed_contact_id' => $historicalRoutedContact->id,
            'status' => ContactDuplicateReview::STATUS_RESOLVED,
            'resolved_at' => Carbon::parse('2026-04-07 16:48:00'),
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $newChannel,
            new IncomingBotMessage(
                platform: $newChannel->platform,
                channelId: $newChannel->id,
                externalChatId: 'cross-chat-403',
                externalUserId: 'cross-user-403',
                providerEventKey: 'telegram-cross-identity-403',
                externalMessageId: 'cross-403',
                externalUsername: 'telegram_cross_403',
                contactName: 'Terminal route',
                text: 'Привет после resolved review',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет после resolved review']],
                receivedAt: Carbon::parse('2026-04-07 16:49:00'),
            ),
        );

        $newIdentity = ContactIdentity::query()
            ->where('channel_id', $newChannel->id)
            ->where('external_user_id', 'cross-user-403')
            ->firstOrFail();

        $this->assertSame($currentRootContact->id, $storedResult->message->contact_id);
        $this->assertSame($currentRootContact->id, $newIdentity->contact_id);
        $this->assertDatabaseCount('contact_duplicate_reviews', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $newChannel->id,
            'event' => 'contact.cross_channel_identity_routed_by_terminal_review',
        ]);
    }

    public function test_store_inbound_message_routes_new_channel_to_anchor_from_terminal_dismissed_identity_review(): void
    {
        $newChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $anchorContact = Contact::factory()->create([
            'name' => 'Dismiss anchor',
        ]);
        $candidateRoot = Contact::factory()->create();

        ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-404',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$candidateRoot->id],
            'routed_contact_id' => $anchorContact->id,
            'status' => ContactDuplicateReview::STATUS_DISMISSED,
            'resolved_at' => Carbon::parse('2026-04-07 16:50:00'),
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $newChannel,
            new IncomingBotMessage(
                platform: $newChannel->platform,
                channelId: $newChannel->id,
                externalChatId: 'cross-chat-404',
                externalUserId: 'cross-user-404',
                providerEventKey: 'telegram-cross-identity-404',
                externalMessageId: 'cross-404',
                externalUsername: 'telegram_cross_404',
                contactName: 'Dismiss route',
                text: 'Привет после dismissed review',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет после dismissed review']],
                receivedAt: Carbon::parse('2026-04-07 16:51:00'),
            ),
        );

        $newIdentity = ContactIdentity::query()
            ->where('channel_id', $newChannel->id)
            ->where('external_user_id', 'cross-user-404')
            ->firstOrFail();

        $this->assertSame($anchorContact->id, $storedResult->message->contact_id);
        $this->assertSame($anchorContact->id, $newIdentity->contact_id);
        $this->assertDatabaseCount('contact_duplicate_reviews', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $newChannel->id,
            'event' => 'contact.cross_channel_identity_routed_by_terminal_review',
        ]);
    }

    public function test_store_inbound_message_routes_by_terminal_review_using_review_contact_fallback_when_routed_contact_id_is_missing(): void
    {
        $newChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $currentRootContact = Contact::factory()->create([
            'name' => 'Current fallback root',
        ]);
        $anchorMergedContact = Contact::factory()->create([
            'name' => 'Historical anchor',
            'merged_into_contact_id' => $currentRootContact->id,
            'merged_at' => Carbon::parse('2026-04-07 16:52:00'),
            'merge_reason' => 'cross_channel_identity_resolution',
        ]);

        ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorMergedContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-405',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => null,
            'routed_contact_id' => null,
            'status' => ContactDuplicateReview::STATUS_RESOLVED,
            'resolved_at' => Carbon::parse('2026-04-07 16:53:00'),
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $newChannel,
            new IncomingBotMessage(
                platform: $newChannel->platform,
                channelId: $newChannel->id,
                externalChatId: 'cross-chat-405',
                externalUserId: 'cross-user-405',
                providerEventKey: 'telegram-cross-identity-405',
                externalMessageId: 'cross-405',
                externalUsername: 'telegram_cross_405',
                contactName: 'Terminal fallback route',
                text: 'Привет после terminal fallback',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['message' => ['text' => 'Привет после terminal fallback']],
                receivedAt: Carbon::parse('2026-04-07 16:54:00'),
            ),
        );

        $newIdentity = ContactIdentity::query()
            ->where('channel_id', $newChannel->id)
            ->where('external_user_id', 'cross-user-405')
            ->firstOrFail();

        $this->assertSame($currentRootContact->id, $storedResult->message->contact_id);
        $this->assertSame($currentRootContact->id, $newIdentity->contact_id);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $newChannel->id,
            'event' => 'contact.cross_channel_identity_routed_by_terminal_review',
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
        ]);
        $cycleContact = Contact::factory()->create([
            'name' => 'Cycle contact',
            'merged_into_contact_id' => $brokenContact->id,
            'merged_at' => now(),
        ]);
        $brokenContact->forceFill([
            'merged_into_contact_id' => $cycleContact->id,
            'merged_at' => now(),
        ])->save();
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
        $this->assertNotSame($cycleContact->id, $storedResult->message->contact_id);
        $this->assertDatabaseCount('contacts', 3);
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
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
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

    public function test_store_inbound_system_event_persists_and_updates_dialog_block_state(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        [$contact, $identity, $dialog] = $this->createLiveReadyContact($channel, 'telegram-unsub-1');

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundSystemEventMessage(
                channel: $channel,
                providerEventKey: 'telegram-unsub-block-1',
                systemEventCode: IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER,
                receivedAt: Carbon::parse('2026-04-14 12:00:00'),
                externalUserId: $identity->external_user_id,
                externalChatId: $dialog->external_chat_id,
            ),
        );

        $this->assertInstanceOf(StoredInboundMessageResult::class, $storedResult);
        $this->assertSame(Message::KIND_INBOUND_SYSTEM_EVENT, $storedResult->message->message_kind);
        $this->assertSame(Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER, $storedResult->message->system_event_code);
        $this->assertSame(Message::SENT_BY_TYPE_SYSTEM, $storedResult->message->sent_by_type);
        $this->assertSame(Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION, $storedResult->message->sent_by_system_code);
        $this->assertSame($contact->id, $storedResult->message->contact_id);
        $this->assertSame($identity->id, $storedResult->message->contact_identity_id);
        $this->assertSame($dialog->id, $storedResult->message->dialog_id);

        $dialog->refresh();

        $this->assertSame(Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER, $dialog->bot_subscription_status);
        $this->assertSame('2026-04-14 12:00:00', $dialog->bot_subscription_changed_at?->format('Y-m-d H:i:s'));
        $this->assertSame($storedResult->message->id, $dialog->bot_subscription_source_message_id);
    }

    public function test_store_inbound_system_event_without_existing_identity_is_ignored_without_creating_entities(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundSystemEventMessage(
                channel: $channel,
                providerEventKey: 'telegram-unsub-missing-identity',
                systemEventCode: IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER,
                receivedAt: Carbon::parse('2026-04-14 12:10:00'),
                externalUserId: 'missing-user-1',
                externalChatId: 'missing-user-1',
            ),
        );

        $this->assertNull($storedResult);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('dialogs', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.telegram_unsubscribe_ignored',
        ]);
    }

    public function test_store_inbound_system_event_replay_is_idempotent(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        [, $identity, $dialog] = $this->createLiveReadyContact($channel, 'telegram-unsub-2');

        $message = $this->makeInboundSystemEventMessage(
            channel: $channel,
            providerEventKey: 'telegram-unsub-block-replay',
            systemEventCode: IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER,
            receivedAt: Carbon::parse('2026-04-14 12:15:00'),
            externalUserId: $identity->external_user_id,
            externalChatId: $dialog->external_chat_id,
        );

        $firstResult = app(StoreInboundMessageAction::class)->handle($channel, $message);
        $secondResult = app(StoreInboundMessageAction::class)->handle($channel, $message);

        $this->assertInstanceOf(StoredInboundMessageResult::class, $firstResult);
        $this->assertInstanceOf(StoredInboundMessageResult::class, $secondResult);
        $this->assertTrue($firstResult->message->is($secondResult->message));
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_store_inbound_system_event_stale_block_does_not_override_newer_unblock_state(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        [, $identity, $dialog] = $this->createLiveReadyContact($channel, 'telegram-unsub-3');

        $newerUnblockResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundSystemEventMessage(
                channel: $channel,
                providerEventKey: 'telegram-unsub-unblock-newer',
                systemEventCode: IncomingBotMessage::SYSTEM_EVENT_BOT_UNBLOCKED_BY_USER,
                receivedAt: Carbon::parse('2026-04-14 12:30:00'),
                externalUserId: $identity->external_user_id,
                externalChatId: $dialog->external_chat_id,
            ),
        );

        $staleBlockResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundSystemEventMessage(
                channel: $channel,
                providerEventKey: 'telegram-unsub-block-stale',
                systemEventCode: IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER,
                receivedAt: Carbon::parse('2026-04-14 12:20:00'),
                externalUserId: $identity->external_user_id,
                externalChatId: $dialog->external_chat_id,
            ),
        );

        $this->assertInstanceOf(StoredInboundMessageResult::class, $newerUnblockResult);
        $this->assertInstanceOf(StoredInboundMessageResult::class, $staleBlockResult);

        $dialog->refresh();

        $this->assertNull($dialog->bot_subscription_status);
        $this->assertSame('2026-04-14 12:30:00', $dialog->bot_subscription_changed_at?->format('Y-m-d H:i:s'));
        $this->assertSame($newerUnblockResult->message->id, $dialog->bot_subscription_source_message_id);
        $this->assertDatabaseCount('messages', 2);
    }

    private function makeInboundUserMessage(
        Channel $channel,
        string $providerEventKey,
        string $externalMessageId,
        ?string $text,
        ?string $messageParameter,
        Carbon $receivedAt,
        string $externalUserId = '200',
        string $externalChatId = '300',
    ): IncomingBotMessage {
        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $externalChatId,
            externalUserId: $externalUserId,
            providerEventKey: $providerEventKey,
            externalMessageId: $externalMessageId,
            externalUsername: 'telegram_user_'.$externalUserId,
            contactName: 'Тестовый контакт '.$externalUserId,
            text: $text,
            messageParameter: $messageParameter,
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: ['message' => ['text' => $text]],
            receivedAt: $receivedAt,
        );
    }

    private function makeInboundSystemEventMessage(
        Channel $channel,
        string $providerEventKey,
        string $systemEventCode,
        Carbon $receivedAt,
        string $externalUserId,
        string $externalChatId,
    ): IncomingBotMessage {
        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $externalChatId,
            externalUserId: $externalUserId,
            providerEventKey: $providerEventKey,
            externalMessageId: null,
            externalUsername: 'telegram_user_'.$externalUserId,
            contactName: 'Тестовый контакт '.$externalUserId,
            text: null,
            inboundKind: IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: [
                'my_chat_member' => [
                    'old_chat_member' => ['status' => $systemEventCode === IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER ? 'member' : 'kicked'],
                    'new_chat_member' => ['status' => $systemEventCode === IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER ? 'kicked' : 'member'],
                ],
            ],
            receivedAt: $receivedAt,
            systemEventCode: $systemEventCode,
        );
    }

    /**
     * @return array{Contact, ContactIdentity, Dialog}
     */
    private function createLiveReadyContact(Channel $channel, string $externalUserId): array
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Иван',
            'city' => 'Москва',
            'country' => 'Россия',
            'age_range' => '26-35',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'b24-'.$externalUserId,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
            'bitrix24_linked_at' => Carbon::parse('2026-04-09 10:00:00'),
            'bitrix24_last_synced_at' => Carbon::parse('2026-04-09 10:05:00'),
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 555 55 55',
            'phone_normalized' => '+79995555555',
            'is_primary' => true,
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $externalUserId,
            'external_username' => 'telegram_user_'.$externalUserId,
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'dialog-'.$externalUserId,
        ]);

        return [$contact, $identity, $dialog];
    }
}
