<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Dialogs\Pages\ListDialogs;
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
use Illuminate\Support\Str;
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

    public function test_active_admin_can_open_dialogs_inbox_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Диалоги')
            ->assertSee($dialog->contact->display_name);
    }

    public function test_dialogs_inbox_uses_current_dialog_identity_label_for_each_row(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$telegramDialog, $maxDialog] = $this->createMultiChannelDialogsForContactLabel();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Telegram Клиент')
            ->assertSee('MAX Клиент');
    }

    public function test_employee_can_open_dialog_view_page_with_reply_composer(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->actingAs($user)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="conversation-reply-form"', false);
    }

    public function test_dialog_view_uses_current_dialog_identity_label_in_contact_summary(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$telegramDialog] = $this->createMultiChannelDialogsForContactLabel();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $telegramDialog]))
            ->assertOk()
            ->assertSee('Telegram Клиент');
    }

    public function test_dialog_view_renders_current_dialog_messenger_name_in_technical_context(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$telegramDialog] = $this->createMultiChannelDialogsForContactLabel();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $telegramDialog]))
            ->assertOk()
            ->assertSee('Имя из мессенджера')
            ->assertSee('data-role="dialog-messenger-name"', false)
            ->assertSee('Telegram Клиент');
    }

    public function test_employee_can_open_dialogs_inbox_page(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Диалоги');
    }

    public function test_employee_without_dialogs_view_cannot_open_dialog_pages(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $dialog = $this->createDialogWithMessages();

        \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.view')
            ->update(['granted' => false]);

        $user = User::query()->findOrFail($user->id);

        $this->actingAs($user)
            ->get(DialogResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertForbidden();
    }

    public function test_dialog_policy_and_reply_helper_respect_disabled_employee_matrix_values(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $dialog = $this->createDialogWithMessages();

        \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.edit')
            ->update(['granted' => false]);

        $user = User::query()->findOrFail($user->id);

        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($user)->allows('viewAny', Dialog::class));
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($user)->allows('view', $dialog));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($user)->allows('update', $dialog));
        $this->assertFalse($user->canReplyInDialogs());
    }

    public function test_dialogs_inbox_defaults_to_requires_manual_reply_filter(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $openDialog = $this->createInboxDialog([
            'contactName' => 'Нужен ответ',
        ]);
        $closedDialog = $this->createInboxDialog([
            'contactName' => 'Ответ уже дан',
        ]);

        $closedInbound = $this->createDialogMessage($closedDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Вопрос закрыт',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->createDialogMessage($closedDialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'Ответ уже отправлен',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertTableFilterExists('requires_manual_reply')
            ->assertCanSeeTableRecords([$openDialog])
            ->assertCanNotSeeTableRecords([$closedDialog]);
    }

    public function test_dialogs_inbox_can_show_all_dialogs_when_requires_reply_filter_is_removed(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $openDialog = $this->createInboxDialog([
            'contactName' => 'Нужен ответ',
        ]);
        $closedDialog = $this->createInboxDialog([
            'contactName' => 'Ответ уже дан',
        ]);

        $closedInbound = $this->createDialogMessage($closedDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Вопрос закрыт',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->createDialogMessage($closedDialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'Ответ уже отправлен',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('requires_manual_reply')
            ->assertCanSeeTableRecords([$openDialog, $closedDialog]);
    }

    public function test_dialogs_inbox_status_is_scoped_to_dialog_not_contact(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Один контакт',
        ]);
        $telegram = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $max = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'platform' => $telegram->platform,
            'external_user_id' => 'tg-scope',
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'platform' => $max->platform,
            'external_user_id' => 'max-scope',
        ]);

        $openDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'current_contact_identity_id' => $telegramIdentity->id,
            'external_chat_id' => 'chat-open-dialog',
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ]);
        $closedDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'current_contact_identity_id' => $maxIdentity->id,
            'external_chat_id' => 'chat-closed-dialog',
            'last_message_at' => now(),
            'last_inbound_at' => now()->subMinutes(2),
            'last_outbound_at' => now(),
        ]);

        $this->createDialogMessage($openDialog, [
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegram->id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Диалог без ручного ответа',
            'received_at' => now()->subMinute(),
        ]);

        $closedInbound = $this->createDialogMessage($closedDialog, [
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $max->id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Диалог уже закрыт',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->createDialogMessage($closedDialog, [
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $max->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'Ручной ответ для второго диалога',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('requires_manual_reply')
            ->assertCanSeeTableRecords([$openDialog, $closedDialog])
            ->assertSee('Требует ответа')
            ->assertSee('Нет новых')
            ->assertSee('Диалог без ручного ответа')
            ->assertSee('Ручной ответ для второго диалога');
    }

    public function test_dialogs_inbox_hides_dialogs_of_merged_secondary_contacts(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $visibleDialog = $this->createInboxDialog([
            'contactName' => 'Root contact',
        ]);

        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $merged->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'merged-max',
        ]);
        $hiddenDialog = Dialog::factory()->create([
            'contact_id' => $merged->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-merged-hidden',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $this->createDialogMessage($hiddenDialog, [
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Merged dialog should stay hidden',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertCanSeeTableRecords([$visibleDialog])
            ->assertCanNotSeeTableRecords([$hiddenDialog]);
    }

    public function test_dialogs_inbox_filters_support_my_and_unassigned_dialogs(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $otherAdmin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $myDialog = $this->createInboxDialog([
            'contactName' => 'Мой диалог',
            'assignedUserId' => $admin->id,
        ]);
        $freeDialog = $this->createInboxDialog([
            'contactName' => 'Свободный диалог',
            'assignedUserId' => null,
        ]);
        $foreignDialog = $this->createInboxDialog([
            'contactName' => 'Чужой диалог',
            'assignedUserId' => $otherAdmin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('requires_manual_reply')
            ->filterTable('assigned_to_me')
            ->assertCanSeeTableRecords([$myDialog])
            ->assertCanNotSeeTableRecords([$freeDialog, $foreignDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('requires_manual_reply')
            ->filterTable('unassigned_dialogs')
            ->assertCanSeeTableRecords([$freeDialog])
            ->assertCanNotSeeTableRecords([$myDialog, $foreignDialog]);
    }

    public function test_dialogs_inbox_channel_and_route_filters_work(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $readyDialog = $this->createInboxDialog([
            'channelName' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => 'tg-ready-chat',
            'contactName' => 'Telegram ready',
        ]);
        $routeProblemDialog = $this->createInboxDialog([
            'channelName' => 'Telegram Broken',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => '',
            'contactName' => 'Telegram broken',
        ]);
        $tokenlessDialog = $this->createInboxDialog([
            'channelName' => 'Telegram No Token',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => 'tg-no-token-chat',
            'contactName' => 'Telegram no token',
            'hasToken' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('requires_manual_reply')
            ->filterTable('route_ready')
            ->assertCanSeeTableRecords([$readyDialog])
            ->assertCanNotSeeTableRecords([$routeProblemDialog, $tokenlessDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('requires_manual_reply')
            ->filterTable('route_problem')
            ->assertCanSeeTableRecords([$routeProblemDialog, $tokenlessDialog])
            ->assertCanNotSeeTableRecords([$readyDialog])
            ->assertSee('Нет токена');

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('requires_manual_reply')
            ->filterTable('channel_id', $readyDialog->channel_id)
            ->assertCanSeeTableRecords([$readyDialog])
            ->assertCanNotSeeTableRecords([$routeProblemDialog]);
    }

    public function test_dialogs_inbox_shows_preview_sender_badge_and_links_to_dialog_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог с автоответом',
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
            'text' => 'Автоответ системы',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'));

        $response->assertOk()
            ->assertSee('Автоответ системы')
            ->assertSee('Автоответчик')
            ->assertSee(DialogResource::getUrl('view', ['record' => $dialog]), escape: false);
    }

    public function test_dialog_view_route_status_matches_inbox_route_badge_for_same_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $readyDialog = $this->createInboxDialog([
            'channelName' => 'Telegram Ready View',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => 'tg-ready-view',
            'contactName' => 'Telegram ready view',
        ]);
        $problemDialog = $this->createInboxDialog([
            'channelName' => 'Telegram No Token View',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => 'tg-no-token-view',
            'contactName' => 'Telegram no token view',
            'hasToken' => false,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $readyDialog]))
            ->assertOk()
            ->assertSee('Маршрут готов');

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $problemDialog]))
            ->assertOk()
            ->assertSee('Нет токена');
    }

    public function test_dialogs_inbox_searches_contact_identity_chat_and_phone(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $targetDialog = $this->createInboxDialog([
            'contactName' => 'Герман Абрикосов',
            'externalUserId' => 'target-user-100',
            'externalUsername' => 'german_target',
            'externalChatId' => 'target-chat-100',
        ]);
        $otherDialog = $this->createInboxDialog([
            'contactName' => 'Другой контакт',
            'externalUserId' => 'other-user-200',
            'externalUsername' => 'other_target',
            'externalChatId' => 'other-chat-200',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $targetDialog->contact_id,
            'phone_raw' => '+7 926 352 71 11',
            'phone_normalized' => '+79263527111',
            'is_primary' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('Герман Абрикосов')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('german_target')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('target-chat-100')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('3527111')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);
    }

    public function test_dialogs_inbox_queries_messages_by_dialog_id_not_contact_id(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertCanSeeTableRecords([$dialog]);

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, '"messages"'));

        $this->assertTrue($queries->isNotEmpty());
        $this->assertFalse($queries->contains(
            fn (string $query): bool => str_contains($query, '"messages"."contact_id"')
        ));
        $this->assertTrue($queries->contains(
            fn (string $query): bool => str_contains($query, '"messages"."dialog_id"')
                || str_contains($query, 'messages.dialog_id = dialogs.id')
        ));
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

    public function test_dialog_view_shows_max_bot_started_payload_as_human_readable_system_event(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;
        $payload = str_repeat('TEXT_1-', 25);
        $expectedDisplayText = 'Открыл бота по диплинку: '.Str::limit($payload, 120, '...');

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => null,
            'raw_payload' => [
                'update_type' => 'bot_started',
                'payload' => $payload,
            ],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee($expectedDisplayText)
            ->assertDontSee('Системное сообщение');
    }

    public function test_dialog_view_shows_telegram_start_payload_as_human_readable_deep_link_event(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Продакшен',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
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
            'external_user_id' => '8010492155',
            'external_username' => 'german_a',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '8010492155',
        ]);
        $payload = str_repeat('TEXT_1-', 25);
        $expectedDisplayText = 'Открыл бота по диплинку: '.Str::limit($payload, 120, '...');

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start '.$payload,
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee($expectedDisplayText)
            ->assertDontSee('/start '.$payload);
    }

    public function test_dialog_view_keeps_plain_telegram_start_command_as_raw_text(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Продакшен',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
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
            'external_user_id' => '8010492155',
            'external_username' => 'german_a',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '8010492155',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('/start')
            ->assertDontSee('Открыл бота по диплинку');
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

    public function test_dialog_view_contact_link_points_to_contact_page_url(): void
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
            ->assertSee(ContactResource::getUrl('view', ['record' => $contact]), escape: false);
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

        $this->assertNull($dialog->contact->assigned_user_id);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'chat_id=66552012')
            && $request['text'] === 'Ответ из dialog page');
    }

    public function test_dialog_view_can_toggle_between_formatted_and_html_source_modes_for_html_reply(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-dialog-html-001',
                ],
            ]),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(3);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->set('dialogReplyFormat', Message::TEXT_FORMAT_HTML)
            ->set('dialogReplyText', '<b>HTML ответ</b>')
            ->call('sendDialogReply')
            ->assertNotified()
            ->assertDispatched('dialog-reply-sent')
            ->assertSeeHtml('<b>HTML ответ</b>')
            ->assertDontSeeHtml('&lt;b&gt;HTML ответ&lt;/b&gt;');

        $messages = $component->get('conversationMessages');

        $this->assertSame(Message::TEXT_FORMAT_HTML, $messages[3]['text_format']);
        $this->assertSame('HTML ответ', $messages[3]['display_text']);
        $this->assertSame('<b>HTML ответ</b>', $messages[3]['formatted_html']);
        $this->assertSame('<b>HTML ответ</b>', $messages[3]['html_source_text']);

        $component
            ->set('conversationDisplayMode', ViewDialog::CONVERSATION_DISPLAY_MODE_HTML)
            ->assertSeeHtml('&lt;b&gt;HTML ответ&lt;/b&gt;')
            ->assertDontSeeHtml('<b>HTML ответ</b>');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'chat_id=66552012')
            && $request['text'] === '<b>HTML ответ</b>'
            && $request['format'] === 'html');
    }

    public function test_employee_can_send_reply_from_dialog_page_without_reassigning_foreign_contact(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-employee-reply-001',
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->contact->update([
            'assigned_user_id' => $owner->id,
        ]);

        $initialMessagesCount = Message::query()->count();
        Livewire::actingAs($employee)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->set('dialogReplyText', 'Employee reply attempt')
            ->call('sendDialogReply')
            ->assertNotified()
            ->assertDispatched('dialog-reply-sent')
            ->assertSee('Employee reply attempt');

        $this->assertSame($initialMessagesCount + 1, Message::query()->count());

        $dialog->contact->refresh();

        $this->assertSame($owner->id, $dialog->contact->assigned_user_id);
    }

    public function test_dialog_view_does_not_show_auto_claim_hint_for_unassigned_contact(): void
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
            ->assertDontSee('контакт закрепится за вами автоматически');
    }

    public function test_dialog_view_does_not_block_reply_for_foreign_assignee(): void
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
            ->assertDontSee('Контакт уже назначен сотруднику Другой сотрудник.')
            ->assertSee('data-role="conversation-reply-form"', false);
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

    /**
     * @return array{Dialog, Dialog}
     */
    protected function createMultiChannelDialogsForContactLabel(): array
    {
        $contact = Contact::factory()->create([
            'name' => null,
            'first_name' => null,
            'last_name' => null,
        ]);
        $telegramChannel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => true,
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
            'is_active' => true,
        ]);
        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannel->id,
            'platform' => $telegramChannel->platform,
            'external_user_id' => 'telegram-contact-label',
            'display_name' => 'Telegram Клиент',
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'platform' => $maxChannel->platform,
            'external_user_id' => 'max-contact-label',
            'display_name' => 'MAX Клиент',
        ]);

        $telegramDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannel->id,
            'current_contact_identity_id' => $telegramIdentity->id,
            'external_chat_id' => 'telegram-contact-label-chat',
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ]);
        $maxDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'current_contact_identity_id' => $maxIdentity->id,
            'external_chat_id' => 'max-contact-label-chat',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $this->createDialogMessage($telegramDialog, [
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegramChannel->id,
            'text' => 'Телеграм диалог',
            'received_at' => now()->subMinute(),
        ]);
        $this->createDialogMessage($maxDialog, [
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $maxChannel->id,
            'text' => 'MAX диалог',
            'received_at' => now(),
        ]);

        return [
            $telegramDialog->fresh(['channel', 'currentContactIdentity', 'contact.assignedUser', 'contact.identities']),
            $maxDialog->fresh(['channel', 'currentContactIdentity', 'contact.assignedUser', 'contact.identities']),
        ];
    }

    /**
     * @param  array{
     *     contactName?:string,
     *     assignedUserId?:?int,
     *     channelName?:string,
     *     platform?:string,
     *     externalUserId?:string,
     *     externalUsername?:?string,
     *     externalChatId?:?string,
     *     hasToken?:bool
     * }  $attributes
     */
    protected function createInboxDialog(array $attributes = []): Dialog
    {
        $platform = $attributes['platform'] ?? Channel::PLATFORM_MAX;
        $hasToken = $attributes['hasToken'] ?? true;
        $channel = Channel::factory()->create([
            'name' => $attributes['channelName'] ?? ($platform === Channel::PLATFORM_TELEGRAM ? 'Telegram Support' : 'MAX Support'),
            'platform' => $platform,
            'credentials' => $hasToken ? ['token' => $platform.'-token'] : [],
            'is_active' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => $attributes['contactName'] ?? 'Inbox contact',
            'assigned_user_id' => $attributes['assignedUserId'] ?? null,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $attributes['externalUserId'] ?? 'external-user-'.fake()->unique()->numerify('###'),
            'external_username' => $attributes['externalUsername'] ?? null,
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => array_key_exists('externalChatId', $attributes)
                ? $attributes['externalChatId']
                : 'chat-'.fake()->unique()->numerify('###'),
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ]);

        $this->createDialogMessage($dialog, [
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Пользователь написал первым',
            'received_at' => now()->subMinute(),
        ]);

        return $dialog->fresh([
            'channel',
            'currentContactIdentity',
            'contact.assignedUser',
            'contact.primaryIdentity',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createDialogMessage(Dialog $dialog, array $attributes = []): Message
    {
        $receivedAt = $attributes['received_at'] ?? now();
        $message = Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id ?? '',
            'external_message_id' => 'msg-'.fake()->unique()->numerify('###'),
            'provider_event_key' => 'event-'.fake()->unique()->numerify('###'),
            'text' => 'Inbox message',
            'received_at' => $receivedAt,
        ], $attributes));

        $dialog->forceFill([
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $message->direction === Message::DIRECTION_INBOUND ? $receivedAt : $dialog->last_inbound_at,
            'last_outbound_at' => $message->direction === Message::DIRECTION_OUTBOUND ? $receivedAt : $dialog->last_outbound_at,
        ])->save();

        return $message;
    }
}
