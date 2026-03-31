<?php

namespace Tests\Feature;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentDialogsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_dialog_view_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Диалог')
            ->assertSee('Открыть контакт');
    }

    public function test_non_admin_cannot_open_dialog_view_page(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->actingAs($user)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertForbidden();
    }

    public function test_dialog_view_shows_only_messages_from_this_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман',
        ]);
        $telegram = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $max = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'platform' => $telegram->platform,
            'external_user_id' => 'tg-100',
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'platform' => $max->platform,
            'external_user_id' => 'max-100',
        ]);

        $targetDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'current_contact_identity_id' => $telegramIdentity->id,
        ]);
        $otherDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'current_contact_identity_id' => $maxIdentity->id,
        ]);

        Message::factory()->create([
            'dialog_id' => $targetDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegram->id,
            'text' => 'Сообщение Telegram',
            'received_at' => now()->subMinute(),
        ]);
        Message::factory()->create([
            'dialog_id' => $otherDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $max->id,
            'text' => 'Сообщение MAX',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $targetDialog->getRouteKey()])
            ->assertSee('Сообщение Telegram')
            ->assertDontSee('Сообщение MAX');
    }

    public function test_dialog_view_initially_loads_latest_fifty_messages(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(70);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()]);

        $messages = $component->get('conversationMessages');

        $this->assertCount(50, $messages);
        $this->assertSame('Сообщение 21', $messages[0]['display_text']);
        $this->assertSame('Сообщение 70', $messages[49]['display_text']);
        $component->assertSet('hasMoreOlderMessages', true)
            ->assertSee('Загрузить более ранние сообщения');
    }

    public function test_dialog_view_can_load_older_messages(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(70);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('loadOlderMessages')
            ->assertDispatched('dialog-history-older-messages-loaded');

        $messages = $component->get('conversationMessages');

        $this->assertCount(70, $messages);
        $this->assertSame('Сообщение 1', $messages[0]['display_text']);
        $this->assertSame('Сообщение 70', $messages[69]['display_text']);
    }

    public function test_dialog_view_hides_load_older_button_when_history_is_exhausted(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(20);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('hasMoreOlderMessages', false)
            ->assertDontSee('Загрузить более ранние сообщения');
    }

    public function test_dialog_view_uses_existing_conversation_renderer_labels_and_fallbacks(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
            'text' => null,
            'received_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'text' => null,
            'received_at' => now()->addSecond(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Автоответчик')
            ->assertSee('Автоответ')
            ->assertSee('Поделился номером телефона');
    }

    public function test_dialog_view_queries_messages_by_dialog_id_not_contact_id(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(3);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()]);

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, '"messages"'));

        $this->assertTrue($queries->isNotEmpty());
        $this->assertFalse($queries->contains(
            fn (string $query): bool => str_contains($query, '"messages"."contact_id"')
        ));
        $this->assertTrue($queries->contains(
            fn (string $query): bool => str_contains($query, '"dialog_id"')
        ));
    }

    public function test_dialog_view_contact_link_points_to_contact_modal_url(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $contact = $dialog->contact;

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]));

        $response->assertOk()
            ->assertSee('/admin/contacts?tableAction=view&amp;tableActionRecord='.$contact->id, escape: false);
    }

    public function test_dialog_view_shows_reply_composer(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Ответ')
            ->assertSee('Отправить');
    }

    public function test_dialog_view_can_send_reply_and_append_message_without_losing_loaded_history(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-dialog-reply-001',
                ],
            ]),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(70);
        $dialog->contact->update([
            'assigned_user_id' => null,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('loadOlderMessages')
            ->set('dialogReplyText', '  Ответ из dialog page  ')
            ->call('sendDialogReply')
            ->assertNotified()
            ->assertDispatched('dialog-reply-sent')
            ->assertSet('dialogReplyText', '')
            ->assertSee('Ответ из dialog page');

        $messages = $component->get('conversationMessages');

        $this->assertCount(71, $messages);
        $this->assertSame('Сообщение 1', $messages[0]['display_text']);
        $this->assertSame('Ответ из dialog page', $messages[70]['display_text']);

        $dialog->contact->refresh();

        $this->assertSame($admin->id, $dialog->contact->assigned_user_id);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'chat_id=66552012')
            && $request['text'] === 'Ответ из dialog page');
    }

    public function test_dialog_view_shows_auto_claim_hint_for_unassigned_contact(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->contact->update([
            'assigned_user_id' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Ответственный пока не выбран. Его можно выбрать выше, либо просто отправить сообщение — контакт закрепится за вами автоматически.');
    }

    public function test_dialog_view_disables_reply_for_foreign_assignee(): void
    {
        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Другой сотрудник',
        ]);
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->contact->update([
            'assigned_user_id' => $owner->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Контакт уже назначен сотруднику Другой сотрудник.');
    }

    public function test_dialog_view_disables_reply_for_unsendable_exact_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $admin->id,
            'name' => 'Герман Абрикосов',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => null,
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '',
            'external_message_id' => 'telegram-unsendable',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
    }

    protected function createDialogWithMessages(int $messagesCount = 1): Dialog
    {
        $channel = Channel::factory()->create([
            'name' => 'MAX-Лесли',
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
            'is_active' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
            'external_username' => 'german_a',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '66552012',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 926 352 71 11',
            'phone_normalized' => '79263527111',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        for ($index = 1; $index <= $messagesCount; $index++) {
            Message::factory()->create([
                'dialog_id' => $dialog->id,
                'contact_id' => $contact->id,
                'contact_identity_id' => $identity->id,
                'channel_id' => $channel->id,
                'text' => sprintf('Сообщение %d', $index),
                'received_at' => now()->subSeconds($messagesCount - $index),
                'external_message_id' => sprintf('msg-%d', $index),
                'provider_event_key' => sprintf('event-%d', $index),
            ]);
        }

        return $dialog->fresh(['contact.assignedUser', 'contact.phoneNumbers', 'contact.primaryIdentity', 'channel', 'currentContactIdentity']);
    }
}
