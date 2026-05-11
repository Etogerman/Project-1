<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Dialogs\Pages\ListDialogs;
use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\Channel;
use App\Models\ChannelPeerSyncState;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Bots\ContactIdentityAvatarStorage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            ->assertDontSee('<h1 class="fi-header-heading', false)
            ->assertSee('Открыть контакт')
            ->assertSee('Сообщения диалога')
            ->assertSee('Написать клиенту')
            ->assertSee('aria-label="Текст ответа"', false)
            ->assertDontSee('Технический контекст')
            ->assertDontSee('Маршрут и идентификаторы')
            ->assertDontSee('Этот блок нужен для диагностики маршрута')
            ->assertDontSee('История текущего канала')
            ->assertDontSee('Сообщение уйдёт в этот диалог')
            ->assertDontSee('Ответ будет отправлен от имени выбранного канала')
            ->assertDontSee('<label for="conversation-reply-textarea" class="ac-field-label">', false);
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

    public function test_dialogs_inbox_page_enables_live_polling(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->createInboxDialog();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('wire:poll.10s', escape: false);
    }

    public function test_dialogs_inbox_uses_separate_hidden_columns_for_identity_and_phone_details(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Только контакт',
            'contactFirstName' => 'Только контакт',
            'externalUserId' => 'tg-user-555',
            'externalUsername' => 'dialog_hidden_user',
        ]);
        $dialog->forceFill([
            'confirmed_phone_raw' => '+7 900 123 45 67',
        ])->save();
        $dialog = $dialog->fresh([
            'channel',
            'currentContactIdentity',
            'contact.assignedUser',
            'contact.primaryIdentity',
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertTableColumnExists(
                'contact_label',
                fn ($column): bool => $column->getLabel() === 'Контакт' && $column->getDescriptionBelow() === null,
                $dialog,
            )
            ->assertTableColumnVisible('contact_label')
            ->assertTableColumnStateSet('contact_label', 'Только контакт', $dialog)
            ->assertTableColumnExists(
                'external_user_id',
                fn ($column): bool => $column->getLabel() === 'Внешний ID'
                    && $column->isToggleable()
                    && $column->isToggledHiddenByDefault(),
                $dialog,
            )
            ->assertTableColumnStateSet('external_user_id', 'tg-user-555', $dialog)
            ->assertTableColumnExists(
                'external_username',
                fn ($column): bool => $column->getLabel() === 'Username'
                    && $column->isToggleable()
                    && $column->isToggledHiddenByDefault(),
                $dialog,
            )
            ->assertTableColumnStateSet('external_username', '@dialog_hidden_user', $dialog)
            ->assertTableColumnExists(
                'phone_label',
                fn ($column): bool => $column->getLabel() === 'Номер телефона'
                    && $column->isToggleable()
                    && $column->isToggledHiddenByDefault(),
                $dialog,
            )
            ->assertTableColumnStateSet('phone_label', '+7 900 123 45 67', $dialog);
    }

    public function test_dialogs_inbox_does_not_show_identity_and_phone_details_inside_contact_column_by_default(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Чистый контакт',
            'contactFirstName' => 'Чистый контакт',
            'externalUserId' => 'hidden-route-777',
            'externalUsername' => 'hidden_dialog_username',
        ]);
        $dialog->forceFill([
            'confirmed_phone_raw' => '+7 901 555 44 33',
        ])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Чистый контакт')
            ->assertDontSee('hidden-route-777')
            ->assertDontSee('@hidden_dialog_username')
            ->assertDontSee('+7 901 555 44 33');
    }

    public function test_dialogs_inbox_shows_derived_stage_label_for_null_persisted_stage(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();
        $dialog->forceFill([
            'stage' => null,
            'phone_confirmed_at' => now(),
        ])->save();
        $dialog = $dialog->fresh([
            'channel',
            'currentContactIdentity',
            'contact.assignedUser',
            'contact.primaryIdentity',
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertTableColumnStateSet('stage', 'Телефон получен', $dialog);
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

    public function test_dialogs_inbox_shows_telegram_account_dialog_even_when_route_is_not_sendable(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Inbox',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account inbox contact',
            'first_name' => 'Account inbox contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-inbox-1',
            'display_name' => 'Telegram Account Клиент',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-message-1',
            'text' => 'Новый account inbound',
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Account inbox contact')
            ->assertSee('Telegram Account Inbox')
            ->assertSee('Требует ответа')
            ->assertSee('Gateway не готов');
    }

    public function test_dialog_view_renders_media_badges_for_telegram_account_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Media',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account media contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-media-1',
            'display_name' => 'Telegram Account Медиа',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-media-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-media-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-media-message-1',
            'text' => 'Отправил медиа',
            'raw_payload' => [
                'media' => [
                    ['type' => 'photo'],
                    ['type' => 'document', 'file_name' => 'offer.pdf'],
                ],
            ],
            'received_at' => now(),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Фото')
            ->assertSee('Документ: offer.pdf')
            ->assertSee('Ожидает загрузки');

        $messages = $component->get('conversationMessages');

        $this->assertCount(1, $messages);
        $this->assertSame('Отправил медиа', $messages[0]['display_text']);
        $this->assertSame(['Фото', 'Документ: offer.pdf'], $messages[0]['media_badges']);
        $this->assertSame([
            ['label' => 'Ожидает загрузки x2', 'tone' => 'gray'],
        ], $messages[0]['media_state_badges']);
    }

    public function test_dialogs_inbox_preview_meta_shows_media_download_placeholder_for_telegram_account_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Placeholder Inbox',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account placeholder contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-placeholder-1',
            'display_name' => 'Telegram Account Placeholder',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-placeholder-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-placeholder-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-placeholder-message-1',
            'text' => null,
            'raw_payload' => [
                'media' => [
                    ['type' => 'photo'],
                ],
            ],
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Фото')
            ->assertSee('Ожидает загрузки');
    }

    public function test_dialog_view_renders_peer_sync_state_for_telegram_account_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Sync',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account sync contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-sync-1',
            'display_name' => 'Telegram Account Sync Клиент',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-sync-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        ChannelPeerSyncState::query()->create([
            'channel_id' => $channel->id,
            'peer_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id,
            'external_chat_id' => $dialog->external_chat_id,
            'backfill_status' => ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE,
            'oldest_imported_message_id' => '900001',
            'latest_observed_message_id' => '900050',
            'history_complete_at' => now()->setDate(2026, 4, 23)->setTime(13, 15, 0),
            'last_sync_error' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSeeHtml('data-role="dialog-peer-sync-status"')
            ->assertSee('Завершена')
            ->assertSee('23.04.2026 13:15')
            ->assertSee('900001')
            ->assertSee('900050');
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

    public function test_dialog_view_exposes_live_refresh_polling_configuration(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-poll-interval-ms="5000"', escape: false)
            ->assertSee('refreshDialogViewData', escape: false)
            ->assertSee("querySelector('[data-role=conversation-thread]')", escape: false)
            ->assertSee('window.requestAnimationFrame(() => this.scrollToBottom())', escape: false)
            ->assertDontSee('[data-role=\\"conversation-thread\\"]', escape: false);
    }

    public function test_dialog_view_renders_editable_status_select_for_pending_inbox_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Статус диалога')
            ->assertSee('Требует ответа')
            ->assertSee('Не требует ответа')
            ->assertSee('data-role="dialog-inbox-status-select"', escape: false)
            ->assertDontSee('data-role="dialog-inbox-status-help"', escape: false)
            ->assertDontSee('data-role="dialog-inbox-status-help-panel"', escape: false)
            ->assertDontSee('Новое входящее сообщение автоматически вернёт диалог в статус «Требует ответа».')
            ->assertDontSee('<p class="ac-field-help">', escape: false)
            ->assertDontSee('Рабочее место оператора')
            ->assertDontSee('Здесь показаны только сообщения текущего диалога в хронологическом порядке.');
    }

    public function test_dialog_view_renders_dialog_stage_strip_instead_of_stage_select(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $dialog->contact()->update([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $dialog->forceFill([
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
        ])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-stage-strip"', false)
            ->assertSee('data-current-tone="warning"', false)
            ->assertSee('class="ac-dialog-stage-strip ac-dialog-summary__stage"', false)
            ->assertSee('data-role="dialog-stage-step"', false)
            ->assertSee('Новый диалог')
            ->assertSee('Телефон получен')
            ->assertSee('Анкета заполнена')
            ->assertSee('МПЛ взял в работу')
            ->assertSee('Передан в МПП')
            ->assertSee('data-state="current"', false)
            ->assertSee('data-state="available"', false)
            ->assertDontSee('<p class="ac-dialog-stage-strip__label">', false)
            ->assertDontSee('class="ac-dialog-stage-strip ac-surface__divider"', false)
            ->assertDontSee('data-role="dialog-stage-select"', false);
    }

    public function test_dialog_view_uses_yellow_highlight_buttons_and_green_send_button(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $html = $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~class="[^"]*ac-button[^"]*ac-button--warning[^"]*"[^>]*>\s*Открыть контакт\s*</a>~su',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~class="[^"]*ac-button[^"]*ac-button--warning-soft[^"]*"[^>]*>\s*Обычный вид\s*</button>~su',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~class="[^"]*ac-button[^"]*ac-button--warning-soft[^"]*"[^>]*>\s*Просто текст\s*</button>~su',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~class="[^"]*ac-button[^"]*ac-button--success[^"]*"[^>]*>\s*<span[^>]*>Отправить</span>~su',
            $html,
        );
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
            ->assertSee('data-role="dialog-channel-label"', false)
            ->assertDontSee('<p class="ac-surface__subtitle">', false)
            ->assertSee('Telegram Клиент');
    }

    public function test_dialog_view_renders_messenger_phone_and_contact_phones_separately(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $contact = $dialog->contact()->firstOrFail();

        $dialog->forceFill([
            'confirmed_phone_raw' => '+7 999 000 00 01',
            'confirmed_phone_normalized' => '+79990000001',
        ])->save();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 900 111 22 33',
            'phone_normalized' => '+79001112233',
            'is_primary' => false,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Телефон мессенджера')
            ->assertSee('+7 999 000 00 01')
            ->assertSee('Телефоны контакта')
            ->assertSee('+7 926 352 71 11')
            ->assertSee('+7 900 111 22 33')
            ->assertDontSee('<p class="ac-dialog-summary__section-title">Диалог</p>', false)
            ->assertDontSee('<p class="ac-dialog-summary__section-title">Контакт и канал</p>', false);
    }

    public function test_dialog_view_renders_identity_avatar_in_dialog_header_when_avatar_exists(): void
    {
        Storage::fake('contact_avatars');
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $identity = $dialog->currentContactIdentity()->firstOrFail();

        Storage::disk('contact_avatars')->put('contact-identities/'.$identity->id.'/avatar/test-avatar.jpg', 'avatar-image');

        $identity->forceFill([
            'avatar_path' => 'contact-identities/'.$identity->id.'/avatar/test-avatar.jpg',
            'avatar_updated_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-contact-avatar-image"', false);
    }

    public function test_dialog_view_renders_identity_avatar_in_dialog_header_when_only_legacy_public_avatar_exists(): void
    {
        Storage::fake('contact_avatars');
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $identity = $dialog->currentContactIdentity()->firstOrFail();

        Storage::disk('public')->put('contact-identities/'.$identity->id.'/avatar/test-avatar.jpg', 'avatar-image');

        $identity->forceFill([
            'avatar_path' => 'contact-identities/'.$identity->id.'/avatar/test-avatar.jpg',
            'avatar_updated_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-contact-avatar-image"', false);
    }

    public function test_dialog_view_falls_back_to_legacy_public_avatar_when_contact_avatar_storage_read_fails(): void
    {
        Storage::fake('contact_avatars');
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $identity = $dialog->currentContactIdentity()->firstOrFail();
        $avatarPath = 'contact-identities/'.$identity->id.'/avatar/test-avatar.jpg';

        Storage::disk('public')->put($avatarPath, 'avatar-image');

        $identity->forceFill([
            'avatar_path' => $avatarPath,
            'avatar_updated_at' => now(),
        ])->save();

        $avatarStorage = \Mockery::mock(ContactIdentityAvatarStorage::class);
        $avatarStorage->shouldReceive('exists')
            ->once()
            ->with($avatarPath)
            ->andThrow(new \RuntimeException('Temporary object storage failure.'));
        $avatarStorage->shouldReceive('url')->never();

        app()->instance(ContactIdentityAvatarStorage::class, $avatarStorage);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-contact-avatar-image"', false);
    }

    public function test_dialog_view_renders_avatar_fallback_when_identity_avatar_is_missing(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$telegramDialog] = $this->createMultiChannelDialogsForContactLabel();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $telegramDialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-contact-avatar-fallback"', false)
            ->assertSee('TК');
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

        DB::table('role_permissions')
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

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.edit')
            ->update(['granted' => false]);

        $user = User::query()->findOrFail($user->id);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Dialog::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $dialog));
        $this->assertFalse(Gate::forUser($user)->allows('update', $dialog));
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
            ->assertTableFilterExists('inbox_status')
            ->assertSet('tableFilters.inbox_status.value', DialogInboxStatusData::CODE_REQUIRES_REPLY)
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
            ->removeTableFilter('inbox_status')
            ->assertCanSeeTableRecords([$openDialog, $closedDialog]);
    }

    public function test_dialogs_inbox_status_filter_supports_all_inbox_states(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $requiresReplyDialog = $this->createInboxDialog([
            'contactName' => 'Требует ответа',
        ]);
        $notRequiredDialog = $this->createInboxDialog([
            'contactName' => 'Не требует ответа',
        ]);
        $noNewDialog = $this->createInboxDialog([
            'contactName' => 'Нет новых',
        ]);

        $dismissedInbound = Message::query()
            ->where('dialog_id', $notRequiredDialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $notRequiredDialog->forceFill([
            'manual_reply_dismissed_source_message_id' => $dismissedInbound->id,
        ])->save();

        $closedInbound = Message::query()
            ->where('dialog_id', $noNewDialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $this->createDialogMessage($noNewDialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'На это сообщение уже ответили',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_NOT_REQUIRED)
            ->assertCanSeeTableRecords([$notRequiredDialog])
            ->assertCanNotSeeTableRecords([$requiresReplyDialog, $noNewDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_NO_NEW)
            ->assertCanSeeTableRecords([$noNewDialog])
            ->assertCanNotSeeTableRecords([$requiresReplyDialog, $notRequiredDialog]);
    }

    public function test_dialogs_inbox_default_requires_reply_filter_hides_manually_dismissed_dialogs(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $openDialog = $this->createInboxDialog([
            'contactName' => 'Нужен ответ',
        ]);
        $dismissedDialog = $this->createInboxDialog([
            'contactName' => 'Ответ не нужен',
        ]);
        $dismissedInbound = Message::query()
            ->where('dialog_id', $dismissedDialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $dismissedDialog->forceFill([
            'manual_reply_dismissed_source_message_id' => $dismissedInbound->id,
        ])->save();

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertCanSeeTableRecords([$openDialog])
            ->assertCanNotSeeTableRecords([$dismissedDialog]);
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
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Telegram Support',
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
            ->removeTableFilter('inbox_status')
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
            ->removeTableFilter('inbox_status')
            ->filterTable('assigned_to_me')
            ->assertCanSeeTableRecords([$myDialog])
            ->assertCanNotSeeTableRecords([$freeDialog, $foreignDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
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
            ->removeTableFilter('inbox_status')
            ->filterTable('route_ready')
            ->assertCanSeeTableRecords([$readyDialog])
            ->assertCanNotSeeTableRecords([$routeProblemDialog, $tokenlessDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->filterTable('route_problem')
            ->assertCanSeeTableRecords([$routeProblemDialog, $tokenlessDialog])
            ->assertCanNotSeeTableRecords([$readyDialog])
            ->assertSee('Нет токена');

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
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

    public function test_dialogs_inbox_shows_bitrix24_sender_badge_for_bitrix24_openlines_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог с Bitrix24',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'provider_event_key' => 'bitrix24-openlines:preview-1',
            'text' => 'Ответ из Bitrix24',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->assertSee('Ответ из Bitrix24')
            ->assertSee('Bitrix24');
    }

    public function test_dialogs_inbox_preview_ignores_dialog_status_history_note(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог со статусом',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'text' => 'Оператор изменил статус диалога',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'));

        $response->assertOk()
            ->assertSee('Пользователь написал первым')
            ->assertDontSee('Оператор изменил статус диалога');
    }

    public function test_dialogs_inbox_preview_keeps_latest_legacy_message_with_null_kind(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог с legacy preview',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Старое обычное сообщение',
            'received_at' => now()->subMinutes(2),
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => null,
            'text' => 'Последнее legacy сообщение',
            'received_at' => now()->subMinute(),
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'text' => 'Оператор изменил статус диалога',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'));

        $response->assertOk()
            ->assertSee('Последнее legacy сообщение')
            ->assertDontSee('Старое обычное сообщение')
            ->assertDontSee('Оператор изменил статус диалога');
    }

    public function test_dialogs_inbox_keeps_system_unsubscribe_preview_without_marking_dialog_as_requires_reply(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Системный диалог',
            'channelName' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $latestInbound = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $latestInbound->id,
            'text' => 'Оператор уже ответил',
            'received_at' => now()->subSeconds(10),
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => null,
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertCanNotSeeTableRecords([$dialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->assertCanSeeTableRecords([$dialog])
            ->assertSee('Клиент заблокировал бота')
            ->assertSee('Система')
            ->assertSee('Нет новых');
    }

    public function test_dialog_view_renders_telegram_unsubscribe_as_system_message_badge(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $dialog->channel()->update([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'name' => 'Telegram Support',
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ])->save();

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => null,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Клиент заблокировал бота')
            ->assertSee('Системное уведомление')
            ->assertDontSee('data-role="conversation-sender"', false)
            ->assertDontSee('Входящее');
    }

    public function test_dialog_view_renders_dialog_stage_history_as_system_message(): void
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
            'contact_id' => $contact?->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'text' => 'Оператор Герман изменил этап диалога: Анкета заполнена -> МПЛ взял в работу',
            'received_at' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Системное уведомление')
            ->assertDontSee('data-role="conversation-sender"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~data-kind="outbound_dialog_status_change"[^>]*class="[^"]*ac-message--system[^"]*"~su',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '~data-kind="outbound_dialog_status_change"[^>]*class="[^"]*ac-message--outbound[^"]*"~su',
            $html,
        );
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

    public function test_dialogs_inbox_searches_contact_profile_identity_chat_and_phone_without_legacy_name(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $targetDialog = $this->createInboxDialog([
            'contactName' => 'Legacy target name',
            'contactFirstName' => 'Герман',
            'contactLastName' => 'Абрикосов',
            'externalUserId' => 'target-user-100',
            'externalUsername' => 'german_target',
            'displayName' => 'Telegram Клиент',
            'externalChatId' => 'target-chat-100',
        ]);
        $otherDialog = $this->createInboxDialog([
            'contactName' => 'Legacy other name',
            'contactFirstName' => 'Другой',
            'contactLastName' => 'Контакт',
            'externalUserId' => 'other-user-200',
            'externalUsername' => 'other_target',
            'displayName' => 'MAX Клиент',
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
            ->searchTable('Telegram Клиент')
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

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('Legacy target name')
            ->assertCanNotSeeTableRecords([$targetDialog, $otherDialog]);
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
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Telegram Support',
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
            ->assertSee('Показать более ранние');
    }

    public function test_dialog_view_live_refresh_appends_new_messages_without_losing_local_state(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $newOwner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Новый ответственный',
        ]);
        $dialog = $this->createDialogWithMessages(3);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->set('dialogReplyText', 'Черновик без потери')
            ->set('dialogReplyFormat', Message::TEXT_FORMAT_HTML)
            ->set('conversationDisplayMode', ViewDialog::CONVERSATION_DISPLAY_MODE_HTML);

        $dialog->contact->update([
            'assigned_user_id' => $newOwner->id,
        ]);

        $newInboundMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Новое входящее без перезагрузки',
            'received_at' => now()->addSecond(),
            'external_message_id' => 'live-refresh-001',
            'provider_event_key' => 'live-refresh-event-001',
        ]);

        $component
            ->call('refreshDialogViewData')
            ->assertDispatched('dialog-history-refreshed')
            ->assertSet('dialogReplyText', 'Черновик без потери')
            ->assertSet('dialogReplyFormat', Message::TEXT_FORMAT_HTML)
            ->assertSet('conversationDisplayMode', ViewDialog::CONVERSATION_DISPLAY_MODE_HTML)
            ->assertSee('Новое входящее без перезагрузки')
            ->assertSee('Новый ответственный');

        $messages = $component->get('conversationMessages');

        $this->assertCount(4, $messages);
        $this->assertSame('Новое входящее без перезагрузки', $messages[3]['display_text']);
        $this->assertSame($newInboundMessage->id, $component->get('latestKnownMessageId'));
    }

    public function test_dialog_view_live_refresh_does_not_duplicate_already_loaded_messages(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(1);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Сообщение только один раз',
            'received_at' => now()->addSecond(),
            'external_message_id' => 'live-refresh-002',
            'provider_event_key' => 'live-refresh-event-002',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('refreshDialogViewData')
            ->call('refreshDialogViewData')
            ->assertDispatched('dialog-history-refreshed');

        $messages = $component->get('conversationMessages');

        $this->assertCount(2, $messages);
        $this->assertSame([
            'Сообщение 1',
            'Сообщение только один раз',
        ], array_column($messages, 'display_text'));
    }

    public function test_dialog_view_live_refresh_inserts_late_arriving_message_into_chronological_position(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(3);
        $secondMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('text', 'Сообщение 2')
            ->firstOrFail();

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Поздно дошедшее сообщение',
            'received_at' => $secondMessage->received_at,
            'external_message_id' => 'live-refresh-late-001',
            'provider_event_key' => 'live-refresh-late-event-001',
        ]);

        $component
            ->call('refreshDialogViewData')
            ->assertDispatched('dialog-history-refreshed');

        $messages = $component->get('conversationMessages');

        $this->assertCount(4, $messages);
        $this->assertSame([
            'Сообщение 1',
            'Сообщение 2',
            'Поздно дошедшее сообщение',
            'Сообщение 3',
        ], array_column($messages, 'display_text'));
    }

    public function test_dialog_view_load_older_messages_does_not_duplicate_late_message_inserted_by_live_refresh(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(70);
        $twentiethMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('text', 'Сообщение 20')
            ->firstOrFail();

        $lateMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Поздно дошедшее сообщение',
            'received_at' => $twentiethMessage->received_at,
            'external_message_id' => 'live-refresh-late-older-001',
            'provider_event_key' => 'live-refresh-late-older-event-001',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('refreshDialogViewData')
            ->assertSet('nextOlderCursor.id', $lateMessage->id)
            ->call('loadOlderMessages')
            ->assertDispatched('dialog-history-older-messages-loaded');

        $messages = $component->get('conversationMessages');
        $messageIds = array_column($messages, 'id');
        $messageTexts = array_column($messages, 'display_text');

        $this->assertCount(71, $messages);
        $this->assertCount(71, array_unique($messageIds));
        $this->assertSame([
            'Сообщение 20',
            'Поздно дошедшее сообщение',
            'Сообщение 21',
        ], array_slice($messageTexts, 19, 3));
        $this->assertSame('Сообщение 1', $messageTexts[0]);
        $this->assertSame('Сообщение 70', $messageTexts[70]);
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
            ->assertSee('Поделился номером телефона')
            ->assertDontSee('data-role="conversation-channel"', false);
    }

    public function test_dialog_view_shows_bitrix24_sender_label_for_bitrix24_openlines_message(): void
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
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'provider_event_key' => 'bitrix24-openlines:view-1',
            'text' => 'Сообщение из Bitrix24',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Bitrix24')
            ->assertSee('Сообщение из Bitrix24')
            ->assertDontSee('Оператор:');
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
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Продакшен',
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
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Продакшен',
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
            ->assertSee('Отправить')
            ->assertSee('onkeydown="if (event.key === \'Enter\'', false)
            ->assertSee('querySelector(\'[data-role=conversation-reply-submit]\').click()', false);
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

    public function test_dialog_view_can_mark_dialog_as_not_required_and_write_history_note(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Оператор Статуса',
        ]);
        $dialog = $this->createInboxDialog();
        $latestInbound = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('dialogInboxStatusSelection', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->set('dialogInboxStatusSelection', DialogInboxStatusData::CODE_NOT_REQUIRED)
            ->call('updateDialogInboxStatus')
            ->assertNotified()
            ->assertSet('dialogInboxStatusSelection', DialogInboxStatusData::CODE_NOT_REQUIRED)
            ->assertSee('Не требует ответа')
            ->assertSee('Оператор Оператор Статуса изменил статус диалога: Требует ответа -> Не требует ответа');

        $this->assertSame(
            $latestInbound->id,
            $dialog->fresh()->manual_reply_dismissed_source_message_id,
        );

        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => $admin->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'reply_to_message_id' => $latestInbound->id,
            'text' => 'Оператор Оператор Статуса изменил статус диалога: Требует ответа -> Не требует ответа',
        ]);

        $historyMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertEquals(
            [
                'event' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
                'from_status' => [
                    'code' => DialogInboxStatusData::CODE_REQUIRES_REPLY,
                    'label' => 'Требует ответа',
                ],
                'to_status' => [
                    'code' => DialogInboxStatusData::CODE_NOT_REQUIRED,
                    'label' => 'Не требует ответа',
                ],
                'reply_to_message_id' => $latestInbound->id,
                'dialog_id' => $dialog->id,
                'changed_by_user_id' => $admin->id,
            ],
            $historyMessage->raw_payload,
        );

        $this->assertNotNull($historyMessage->received_at);
    }

    public function test_dialog_view_live_refresh_returns_manually_dismissed_dialog_to_requires_reply_after_new_inbound(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();
        $latestInbound = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $dialog->forceFill([
            'manual_reply_dismissed_source_message_id' => $latestInbound->id,
        ])->save();

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('dialogInboxStatusSelection', DialogInboxStatusData::CODE_NOT_REQUIRED);

        $receivedAt = now()->addSecond();

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Новое сообщение после ручного закрытия',
            'received_at' => $receivedAt,
            'external_message_id' => 'dialog-status-refresh-001',
            'provider_event_key' => 'dialog-status-refresh-event-001',
        ]);

        $dialog->forceFill([
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $receivedAt,
        ])->save();

        $component
            ->call('refreshDialogViewData')
            ->assertSet('dialogInboxStatusSelection', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->assertSee('Новое сообщение после ручного закрытия');
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
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Telegram Support',
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
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Telegram Support',
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
     *     displayName?:?string,
     *     externalChatId?:?string,
     *     hasToken?:bool
     * }  $attributes
     */
    protected function createInboxDialog(array $attributes = []): Dialog
    {
        $platform = $attributes['platform'] ?? Channel::PLATFORM_MAX;
        $hasToken = $attributes['hasToken'] ?? true;
        $channelAttributes = [
            'name' => $attributes['channelName'] ?? ($platform === Channel::PLATFORM_TELEGRAM ? 'Telegram Support' : 'MAX Support'),
            'platform' => $platform,
            'credentials' => $hasToken ? ['token' => $platform.'-token'] : [],
            'is_active' => true,
        ];

        if ($platform === Channel::PLATFORM_TELEGRAM && $hasToken) {
            $channelAttributes = array_merge(
                $this->connectedTelegramChannelAttributes($platform.'-token'),
                $channelAttributes,
            );
        }

        $channel = Channel::factory()->create($channelAttributes);
        $contact = Contact::factory()->create([
            'name' => $attributes['contactName'] ?? 'Inbox contact',
            'first_name' => $attributes['contactFirstName'] ?? null,
            'last_name' => $attributes['contactLastName'] ?? null,
            'assigned_user_id' => $attributes['assignedUserId'] ?? null,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $attributes['externalUserId'] ?? 'external-user-'.fake()->unique()->numerify('###'),
            'display_name' => $attributes['displayName'] ?? null,
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

    /**
     * @return array<string, mixed>
     */
    protected function connectedTelegramChannelAttributes(string $token): array
    {
        return [
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => $token],
            'is_active' => true,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now(),
            'connection_error_message' => null,
            'provider_webhook_url' => null,
            'expected_webhook_url' => null,
        ];
    }
}
