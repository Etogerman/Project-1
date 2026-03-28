<?php

namespace Tests\Feature;

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Channels\Pages\ManageChannels;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class FilamentChannelsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_channels_page_and_see_resource(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Sales Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $this->actingAs($admin)
            ->get('/admin/channels')
            ->assertOk()
            ->assertSee('Каналы связи');

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertCanSeeTableRecords([$channel]);
    }

    public function test_active_non_admin_user_gets_forbidden_on_channels_page(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin/channels')
            ->assertForbidden();
    }

    public function test_admin_can_create_telegram_bot_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callAction('create', [
                'name' => 'Telegram Bot',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'credentials' => [
                    'token' => 'telegram-secret-token',
                ],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $channel = Channel::query()
            ->where('name', 'Telegram Bot')
            ->firstOrFail();

        $this->assertSame(Channel::PLATFORM_TELEGRAM, $channel->platform);
        $this->assertSame(Channel::CONNECTION_TYPE_BOT, $channel->connection_type);
        $this->assertTrue($channel->is_active);
        $this->assertSame('telegram-secret-token', $channel->credentials['token']);
    }

    public function test_admin_can_create_max_bot_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callAction('create', [
                'name' => 'MAX Bot',
                'platform' => Channel::PLATFORM_MAX,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'credentials' => [
                    'token' => 'max-secret-token',
                ],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $channel = Channel::query()
            ->where('name', 'MAX Bot')
            ->firstOrFail();

        $this->assertSame(Channel::PLATFORM_MAX, $channel->platform);
        $this->assertSame(Channel::CONNECTION_TYPE_BOT, $channel->connection_type);
        $this->assertSame('max-secret-token', $channel->credentials['token']);
    }

    public function test_token_is_saved_encrypted_and_not_visible_in_plain_text_in_database(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'plain-visible-token',
            ],
        ]);

        $storedCredentials = DB::table('channels')
            ->where('id', $channel->id)
            ->value('credentials');

        $this->assertIsString($storedCredentials);
        $this->assertStringNotContainsString('plain-visible-token', $storedCredentials);

        $channel->refresh();

        $this->assertSame('plain-visible-token', $channel->credentials['token']);
    }

    public function test_admin_can_edit_channel_without_overwriting_existing_token_with_empty_value(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Original Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'current-token',
                'webhook_secret' => 'saved-secret',
            ],
            'bot_external_id' => '101',
            'bot_username' => 'old_bot',
            'bot_name' => 'Old Bot',
            'bot_profile_url' => 'https://t.me/old_bot',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('edit', $channel, [
                'name' => 'Updated Telegram Bot',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'credentials' => [
                    'token' => '',
                ],
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertSame('Updated Telegram Bot', $channel->name);
        $this->assertFalse($channel->is_active);
        $this->assertSame('current-token', $channel->credentials['token']);
        $this->assertSame('saved-secret', $channel->credentials['webhook_secret']);
        $this->assertSame('old_bot', $channel->bot_username);
    }

    public function test_admin_can_update_channel_token_on_edit_without_losing_webhook_secret(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'old-token',
                'webhook_secret' => 'saved-secret',
            ],
            'bot_external_id' => '202',
            'bot_username' => 'old_username',
            'bot_name' => 'Old Username',
            'bot_profile_url' => 'https://t.me/old_username',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('edit', $channel, [
                'name' => $channel->name,
                'platform' => $channel->platform,
                'connection_type' => $channel->connection_type,
                'credentials' => [
                    'token' => 'new-token',
                ],
                'is_active' => $channel->is_active,
            ])
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertSame('new-token', $channel->credentials['token']);
        $this->assertSame('saved-secret', $channel->credentials['webhook_secret']);
        $this->assertNull($channel->bot_external_id);
        $this->assertNull($channel->bot_username);
        $this->assertNull($channel->bot_name);
        $this->assertNull($channel->bot_profile_url);

        $storedCredentials = DB::table('channels')
            ->where('id', $channel->id)
            ->value('credentials');

        $this->assertIsString($storedCredentials);
        $this->assertStringNotContainsString('new-token', $storedCredentials);
    }

    public function test_channel_record_title_is_human_readable(): void
    {
        $channel = Channel::factory()->create([
            'name' => 'Support Bot',
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $this->assertSame(
            sprintf('#%d %s (%s)', $channel->id, $channel->name, 'MAX'),
            ChannelResource::getRecordTitle($channel),
        );
    }

    public function test_delete_and_bulk_delete_are_forbidden_by_policy(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $channel));
        $this->assertFalse(Gate::forUser($admin)->allows('deleteAny', Channel::class));
    }

    public function test_admin_can_view_latest_messages_in_channel_view_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-100',
        ]);

        $autoReplySentAt = Carbon::create(2026, 3, 28, 10, 11, 12);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'provider_event_key' => 'telegram-update-900',
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-900',
            'text' => 'Нужна помощь',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
            'auto_reply_sent_at' => $autoReplySentAt,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'webhook.duplicate_ignored',
            'message' => 'Повторный webhook обработан без повторной отправки ответа.',
            'context' => [
                'provider_event_key' => 'telegram-update-900',
            ],
            'created_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('view', $channel)
            ->assertMountedActionModalSee('Последний webhook')
            ->assertMountedActionModalSee('Лента сообщений')
            ->assertMountedActionModalSee('ext-100')
            ->assertMountedActionModalSee('telegram-update-900')
            ->assertMountedActionModalSee('28.03.2026 10:11:12')
            ->assertMountedActionModalSee('Ответ отправлен')
            ->assertMountedActionModalSee('Дубликат проигнорирован')
            ->assertMountedActionModalSee('Нужна помощь')
            ->assertMountedActionModalSee('Входящее');
    }

    public function test_channel_modal_prefers_latest_saved_message_over_received_at_order(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => null,
            'text' => 'старт',
            'raw_payload' => ['message' => 'oldest'],
            'received_at' => now()->addYears(2),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => null,
            'text' => 'тест3',
            'raw_payload' => ['message' => 'middle'],
            'received_at' => now()->addYear(),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'mid.0000000003e3748c019d30476b8e52e7',
            'text' => 'тест5',
            'raw_payload' => ['message' => 'latest'],
            'received_at' => now()->subYear(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('view', $channel)
            ->assertMountedActionModalSee('mid.0000000003e3748c019d30476b8e52e7')
            ->assertMountedActionModalSee('тест5');

        $latestMessageResolver = new ReflectionMethod(ChannelResource::class, 'resolveLatestSavedMessage');
        $latestMessageResolver->setAccessible(true);

        /** @var Message $latestMessage */
        $latestMessage = $latestMessageResolver->invoke(null, $channel);

        $this->assertSame('тест5', $latestMessage->text);

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $latestPosition = strpos($recentMessagesHtml, 'тест5');
        $middlePosition = strpos($recentMessagesHtml, 'тест3');
        $oldestPosition = strpos($recentMessagesHtml, 'старт');

        $this->assertIsInt($latestPosition);
        $this->assertIsInt($middlePosition);
        $this->assertIsInt($oldestPosition);
        $this->assertTrue($latestPosition < $middlePosition);
        $this->assertTrue($middlePosition < $oldestPosition);
    }

    public function test_recent_messages_renderer_shows_provider_event_key_auto_reply_timestamp_and_pending_status(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-200',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'provider_event_key' => 'telegram-update-901',
            'external_chat_id' => 'chat-901',
            'external_message_id' => 'msg-901',
            'text' => 'Повторное сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => Carbon::create(2026, 3, 28, 12, 30, 0),
            'auto_reply_sent_at' => null,
        ]);

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Event key: telegram-update-901', $recentMessagesHtml);
        $this->assertStringContainsString('Автоответ: —', $recentMessagesHtml);
        $this->assertStringContainsString('Статус: Ответ еще не отправлен', $recentMessagesHtml);
    }

    public function test_recent_activity_renderer_shows_and_highlights_dedupe_events(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'webhook.duplicate_retry_reply',
            'message' => 'Повторный webhook использован для повторной отправки автоответа.',
            'context' => [
                'provider_event_key' => 'telegram-update-902',
            ],
            'created_at' => Carbon::create(2026, 3, 28, 12, 45, 0),
        ]);

        $recentActivityRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentActivityLogs');
        $recentActivityRenderer->setAccessible(true);

        $recentActivityHtml = $recentActivityRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Дубликат → retry ответа', $recentActivityHtml);
        $this->assertStringContainsString('Event key: telegram-update-902', $recentActivityHtml);
        $this->assertStringContainsString('data-dedupe-event="true"', $recentActivityHtml);
    }

    public function test_recent_messages_renderer_shows_outbound_reply_link(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-300',
        ]);

        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'provider_event_key' => 'telegram-update-903',
            'external_chat_id' => 'chat-903',
            'external_message_id' => 'msg-903',
            'text' => 'Входящее сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => Carbon::create(2026, 3, 28, 13, 0, 0),
            'auto_reply_sent_at' => Carbon::create(2026, 3, 28, 13, 0, 5),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $inboundMessage->id,
            'external_chat_id' => 'chat-903',
            'external_message_id' => 'out-903',
            'text' => 'Исходящий автоответ',
            'raw_payload' => ['message' => ['message_id' => 'out-903']],
            'received_at' => Carbon::create(2026, 3, 28, 13, 0, 5),
        ]);

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Исходящее', $recentMessagesHtml);
        $this->assertStringContainsString('Исходящий автоответ', $recentMessagesHtml);
        $this->assertStringContainsString('Связь: Ответ на event key: telegram-update-903', $recentMessagesHtml);
    }
}
