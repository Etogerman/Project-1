<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class FilamentContactsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_contacts_page_and_see_records(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => null,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-100',
            'external_username' => 'customer_one',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-100',
            'external_message_id' => 'msg-100',
            'text' => 'Первое сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/contacts')
            ->assertOk()
            ->assertSee('Контакты');

        $this->assertSame('Контакты', ContactResource::getNavigationLabel());

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableColumnVisible('id')
            ->assertTableColumnVisible('inbox_status')
            ->assertCanSeeTableRecords([$contact])
            ->assertTableFilterExists('requires_manual_reply')
            ->assertTableActionExists('view', null, $contact)
            ->assertTableActionDoesNotExist('edit', null, $contact)
            ->assertTableHeaderActionsExistInOrder([]);
    }

    public function test_active_non_admin_user_gets_forbidden_on_contacts_page(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin/contacts')
            ->assertForbidden();
    }

    public function test_admin_can_view_contact_details_in_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => null,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-200',
            'external_username' => 'max_customer',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-700',
            'text' => 'Нужна помощь по заказу',
            'raw_payload' => [
                'debug' => 'max-debug',
                'message' => [
                    'mid' => 'msg-700',
                ],
            ],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Сводка')
            ->assertMountedActionModalSee('Последнее сообщение')
            ->assertMountedActionModalSee('Диагностика webhook')
            ->assertMountedActionModalSee('История сообщений')
            ->assertMountedActionModalSee('@max_customer')
            ->assertMountedActionModalSee('max-200')
            ->assertMountedActionModalSee('MAX Support')
            ->assertMountedActionModalSee('msg-700')
            ->assertMountedActionModalSee('max-debug')
            ->assertMountedActionModalDontSee('Identities list')
            ->assertMountedActionModalDontSee('Recent messages')
            ->assertMountedActionModalSee('Нужна помощь по заказу');
    }

    public function test_admin_can_see_inline_reply_composer_in_contact_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-901',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'provider_event_key' => 'telegram-update-901',
            'external_chat_id' => 'chat-901',
            'external_message_id' => 'msg-901',
            'text' => 'Входящее сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableActionExists('view', null, $contact)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('История сообщений')
            ->assertMountedActionModalSee('Диагностика webhook')
            ->assertMountedActionModalSee('Ответ')
            ->assertMountedActionModalSee('Отправить');
    }

    public function test_contact_diagnostics_show_latest_message_even_with_same_received_at_second(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
            'external_username' => null,
        ]);
        $receivedAt = now()->startOfSecond();

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-old',
            'text' => 'проверка',
            'raw_payload' => [
                'debug' => 'old-payload',
                'message' => ['body' => ['text' => 'проверка']],
            ],
            'received_at' => $receivedAt,
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-new',
            'text' => 'тест3',
            'raw_payload' => [
                'debug' => 'new-payload',
                'message' => ['body' => ['text' => 'тест3']],
            ],
            'received_at' => $receivedAt,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('msg-new')
            ->assertMountedActionModalSee('new-payload')
            ->assertMountedActionModalSee('тест3')
            ->assertMountedActionModalDontSee('old-payload');
    }

    public function test_contact_history_renderer_shows_inbound_and_outbound_messages_with_reply_link(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-900',
        ]);

        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-950',
            'external_chat_id' => 'chat-950',
            'external_message_id' => 'msg-950',
            'text' => 'Входящее сообщение от пользователя',
            'raw_payload' => ['debug' => 'inbound-payload'],
            'received_at' => now()->subSecond(),
            'auto_reply_sent_at' => now(),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $inboundMessage->id,
            'external_chat_id' => 'chat-950',
            'external_message_id' => 'out-950',
            'text' => 'Исходящий автоответ',
            'raw_payload' => ['provider' => 'telegram-send'],
            'received_at' => now(),
        ]);

        $historyRenderer = new ReflectionMethod(ContactResource::class, 'renderConversationHistory');
        $historyRenderer->setAccessible(true);

        $historyHtml = $historyRenderer->invoke(null, $contact)->toHtml();

        $this->assertStringContainsString('data-role="conversation-thread"', $historyHtml);
        $this->assertStringContainsString('data-role="conversation-message"', $historyHtml);
        $this->assertStringContainsString('data-direction="inbound"', $historyHtml);
        $this->assertStringContainsString('data-direction="outbound"', $historyHtml);
        $this->assertStringContainsString('data-kind="inbound_user"', $historyHtml);
        $this->assertStringContainsString('data-kind="outbound_auto_reply"', $historyHtml);
        $this->assertStringContainsString('Входящее', $historyHtml);
        $this->assertStringContainsString('Исходящее', $historyHtml);
        $this->assertStringContainsString('Входящее сообщение от пользователя', $historyHtml);
        $this->assertStringContainsString('Исходящий автоответ', $historyHtml);
        $this->assertStringContainsString('Telegram Support (Telegram)', $historyHtml);
        $this->assertStringContainsString('Пользователь', $historyHtml);
        $this->assertStringContainsString('Автоответ', $historyHtml);
        $this->assertStringContainsString('Event key: telegram-update-950', $historyHtml);
        $this->assertStringContainsString('Статус: Ответ отправлен', $historyHtml);
        $this->assertStringContainsString('Ответ на event key: telegram-update-950', $historyHtml);
        $this->assertLessThan(
            strpos($historyHtml, 'Исходящий автоответ'),
            strpos($historyHtml, 'Входящее сообщение от пользователя'),
        );
    }

    public function test_contact_history_renderer_shows_unknown_kind_for_historical_messages_without_classification(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-legacy',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => null,
            'external_chat_id' => 'chat-legacy',
            'external_message_id' => 'legacy-out',
            'text' => 'Историческое исходящее',
            'raw_payload' => ['provider' => 'legacy'],
            'received_at' => now(),
        ]);

        $historyRenderer = new ReflectionMethod(ContactResource::class, 'renderConversationHistory');
        $historyRenderer->setAccessible(true);

        $historyHtml = $historyRenderer->invoke(null, $contact)->toHtml();

        $this->assertStringContainsString('Историческое исходящее', $historyHtml);
        $this->assertStringContainsString('Не определен', $historyHtml);
        $this->assertStringContainsString('data-kind="unknown"', $historyHtml);
    }

    public function test_contact_history_renderer_shows_empty_state_when_contact_has_no_messages(): void
    {
        $contact = Contact::factory()->create();

        $historyRenderer = new ReflectionMethod(ContactResource::class, 'renderConversationHistory');
        $historyRenderer->setAccessible(true);

        $historyHtml = $historyRenderer->invoke(null, $contact)->toHtml();

        $this->assertStringContainsString('data-role="conversation-thread"', $historyHtml);
        $this->assertStringContainsString('data-role="conversation-empty"', $historyHtml);
        $this->assertStringContainsString('Сообщений ещё не было.', $historyHtml);
    }

    public function test_contacts_table_marks_contact_as_requires_reply_when_auto_reply_is_latest_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с автоответом',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-inbox-1',
        ]);

        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-inbox-1',
            'external_chat_id' => 'chat-inbox-1',
            'external_message_id' => 'msg-inbox-1',
            'text' => 'Пользователь написал первым',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now()->subMinute(),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $inboundMessage->id,
            'external_chat_id' => 'chat-inbox-1',
            'external_message_id' => 'out-inbox-1',
            'text' => 'Автоответ системы',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertCanSeeTableRecords([$contact])
            ->assertSee('Требует ответа')
            ->assertSee('Автоответ системы')
            ->assertSee('Автоответ')
            ->assertSee('Telegram Support (Telegram)');
    }

    public function test_contacts_table_marks_contact_as_no_new_after_manual_reply(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с ручным ответом',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-inbox-1',
        ]);

        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-max-inbox',
            'external_message_id' => 'msg-max-inbox',
            'text' => 'Нужно уточнение по заказу',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now()->subMinute(),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'reply_to_message_id' => $inboundMessage->id,
            'external_chat_id' => 'chat-max-inbox',
            'external_message_id' => 'out-max-inbox',
            'text' => 'Ручной ответ оператора',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertCanSeeTableRecords([$contact])
            ->assertSee('Нет новых')
            ->assertSee('Ручной ответ оператора')
            ->assertSee('Ручной ответ')
            ->assertSee('MAX Support (MAX)');
    }

    public function test_requires_manual_reply_filter_shows_only_contacts_that_need_reply(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $needsReply = Contact::factory()->create([
            'name' => 'Нужен ответ',
        ]);
        $needsReplyIdentity = ContactIdentity::factory()->create([
            'contact_id' => $needsReply->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-open',
        ]);

        Message::query()->create([
            'contact_id' => $needsReply->id,
            'contact_identity_id' => $needsReplyIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-open',
            'external_message_id' => 'msg-open',
            'text' => 'Нужно ответить',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now()->subMinute(),
        ]);

        $closedContact = Contact::factory()->create([
            'name' => 'Ответ уже дан',
        ]);
        $closedIdentity = ContactIdentity::factory()->create([
            'contact_id' => $closedContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-closed',
        ]);

        $closedInbound = Message::query()->create([
            'contact_id' => $closedContact->id,
            'contact_identity_id' => $closedIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-closed',
            'external_message_id' => 'msg-closed',
            'text' => 'Вопрос закрыт',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now()->subMinutes(2),
        ]);

        Message::query()->create([
            'contact_id' => $closedContact->id,
            'contact_identity_id' => $closedIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'reply_to_message_id' => $closedInbound->id,
            'external_chat_id' => 'chat-closed',
            'external_message_id' => 'out-closed',
            'text' => 'Ответ уже отправлен',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableFilterExists('requires_manual_reply')
            ->filterTable('requires_manual_reply')
            ->assertCanSeeTableRecords([$needsReply])
            ->assertCanNotSeeTableRecords([$closedContact]);
    }

    public function test_contacts_table_sorts_by_latest_saved_message_desc(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $olderContact = Contact::factory()->create([
            'name' => 'Старый контакт',
        ]);
        $olderIdentity = ContactIdentity::factory()->create([
            'contact_id' => $olderContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-older',
        ]);

        Message::query()->create([
            'contact_id' => $olderContact->id,
            'contact_identity_id' => $olderIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-older',
            'external_message_id' => 'msg-older',
            'text' => 'Старое сохранённое сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now()->addDay(),
        ]);

        $newerContact = Contact::factory()->create([
            'name' => 'Новый контакт',
        ]);
        $newerIdentity = ContactIdentity::factory()->create([
            'contact_id' => $newerContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-newer',
        ]);

        Message::query()->create([
            'contact_id' => $newerContact->id,
            'contact_identity_id' => $newerIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-newer',
            'external_message_id' => 'msg-newer',
            'text' => 'Более новое сохранённое сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now()->subDay(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertCanSeeTableRecords([$newerContact, $olderContact], inOrder: true);
    }

    public function test_inline_reply_composer_sends_telegram_message_and_creates_outbound_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99001,
                ],
            ]),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-902',
        ]);

        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-902',
            'external_chat_id' => 'chat-902',
            'external_message_id' => 'msg-902',
            'text' => 'Входящее сообщение от пользователя',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->set('inlineReplyText', '  Ручной ответ сотрудника  ')
            ->call('sendInlineReply')
            ->assertNotified()
            ->assertSet('inlineReplyText', '');

        Http::assertSent(function (Request $request) use ($channel): bool {
            return $request->url() === 'https://api.telegram.org/bot'.$channel->getToken().'/sendMessage'
                && $request['chat_id'] === 'chat-902'
                && $request['text'] === 'Ручной ответ сотрудника';
        });

        $outboundMessage = Message::query()
            ->where('contact_id', $contact->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->firstOrFail();

        $this->assertSame($identity->id, $outboundMessage->contact_identity_id);
        $this->assertSame($channel->id, $outboundMessage->channel_id);
        $this->assertSame('chat-902', $outboundMessage->external_chat_id);
        $this->assertSame('99001', $outboundMessage->external_message_id);
        $this->assertSame('Ручной ответ сотрудника', $outboundMessage->text);
        $this->assertSame(Message::KIND_OUTBOUND_MANUAL_REPLY, $outboundMessage->message_kind);
        $this->assertSame($inboundMessage->id, $outboundMessage->reply_to_message_id);

        $channel->refresh();

        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertDatabaseHas(ChannelActivityLog::class, [
            'channel_id' => $channel->id,
            'event' => 'contact.reply_sent',
            'level' => 'info',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Ручной ответ сотрудника')
            ->assertMountedActionModalSee('Исходящее')
            ->assertMountedActionModalSee('Ручной ответ')
            ->assertMountedActionModalSee('Ответ');
    }

    public function test_inline_reply_composer_sends_max_message_and_creates_outbound_message(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-manual-001',
                ],
            ]),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'mid.0000000003f780cc019d33311ef013fa',
            'external_chat_id' => '',
            'external_message_id' => 'mid.0000000003f780cc019d33311ef013fa',
            'text' => 'MAX входящее сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->set('inlineReplyText', 'Ручной ответ MAX')
            ->call('sendInlineReply')
            ->assertNotified()
            ->assertSet('inlineReplyText', '');

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
                && str_contains($request->url(), 'user_id=228532008')
                && $request['text'] === 'Ручной ответ MAX';
        });

        $outboundMessage = Message::query()
            ->where('contact_id', $contact->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->firstOrFail();

        $this->assertSame($identity->id, $outboundMessage->contact_identity_id);
        $this->assertSame($channel->id, $outboundMessage->channel_id);
        $this->assertSame('', $outboundMessage->external_chat_id);
        $this->assertSame('max-manual-001', $outboundMessage->external_message_id);
        $this->assertSame('Ручной ответ MAX', $outboundMessage->text);
        $this->assertSame(Message::KIND_OUTBOUND_MANUAL_REPLY, $outboundMessage->message_kind);
        $this->assertSame($inboundMessage->id, $outboundMessage->reply_to_message_id);
    }

    public function test_inline_reply_composer_does_not_create_outbound_message_when_provider_fails(): void
    {
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => false,
            ], 500),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-903',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'provider_event_key' => 'telegram-update-903',
            'external_chat_id' => 'chat-903',
            'external_message_id' => 'msg-903',
            'text' => 'Входящее сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->set('inlineReplyText', 'Ответ с ошибкой провайдера')
            ->call('sendInlineReply')
            ->assertNotified()
            ->assertSet('inlineReplyText', 'Ответ с ошибкой провайдера');

        $this->assertDatabaseCount('messages', 1);

        $channel->refresh();

        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNotNull($channel->last_error_at);
        $this->assertDatabaseHas(ChannelActivityLog::class, [
            'channel_id' => $channel->id,
            'event' => 'contact.reply_failed',
            'level' => 'error',
        ]);
    }

    public function test_inline_reply_composer_shows_error_when_contact_has_no_active_route_source(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => false,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-904',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'provider_event_key' => 'telegram-update-904',
            'external_chat_id' => 'chat-904',
            'external_message_id' => 'msg-904',
            'text' => 'Входящее сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->set('inlineReplyText', 'Ручной ответ без маршрута')
            ->call('sendInlineReply')
            ->assertNotified()
            ->assertSet('inlineReplyText', 'Ручной ответ без маршрута');

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseMissing(ChannelActivityLog::class, [
            'event' => 'contact.reply_sent',
        ]);
    }

    public function test_contact_modal_keeps_webhook_diagnostics_bound_to_latest_inbound_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'provider_event_key' => 'mid.0000000003f780cc019d33311ef013fa',
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-inbound',
            'text' => 'тест3',
            'raw_payload' => [
                'debug' => 'latest-inbound-payload',
            ],
            'received_at' => now()->subSecond(),
            'auto_reply_sent_at' => now(),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $inboundMessage->id,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-outbound',
            'text' => 'Привет бот находится в разработке.',
            'raw_payload' => [
                'debug' => 'outbound-provider-response',
            ],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('mid.0000000003f780cc019d33311ef013fa')
            ->assertMountedActionModalSee('latest-inbound-payload')
            ->assertMountedActionModalSee('Ответ отправлен')
            ->assertMountedActionModalDontSee('outbound-provider-response');
    }

    public function test_contact_modal_prefers_latest_saved_message_over_received_at_order(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
            'external_username' => null,
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => null,
            'text' => 'проверка',
            'raw_payload' => [
                'debug' => 'old-payload',
            ],
            'received_at' => now()->addYear(),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'mid.0000000003e3748c019d30476b8e52e7',
            'text' => 'тест3',
            'raw_payload' => [
                'debug' => 'new-payload',
            ],
            'received_at' => now()->subYear(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('mid.0000000003e3748c019d30476b8e52e7')
            ->assertMountedActionModalSee('new-payload')
            ->assertMountedActionModalSee('тест3')
            ->assertMountedActionModalDontSee('old-payload');
    }

    public function test_contacts_table_uses_latest_saved_message_for_last_message_column(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        $oldReceivedAt = now()->addYears(20)->startOfMinute();
        $latestSavedReceivedAt = now()->subMinute()->startOfMinute();

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => null,
            'text' => 'проверка',
            'raw_payload' => ['message' => 'old-payload'],
            'received_at' => $oldReceivedAt,
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-latest',
            'text' => 'тест7',
            'raw_payload' => ['message' => 'latest-payload'],
            'received_at' => $latestSavedReceivedAt,
        ]);

        $this->actingAs($admin)
            ->get('/admin/contacts')
            ->assertOk()
            ->assertSee($latestSavedReceivedAt->format('d.m.Y H:i'))
            ->assertDontSee($oldReceivedAt->format('d.m.Y H:i'));
    }

    public function test_contacts_table_supports_column_manager_and_toggleable_columns(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->tap(function ($component): void {
                $table = $component->instance()->getTable();

                $this->assertTrue($table->hasColumnManager());
                $this->assertTrue($table->getColumn('id')?->isToggleable());
                $this->assertTrue($table->getColumn('display_name')?->isToggleable());
                $this->assertTrue($table->getColumn('primaryIdentity.external_username')?->isToggleable());
            });
    }

    public function test_contact_policy_allows_only_read_access_for_active_admins(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Contact::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $contact));
        $this->assertFalse(Gate::forUser($admin)->allows('create', Contact::class));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $contact));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $contact));
        $this->assertFalse(Gate::forUser($admin)->allows('deleteAny', Contact::class));
    }
}
