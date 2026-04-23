<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\ContactStartTag;
use App\Models\ContactTimelineEvent;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Services\Contacts\CreateContactDuplicateReviewAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
            ->assertSee('Контакты')
            ->assertSee('Кнопки');

        $this->assertSame('Контакты', ContactResource::getNavigationLabel());

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableColumnVisible('tags_summary')
            ->assertTableColumnVisible('id')
            ->assertTableColumnVisible('inbox_status')
            ->assertTableColumnVisible('dedup_status')
            ->assertTableColumnVisible('tags_summary')
            ->assertCanSeeTableRecords([$contact])
            ->assertTableFilterExists('requires_manual_reply')
            ->assertTableFilterExists('assigned_to_me')
            ->assertTableFilterExists('unassigned_contacts')
            ->assertTableFilterExists('duplicate_review_pending')
            ->assertTableFilterExists('tags')
            ->assertTableActionExists('delete', null, $contact)
            ->assertTableActionHasIcon('delete', Heroicon::OutlinedTrash, $contact)
            ->assertTableActionDoesNotHaveLabel('delete', $contact)
            ->assertTableActionDoesNotExist('edit', null, $contact)
            ->assertTableHeaderActionsExistInOrder([]);
    }

    public function test_active_employee_can_open_contacts_page_in_read_only_mode(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Read only contact',
        ]);

        $this->actingAs($user)
            ->get('/admin/contacts')
            ->assertOk()
            ->assertSee('Контакты');

        Livewire::actingAs($user)
            ->test(ManageContacts::class)
            ->assertCanSeeTableRecords([$contact])
            ->assertTableActionDoesNotExist('delete', null, $contact);
    }

    public function test_contact_view_page_renders_flat_general_sections(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Ответственный оператор',
        ]);
        $tag = Tag::factory()->create([
            'name' => 'VIP',
            'slug' => 'vip',
            'color' => 'success',
            'is_active' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Имя из мессенджера',
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'last_name' => 'Абрикосов',
            'gender' => 'male',
            'age_years' => 34,
            'age_range' => '30_39',
            'birth_date' => '1991-03-02',
            'country' => 'Россия',
            'city' => 'Москва',
            'region' => 'Московская область',
            'region_status' => Contact::REGION_STATUS_RESOLVED,
            'region_source' => Contact::REGION_SOURCE_MANUAL,
            'pending_region_candidates' => ['Московская область', 'Москва'],
            'distance_to_moscow_km' => 0,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
            'distance_to_moscow_calculated_at' => now(),
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'data_collection_started_at' => now()->subHour(),
            'data_collection_current_field_started_at' => now()->subMinutes(10),
            'data_collection_attempts_count' => 1,
            'is_auto_reply_enabled' => true,
            'assigned_user_id' => $assignee->id,
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $contact->tags()->attach($tag);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
            'source' => ContactPhoneNumber::SOURCE_MANUAL,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Абрикосов Герман')
            ->assertSee('Общее')
            ->assertSee('Диалоги')
            ->assertSee('Битрикс24')
            ->assertSee('История')
            ->assertSee('Диагностика')
            ->assertSee('Данные клиента')
            ->assertSee('Откуда знаем имя?')
            ->assertSee('Клиент назвал')
            ->assertDontSee('Имя (мессенджер)')
            ->assertSee('Работа с контактом')
            ->assertSee('Локация')
            ->assertSee('Анкета')
            ->assertSee('Теги контакта')
            ->assertSee('Телефоны')
            ->assertSee('Ответственный')
            ->assertSee('Автоответы')
            ->assertDontSee('effective_age_years')
            ->assertDontSee('pending_region_candidates')
            ->assertDontSee('data_collection_current_field_started_at')
            ->assertDontSee('Имя в мессенджере')
            ->assertDontSee('Дедупликация')
            ->assertDontSee('Диагностика webhook')
            ->assertDontSee('Профиль')
            ->assertDontSee('Служебные данные');
    }

    public function test_contacts_table_shows_first_name_source_indicator_next_to_display_name(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'last_name' => 'Абрикосов',
            'name' => 'Legacy name',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertCanSeeTableRecords([$contact])
            ->assertSee('Герман Абрикосов')
            ->assertSee('Клиент назвал');
    }

    public function test_contact_view_page_header_uses_display_name_instead_of_legacy_name_fallback(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Legacy имя',
            'first_name' => null,
            'last_name' => null,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'header-contact-100',
            'display_name' => 'Telegram Клиент',
        ]);

        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'header-contact-chat',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Telegram Клиент')
            ->assertDontSee('Legacy имя')
            ->assertDontSee('Имя (мессенджер)');
    }

    public function test_admin_can_view_contact_diagnostics_tab_with_runtime_data(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Root контакт',
            'first_name' => 'Root',
            'last_name' => 'Контакт',
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Диагностика',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
            'merged_into_contact_id' => $root->id,
            'merged_at' => now()->subMinute(),
            'merge_reason' => 'phone_exact_match',
            'merge_trigger_phone' => '+79990000000',
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Runtime',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'runtime-user-100',
            'external_username' => 'runtime_customer',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-runtime-100',
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
            'last_outbound_at' => now(),
        ]);

        Message::query()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-runtime-100',
            'external_message_id' => 'msg-runtime-100',
            'provider_event_key' => 'evt-runtime-100',
            'text' => 'Проверка диагностики',
            'raw_payload' => [
                'debug' => 'payload-runtime-100',
                'message' => [
                    'id' => 'msg-runtime-100',
                ],
            ],
            'received_at' => now()->subMinute(),
            'auto_reply_sent_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Диагностика')
            ->set('activeTab', ViewContact::TAB_DIAGNOSTICS)
            ->assertSee('Последний inbound webhook')
            ->assertSee('Route context')
            ->assertSee('Identity')
            ->assertSee('Дедупликация')
            ->assertSee('msg-runtime-100')
            ->assertSee('evt-runtime-100')
            ->assertSee('payload-runtime-100')
            ->assertSee('Ответ отправлен')
            ->assertSee('Telegram Runtime')
            ->assertSee('chat-runtime-100')
            ->assertSee((string) $identity->id)
            ->assertSee('@runtime_customer')
            ->assertSee('Нужна проверка')
            ->assertSee('+79990000000')
            ->assertSee('Совпадение телефона');
    }

    public function test_employee_does_not_see_contact_diagnostics_tab_or_diagnostics_query(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт без диагностики',
        ]);

        Livewire::actingAs($employee)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertDontSee('Диагностика');

        $this->actingAs($employee)
            ->get(ContactResource::getUrl('view', ['record' => $contact, 'tab' => ViewContact::TAB_DIAGNOSTICS]))
            ->assertOk()
            ->assertSee('Данные клиента')
            ->assertDontSee('Последний inbound webhook')
            ->assertDontSee('Route context');
    }

    public function test_admin_can_open_contact_view_page_route(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Маршрут контакта',
        ]);

        $this->actingAs($admin)
            ->get(ContactResource::getUrl('view', ['record' => $contact]))
            ->assertOk()
            ->assertSee('Маршрут контакта');
    }

    public function test_contact_view_page_shows_minimal_indicator_for_merged_contact(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Главный клиент',
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $merged->getRouteKey()])
            ->assertSee('Контакт объединён с основным контактом')
            ->assertSee('#'.$root->id)
            ->assertSee($root->display_name)
            ->assertDontSee('История')
            ->assertDontSee('Склейки и проверки дублей')
            ->assertDontSee('Открытые проверки');
    }

    public function test_merged_contact_history_query_falls_back_to_general_tab(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(ContactResource::getUrl('view', ['record' => $merged, 'tab' => ViewContact::TAB_HISTORY]))
            ->assertOk()
            ->assertSee('Данные клиента')
            ->assertSee('Контакт объединён с основным контактом')
            ->assertDontSee('История событий контакта')
            ->assertDontSee('Комментарий оператора')
            ->assertDontSee('contact-tab-history');
    }

    public function test_contact_view_page_renders_bitrix_and_history_tabs(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $rootContact = Contact::factory()->create([
            'first_name' => 'Основной',
            'last_name' => 'Контакт',
        ]);
        $contact = Contact::factory()->create([
            'bitrix24_contact_id' => 'B24-C-100',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_sync_pending' => true,
            'bitrix24_deal_id' => 'B24-D-200',
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED,
            'bitrix24_history_sync_pending' => false,
            'merged_into_contact_id' => $rootContact->id,
            'merged_at' => now()->subDay(),
            'data_collection_started_at' => now()->subDays(3),
            'data_collection_completed_at' => now()->subDays(2),
        ]);
        DB::table('contacts')
            ->where('id', $contact->id)
            ->update([
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDay(),
            ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'history-dialog-100',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'history-chat-100',
        ]);
        DB::table('dialogs')
            ->where('id', $dialog->id)
            ->update([
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_BITRIX24)
            ->assertSee('Контакт в Bitrix24')
            ->assertSee('Сделка в Bitrix24')
            ->assertSee('История в Bitrix24')
            ->assertSee('bitrix24_sync_status')
            ->assertSee('bitrix24_history_sync_pending')
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('История событий контакта')
            ->assertSeeInOrder([
                'Контакт объединён',
                'Анкета завершена',
                'Анкета начата',
                'Появился диалог',
                'Контакт создан',
            ])
            ->assertSee('Контакт объединён с основным контактом')
            ->assertSee('MAX Support')
            ->assertDontSee('История событий контакта будет подключена следующим этапом.');
    }

    public function test_contact_history_tab_does_not_render_message_text(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        DB::table('contacts')
            ->where('id', $contact->id)
            ->update([
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'history-message-100',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'message-history-chat-100',
        ]);
        DB::table('dialogs')
            ->where('id', $dialog->id)
            ->update([
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
        Message::query()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'message-history-chat-100',
            'external_message_id' => 'message-history-100',
            'text' => 'Уникальный текст сообщения для истории',
            'provider_event_key' => 'provider-history-100',
            'received_at' => now()->subHours(12),
            'raw_payload' => ['message' => 'payload'],
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('История событий контакта')
            ->assertSee('Контакт создан')
            ->assertDontSee('Уникальный текст сообщения для истории');
    }

    public function test_contact_history_page_can_be_opened_directly_via_query_param(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/contacts/'.$contact->id.'?tab=history')
            ->assertOk()
            ->assertSee('История событий контакта')
            ->assertSee('Комментарий оператора');
    }

    public function test_contact_history_tab_supports_load_more(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        DB::table('contacts')
            ->where('id', $contact->id)
            ->update([
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ]);
        ContactTimelineEvent::query()->create([
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_OPERATOR_COMMENT,
            'actor_user_id' => $admin->id,
            'body' => 'Первый комментарий в истории',
            'occurred_at' => now(),
        ]);

        foreach (range(1, 21) as $index) {
            $channel = Channel::factory()->create([
                'name' => 'Канал '.$index,
                'platform' => $index % 2 === 0 ? Channel::PLATFORM_MAX : Channel::PLATFORM_TELEGRAM,
            ]);
            $identity = ContactIdentity::factory()->create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'platform' => $channel->platform,
                'external_user_id' => 'history-load-more-'.$index,
            ]);
            $dialog = Dialog::factory()->create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'current_contact_identity_id' => $identity->id,
                'external_chat_id' => 'history-load-more-chat-'.$index,
            ]);

            DB::table('dialogs')
                ->where('id', $dialog->id)
                ->update([
                    'created_at' => now()->subMinutes($index),
                    'updated_at' => now()->subMinutes($index),
                ]);
        }

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('Комментарий оператора')
            ->assertSee('Первый комментарий в истории')
            ->assertSee('Показать ещё')
            ->assertDontSee('Канал 21')
            ->call('loadMoreHistory')
            ->assertSee('Канал 21')
            ->assertSee('Контакт создан');
    }

    public function test_contact_history_tab_renders_empty_state_without_events(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        DB::table('contacts')
            ->where('id', $contact->id)
            ->update([
                'created_at' => null,
                'updated_at' => null,
                'data_collection_started_at' => null,
                'data_collection_completed_at' => null,
                'merged_at' => null,
            ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('История событий контакта')
            ->assertSee('По этому контакту пока нет событий для вкладки «История».')
            ->assertDontSee('Показать ещё');
    }

    public function test_admin_can_add_operator_comment_to_contact_history(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Герман Абрикосов',
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->set('historyCommentBody', 'Нужно вернуться к контакту завтра утром.')
            ->call('addHistoryComment')
            ->assertHasNoErrors()
            ->assertSee('Комментарий оператора')
            ->assertSee('Герман Абрикосов')
            ->assertSee('Нужно вернуться к контакту завтра утром.');

        $this->assertDatabaseHas('contact_timeline_events', [
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_OPERATOR_COMMENT,
            'actor_user_id' => $admin->id,
            'body' => 'Нужно вернуться к контакту завтра утром.',
        ]);
    }

    public function test_employee_cannot_add_operator_comment_to_contact_history(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
            'name' => 'Оператор истории',
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($employee)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertDontSee('Добавить комментарий')
            ->set('historyCommentBody', 'Операторский комментарий без прав администратора.')
            ->call('addHistoryComment')
            ->assertHasNoErrors()
            ->assertDontSee('Оператор истории')
            ->assertDontSee('Операторский комментарий без прав администратора.');

        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_contact_history_comment_requires_non_empty_body(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->set('historyCommentBody', '   ')
            ->call('addHistoryComment')
            ->assertHasErrors(['historyCommentBody']);

        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_contact_history_renders_comments_before_older_lifecycle_events(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Админ истории',
        ]);
        $contact = Contact::factory()->create();
        DB::table('contacts')
            ->where('id', $contact->id)
            ->update([
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDays(3),
            ]);

        ContactTimelineEvent::query()->create([
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_OPERATOR_COMMENT,
            'actor_user_id' => $admin->id,
            'body' => 'Связаться после проверки анкеты.',
            'occurred_at' => now()->subHour(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSeeInOrder([
                'Комментарий оператора',
                'Контакт создан',
            ])
            ->assertSee('Админ истории')
            ->assertSee('Связаться после проверки анкеты.');
    }

    public function test_contact_history_renders_first_name_changed_event_with_source_details(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        ContactTimelineEvent::query()->create([
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
            'payload' => [
                'previous_value' => null,
                'new_value' => 'Герман',
                'previous_source' => null,
                'new_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
                'reason' => 'scenario_confirmed',
            ],
            'occurred_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('Имя изменено')
            ->assertSee('«—» → «Герман»')
            ->assertSee('Источник: Клиент назвал');
    }

    public function test_contact_history_renders_merge_name_conflict_event_with_source_details(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        ContactTimelineEvent::query()->create([
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_MERGE_NAME_CONFLICT,
            'payload' => [
                'merged_contact_id' => 77,
                'merged_first_name' => 'Другое имя',
                'merged_first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
            ],
            'occurred_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('Конфликт имени при объединении')
            ->assertSee('При объединении с контактом #77 найдено другое имя: «Другое имя»')
            ->assertSee('Источник: Авто (из мессенджера)');
    }

    public function test_merged_contact_cannot_add_operator_comment_via_direct_action_call(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $merged->getRouteKey()])
            ->set('historyCommentBody', 'Не должен записаться из архивного дубля.')
            ->call('addHistoryComment')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('contact_timeline_events', [
            'contact_id' => $root->id,
            'body' => 'Не должен записаться из архивного дубля.',
        ]);

        $this->assertDatabaseMissing('contact_timeline_events', [
            'contact_id' => $merged->id,
            'body' => 'Не должен записаться из архивного дубля.',
        ]);
    }

    public function test_admin_can_update_contact_profile_from_contact_view_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Имя из мессенджера',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->call('openEditProfileDialog')
            ->assertSet('showEditProfileDialog', true)
            ->set('editingFirstName', 'Герман')
            ->set('editingLastName', 'Абрикосов')
            ->set('editingGender', 'male')
            ->set('editingAgeRange', '30_39')
            ->set('editingCountry', 'Россия')
            ->set('editingCity', 'Москва')
            ->call('saveMountedContactProfile')
            ->assertHasNoErrors()
            ->assertSee('Герман')
            ->assertSee('Абрикосов')
            ->assertSee('Россия')
            ->assertSee('Москва');
    }

    public function test_contacts_table_hides_merged_contacts_from_default_listing(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertCanSeeTableRecords([$root])
            ->assertCanNotSeeTableRecords([$merged]);
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
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-500',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        Message::query()->create([
            'dialog_id' => $dialog->id,
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

        ContactStartTag::query()->create([
            'contact_id' => $contact->id,
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => 'TEXT_2',
            'source' => ContactStartTag::SOURCE_MAX_START,
            'assigned_at' => now()->subMinute(),
        ]);

        ContactStartTag::query()->create([
            'contact_id' => $contact->id,
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => 'TEXT_1',
            'source' => ContactStartTag::SOURCE_TELEGRAM_START,
            'assigned_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Контакт')
            ->assertMountedActionModalSee('Теги')
            ->assertMountedActionModalSee('Работа с контактом')
            ->assertMountedActionModalSee('Анкета')
            ->assertMountedActionModalSee('Теги контакта')
            ->assertMountedActionModalSee('Телефоны')
            ->assertMountedActionModalSee('Диалоги')
            ->assertMountedActionModalSee('Подробности')
            ->assertMountedActionModalSee('Диагностика webhook')
            ->assertMountedActionModalSee('Свободен')
            ->assertMountedActionModalSee('Изменить')
            ->assertMountedActionModalSee('@max_customer')
            ->assertMountedActionModalSee('max-200')
            ->assertMountedActionModalSee('Параметры перехода')
            ->assertMountedActionModalSee('TEXT_1, TEXT_2')
            ->assertMountedActionModalSee('MAX Support')
            ->assertMountedActionModalSee('msg-700')
            ->assertMountedActionModalSee('max-debug')
            ->assertMountedActionModalSee('Нужна помощь по заказу')
            ->assertMountedActionModalDontSee('История сообщений')
            ->assertMountedActionModalDontSee('Последнее сообщение')
            ->assertMountedActionModalDontSee('Введите текст ответа')
            ->assertMountedActionModalDontSee('Назначение')
            ->assertMountedActionModalDontSee('Identities list')
            ->assertMountedActionModalDontSee('Recent messages');
    }

    public function test_admin_can_assign_contact_tag_from_contact_modal_and_close_dialog_state(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с тегами',
        ]);
        $assignableTag = Tag::factory()->create([
            'name' => 'VIP сегмент',
            'color' => Tag::COLOR_SUCCESS,
            'is_active' => true,
        ]);
        Tag::factory()->create([
            'name' => 'Скрытый тег',
            'color' => Tag::COLOR_GRAY,
            'is_active' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Теги контакта')
            ->call('openAddTagDialog')
            ->assertSet('showAddTagDialog', true)
            ->assertSee('VIP сегмент')
            ->assertDontSee('Скрытый тег')
            ->set('selectedTagId', (string) $assignableTag->id)
            ->call('saveMountedContactTag')
            ->assertHasNoErrors()
            ->assertNotified()
            ->assertSet('showAddTagDialog', false)
            ->assertMountedActionModalSee('VIP сегмент');

        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $assignableTag->id,
            'assigned_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_remove_contact_tag_from_contact_modal_after_remount(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с назначенным тегом',
        ]);
        $tag = Tag::factory()->create([
            'name' => 'Удаляемый тег',
            'color' => Tag::COLOR_WARNING,
            'is_active' => true,
        ]);

        $contact->tags()->attach($tag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Удаляемый тег')
            ->call('removeMountedContactTag', $tag->id)
            ->assertNotified()
            ->assertMountedActionModalSee('Для этого контакта теги ещё не назначены.')
            ->assertMountedActionModalDontSee('Удаляемый тег');

        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $tag->id,
        ]);
    }

    public function test_contacts_table_can_filter_by_tags_with_active_filter_state(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $vipTag = Tag::factory()->create([
            'name' => 'VIP',
            'color' => Tag::COLOR_SUCCESS,
        ]);
        $leadTag = Tag::factory()->create([
            'name' => 'Лид',
            'color' => Tag::COLOR_PRIMARY,
        ]);
        $vipContact = Contact::factory()->create([
            'name' => 'VIP контакт',
        ]);
        $leadContact = Contact::factory()->create([
            'name' => 'Лид контакт',
        ]);
        $cleanContact = Contact::factory()->create([
            'name' => 'Без тегов',
        ]);

        $vipContact->tags()->attach($vipTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $leadContact->tags()->attach($leadTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->filterTable('tags', [$vipTag->id])
            ->assertCanSeeTableRecords([$vipContact])
            ->assertCanNotSeeTableRecords([$leadContact, $cleanContact]);
    }

    public function test_employee_can_view_contact_details_with_profile_and_ownership_controls_only(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Контакт')
            ->assertMountedActionModalSee('Теги')
            ->assertSee('data-role="contact-open-assignee-dialog"', false)
            ->assertSee('data-role="contact-edit-profile"', false)
            ->assertDontSee('data-role="contact-edit-phone"', false)
            ->assertDontSee('data-role="contact-delete-phone"', false)
            ->assertDontSee('data-role="contact-open-tag-dialog"', false)
            ->assertDontSee('data-role="contact-remove-tag"', false)
            ->assertDontSee('data-role="contact-enable-auto-reply"', false)
            ->assertDontSee('data-role="contact-disable-auto-reply"', false)
            ->assertDontSee('data-role="contact-resume-data-collection"', false)
            ->assertDontSee('data-role="contact-open-delete-dialog"', false);
    }

    public function test_employee_can_see_edit_and_delete_phone_controls_for_saved_phone(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertSee('data-role="contact-edit-phone"', false)
            ->assertSee('data-role="contact-delete-phone"', false)
            ->assertDontSee('data-role="contact-enable-auto-reply"', false)
            ->assertDontSee('data-role="contact-disable-auto-reply"', false)
            ->assertDontSee('data-role="contact-resume-data-collection"', false)
            ->assertDontSee('data-role="contact-open-delete-dialog"', false);
    }

    public function test_employee_can_edit_contact_profile_from_contact_modal(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Имя из мессенджера',
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Профиль')
            ->assertSee('data-role="contact-edit-profile"', false)
            ->call('openEditProfileDialog')
            ->set('editingFirstName', 'Герман')
            ->set('editingLastName', 'Абрикосов')
            ->set('editingGender', 'male')
            ->set('editingBirthDate', now()->subYears(29)->toDateString())
            ->set('editingAgeYears', '35')
            ->set('editingAgeRange', '30_39')
            ->set('editingCountry', 'Россия')
            ->set('editingCity', 'Москва')
            ->set('editingRegion', 'Московская область')
            ->call('saveMountedContactProfile')
            ->assertHasNoErrors()
            ->assertMountedActionModalSee('Герман')
            ->assertMountedActionModalSee('Абрикосов')
            ->assertMountedActionModalSee('Мужской')
            ->assertMountedActionModalSee('30 - 39 лет')
            ->assertMountedActionModalSee('Россия')
            ->assertMountedActionModalSee('Москва')
            ->assertMountedActionModalSee('Московская область')
            ->assertMountedActionModalSee('Определён')
            ->assertMountedActionModalSee('Имя из мессенджера')
            ->assertDontSee('data-role="contact-edit-phone"', false)
            ->assertDontSee('data-role="contact-delete-phone"', false)
            ->assertDontSee('data-role="contact-enable-auto-reply"', false)
            ->assertDontSee('data-role="contact-disable-auto-reply"', false)
            ->assertDontSee('data-role="contact-resume-data-collection"', false)
            ->assertDontSee('data-role="contact-open-delete-dialog"', false);

        $contact->refresh();

        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame('Абрикосов', $contact->last_name);
        $this->assertSame('male', $contact->gender);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Москва', $contact->city);
        $this->assertSame('Московская область', $contact->region);
        $this->assertSame(Contact::REGION_STATUS_RESOLVED, $contact->region_status);
        $this->assertSame(Contact::REGION_SOURCE_MANUAL, $contact->region_source);
        $this->assertSame('30_39', $contact->age_range);
        $this->assertNotNull($contact->birth_date);
        $this->assertNull($contact->age_years);
        $this->assertSame('Герман Абрикосов', $contact->display_name);
    }

    public function test_employee_can_edit_root_profile_from_merged_contact_modal(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
            'first_name' => null,
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'first_name' => null,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $merged)
            ->call('openEditProfileDialog')
            ->set('editingFirstName', 'Герман')
            ->call('saveMountedContactProfile')
            ->assertHasNoErrors()
            ->assertSet('mountedActions.0.context.recordKey', (string) $root->id)
            ->assertMountedActionModalSee('Герман');

        $this->assertSame('Герман', $root->fresh()->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_MANUAL, $root->fresh()->first_name_source);
        $this->assertNull($merged->fresh()->first_name);
    }

    public function test_employee_profile_validation_matches_admin_rules(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Старое имя',
            'birth_date' => null,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openEditProfileDialog')
            ->set('editingGender', 'unknown')
            ->set('editingBirthDate', now()->addDay()->toDateString())
            ->set('editingRegion', 'Несуществующий регион')
            ->call('saveMountedContactProfile')
            ->assertHasErrors([
                'editingGender',
                'editingBirthDate',
                'editingRegion',
            ]);

        $contact->refresh();

        $this->assertSame('Старое имя', $contact->first_name);
        $this->assertNull($contact->birth_date);
    }

    public function test_employee_can_reassign_foreign_contact_via_responsible_dialog(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'name' => 'Оператор',
        ]);
        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Текущий ответственный',
        ]);
        $target = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'name' => 'Новый оператор',
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $owner->id,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openAssignContactDialog')
            ->assertMountedActionModalSee('Новый оператор')
            ->set('selectedAssigneeId', (string) $target->id)
            ->call('saveMountedContactAssignee')
            ->assertNotified()
            ->assertMountedActionModalSee('Новый оператор');

        $contact->refresh();

        $this->assertSame($target->id, $contact->assigned_user_id);
    }

    public function test_employee_can_clear_foreign_contact_responsible_via_dialog(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $owner->id,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openAssignContactDialog')
            ->set('selectedAssigneeId', '')
            ->call('saveMountedContactAssignee')
            ->assertNotified()
            ->assertMountedActionModalSee('Свободен');

        $contact->refresh();

        $this->assertNull($contact->assigned_user_id);
    }

    public function test_employee_cannot_delete_contact_via_livewire_action(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('deleteMountedContact')
            ->assertNotified();

        $this->assertModelExists($contact);
    }

    public function test_contacts_table_shows_pending_duplicate_review_badge_and_filter(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $pendingContact = Contact::factory()->create([
            'name' => 'Требует проверки',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $cleanContact = Contact::factory()->create([
            'name' => 'Чистый контакт',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_NONE,
        ]);

        ContactDuplicateReview::factory()->create([
            'contact_id' => $pendingContact->id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'candidate_root_contact_ids' => [777],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertSee('Нужна проверка')
            ->assertCanSeeTableRecords([$pendingContact, $cleanContact]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->filterTable('duplicate_review_pending')
            ->assertCanSeeTableRecords([$pendingContact])
            ->assertCanNotSeeTableRecords([$cleanContact]);
    }

    public function test_contact_modal_shows_duplicate_review_summary_for_root_contact(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с review',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);

        ContactDuplicateReview::factory()->create([
            'contact_id' => $contact->id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'candidate_root_contact_ids' => [12, 18],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Дедупликация')
            ->assertMountedActionModalSee('Нужна проверка')
            ->assertMountedActionModalSee('Открытые проверки: 1')
            ->assertMountedActionModalSee('Телефон найден у другого root-контакта')
            ->assertMountedActionModalSee('+79991234567')
            ->assertMountedActionModalSee('#12, #18');
    }

    public function test_contact_modal_shows_cross_channel_identity_review_summary_with_identity_and_channel_context(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с ambiguity review',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ContactDuplicateReview::factory()->create([
            'contact_id' => $contact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-600',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [24, 42],
            'context_payload' => ['last_seen_channel_id' => $channel->id],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Открытые проверки: 1')
            ->assertMountedActionModalSee('Один platform user ID привязан к нескольким root-контактам')
            ->assertMountedActionModalSee('telegram:cross-user-600')
            ->assertMountedActionModalSee('#24, #42')
            ->assertMountedActionModalSee('Telegram Account (Telegram)');
    }

    public function test_contact_modal_allows_resolving_cross_channel_identity_review_from_dedup_section(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $anchorContact = Contact::factory()->create([
            'name' => 'Anchor review contact',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $candidateRoot = Contact::factory()->create([
            'name' => 'Candidate review root',
        ]);
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-601',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$candidateRoot->id],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $anchorContact)
            ->assertMountedActionModalSee('Разобрать')
            ->assertMountedActionModalSee('Оставить отдельным root')
            ->call('openResolveCrossChannelIdentityReviewDialog', $review->id)
            ->assertSet('showResolveCrossChannelIdentityReviewDialog', true)
            ->assertMountedActionModalSee('Разобрать identity ambiguity')
            ->assertMountedActionModalSee('telegram:cross-user-601')
            ->set('selectedResolvedRoutedContactId', (string) $candidateRoot->id)
            ->call('saveResolvedCrossChannelIdentityReview');

        $review->refresh();
        $anchorContact->refresh();

        $this->assertSame(ContactDuplicateReview::STATUS_RESOLVED, $review->status);
        $this->assertSame($candidateRoot->id, $review->routed_contact_id);
        $this->assertSame($candidateRoot->id, $anchorContact->merged_into_contact_id);
    }

    public function test_contact_modal_allows_dismissing_cross_channel_identity_review_from_dedup_section(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $anchorContact = Contact::factory()->create([
            'name' => 'Dismiss anchor review contact',
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_PENDING,
        ]);
        $candidateRoot = Contact::factory()->create();
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $anchorContact->id,
            'phone_normalized' => null,
            'identity_key' => 'telegram:cross-user-602',
            'review_type' => ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            'candidate_root_contact_ids' => [$candidateRoot->id],
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $anchorContact)
            ->call('dismissMountedCrossChannelIdentityReview', $review->id);

        $review->refresh();
        $anchorContact->refresh();

        $this->assertSame(ContactDuplicateReview::STATUS_DISMISSED, $review->status);
        $this->assertSame($anchorContact->id, $review->routed_contact_id);
        $this->assertNull($anchorContact->merged_into_contact_id);
    }

    public function test_candidate_root_contact_modal_shows_external_cross_channel_identity_review_and_allows_resolution_entry_point(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $anchorContact = Contact::factory()->create([
            'name' => 'Anchor candidate owner',
        ]);
        $candidateRoot = Contact::factory()->create([
            'name' => 'Candidate visible root',
        ]);

        $review = app(CreateContactDuplicateReviewAction::class)->handle(
            contact: $anchorContact,
            phoneNormalized: null,
            reviewType: ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
            candidateRootContactIds: [$candidateRoot->id],
            identityKey: 'telegram:cross-user-603',
        );

        $this->assertSame(Contact::DUPLICATE_REVIEW_STATUS_PENDING, $candidateRoot->fresh()->duplicate_review_status);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $candidateRoot)
            ->assertMountedActionModalSee('Открытые проверки: 1')
            ->assertMountedActionModalSee('telegram:cross-user-603')
            ->assertMountedActionModalSee('Разобрать')
            ->call('openResolveCrossChannelIdentityReviewDialog', $review->id)
            ->assertSet('showResolveCrossChannelIdentityReviewDialog', true)
            ->assertMountedActionModalSee('Разобрать identity ambiguity')
            ->set('selectedResolvedRoutedContactId', (string) $candidateRoot->id)
            ->call('saveResolvedCrossChannelIdentityReview');

        $review->refresh();
        $anchorContact->refresh();

        $this->assertSame(ContactDuplicateReview::STATUS_RESOLVED, $review->status);
        $this->assertSame($candidateRoot->id, $review->routed_contact_id);
        $this->assertSame($candidateRoot->id, $anchorContact->merged_into_contact_id);
    }

    public function test_contact_infolist_uses_compact_section_order_and_collapsed_technical_sections(): void
    {
        $schema = ContactResource::infolist(new Schema(null));

        /** @var array<int, Section> $sections */
        $sections = $schema->getComponents();

        $this->assertSame([
            'Контакт',
            'Профиль',
            'Теги',
            'Диалоги',
            'Анкета',
            'Работа с контактом',
            'Телефоны',
            'Подробности',
            'Диагностика webhook',
        ], array_map(
            fn (Section $section): string => (string) $section->getHeading(),
            $sections,
        ));

        $sectionsByHeading = collect($sections)
            ->mapWithKeys(fn (Section $section): array => [(string) $section->getHeading() => $section]);

        $this->assertFalse($sectionsByHeading['Контакт']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Профиль']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Теги']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Анкета']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Работа с контактом']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Телефоны']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Диалоги']->isCollapsible());

        $this->assertTrue($sectionsByHeading['Подробности']->isCollapsible());
        $this->assertTrue($sectionsByHeading['Подробности']->isCollapsed());

        $this->assertTrue($sectionsByHeading['Диагностика webhook']->isCollapsible());
        $this->assertTrue($sectionsByHeading['Диагностика webhook']->isCollapsed());
    }

    public function test_contact_modal_no_longer_shows_history_and_inline_reply_sections(): void
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
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Анкета')
            ->assertMountedActionModalSee('Диагностика webhook')
            ->assertMountedActionModalDontSee('История сообщений')
            ->assertMountedActionModalDontSee('Последнее сообщение')
            ->assertMountedActionModalDontSee('Введите текст ответа')
            ->assertMountedActionModalDontSee('Отправить');
    }

    public function test_admin_can_assign_contact_tag_from_contact_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с тегами',
        ]);
        $assignableTag = Tag::factory()->create([
            'name' => 'VIP сегмент',
            'color' => Tag::COLOR_SUCCESS,
            'is_active' => true,
        ]);
        Tag::factory()->create([
            'name' => 'Скрытый тег',
            'color' => Tag::COLOR_GRAY,
            'is_active' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Теги')
            ->call('openAddTagDialog')
            ->assertSet('showAddTagDialog', true)
            ->assertSee('VIP сегмент')
            ->assertDontSee('Скрытый тег')
            ->set('selectedTagId', (string) $assignableTag->id)
            ->call('saveMountedContactTag')
            ->assertHasNoErrors()
            ->assertNotified()
            ->assertSet('showAddTagDialog', false)
            ->assertMountedActionModalSee('VIP сегмент');

        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $assignableTag->id,
            'assigned_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_remove_contact_tag_from_contact_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с назначенным тегом',
        ]);
        $tag = Tag::factory()->create([
            'name' => 'Удаляемый тег',
            'color' => Tag::COLOR_WARNING,
            'is_active' => true,
        ]);

        $contact->tags()->attach($tag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Удаляемый тег')
            ->call('removeMountedContactTag', $tag->id)
            ->assertNotified()
            ->assertMountedActionModalSee('Для этого контакта теги ещё не назначены.')
            ->assertMountedActionModalDontSee('Удаляемый тег');

        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $tag->id,
        ]);
    }

    public function test_contacts_table_can_filter_by_tags(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $vipTag = Tag::factory()->create([
            'name' => 'VIP',
            'color' => Tag::COLOR_SUCCESS,
        ]);
        $leadTag = Tag::factory()->create([
            'name' => 'Лид',
            'color' => Tag::COLOR_PRIMARY,
        ]);
        $vipContact = Contact::factory()->create([
            'name' => 'VIP контакт',
        ]);
        $leadContact = Contact::factory()->create([
            'name' => 'Лид контакт',
        ]);
        $cleanContact = Contact::factory()->create([
            'name' => 'Без тегов',
        ]);

        $vipContact->tags()->attach($vipTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $leadContact->tags()->attach($leadTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->filterTable('tags', [$vipTag->id])
            ->assertCanSeeTableRecords([$vipContact])
            ->assertCanNotSeeTableRecords([$leadContact, $cleanContact]);
    }

    public function test_contact_modal_shows_inactive_collector_status(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'data_collection_status' => null,
            'data_collection_current_field' => null,
            'data_collection_attempts_count' => 0,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Анкета')
            ->assertMountedActionModalSee('Не запущена')
            ->assertMountedActionModalSee('Текущий шаг')
            ->assertMountedActionModalSee('Попыток')
            ->assertMountedActionModalSee('Имя')
            ->assertMountedActionModalSee('Страна')
            ->assertMountedActionModalSee('Город')
            ->assertMountedActionModalSee('Возраст');
    }

    public function test_contact_modal_shows_active_collector_state_and_attempts(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => null,
            'age_range' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'data_collection_attempts_count' => 1,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('В процессе')
            ->assertMountedActionModalSee('Город')
            ->assertMountedActionModalSee('1')
            ->assertMountedActionModalSee('Герман')
            ->assertMountedActionModalSee('Россия');
    }

    public function test_contact_modal_shows_completed_collector_status_without_current_step(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '30_39',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
            'data_collection_attempts_count' => 0,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Завершена')
            ->assertMountedActionModalSee('Москва')
            ->assertMountedActionModalSee('Россия')
            ->assertMountedActionModalSee('Герман')
            ->assertMountedActionModalSee('30 - 39 лет');
    }

    public function test_contact_modal_shows_resume_button_for_incomplete_profile_with_phone(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => null,
            'city' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Возобновить анкету');
    }

    public function test_contact_modal_hides_resume_button_for_full_profile(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '30_39',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalDontSee('Возобновить анкету');
    }

    public function test_contact_modal_hides_resume_button_for_active_collector(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => null,
            'age_range' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalDontSee('Возобновить анкету');
    }

    public function test_contact_modal_hides_resume_button_without_phone(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => null,
            'city' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalDontSee('Возобновить анкету');
    }

    public function test_admin_can_resume_contact_data_collection_from_first_missing_field(): void
    {
        Queue::fake();

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
            'data_collection_attempts_count' => 1,
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-1001',
        ]);
        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'resume-message-1001',
            'external_chat_id' => 'chat-1001',
            'external_message_id' => 'msg-1001',
            'text' => 'Продолжим',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('resumeMountedContactDataCollection')
            ->assertMountedActionModalSee('В процессе')
            ->assertMountedActionModalSee('Город');

        $contact->refresh();

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);

        Queue::assertPushed(ProcessDataCollectionQuestionJob::class, function (ProcessDataCollectionQuestionJob $job) use ($inboundMessage): bool {
            return $job->sourceMessageId === $inboundMessage->id
                && $job->forceSend === true;
        });
    }

    public function test_admin_can_resume_data_collection_from_merged_contact_and_modal_switches_to_root(): void
    {
        Queue::fake();

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $root->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $root->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-root-resume',
        ]);
        $inboundMessage = Message::query()->create([
            'contact_id' => $root->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'resume-root-message',
            'external_chat_id' => 'chat-root-resume',
            'external_message_id' => 'msg-root-resume',
            'text' => 'Продолжим',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $merged)
            ->call('resumeMountedContactDataCollection')
            ->assertSet('mountedActions.0.context.recordKey', (string) $root->id)
            ->assertMountedActionModalSee('Герман')
            ->assertMountedActionModalSee('В процессе')
            ->assertMountedActionModalSee('Город');

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $root->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $root->fresh()->data_collection_current_field);
        $this->assertNull($merged->fresh()->data_collection_current_field);

        Queue::assertPushed(ProcessDataCollectionQuestionJob::class, function (ProcessDataCollectionQuestionJob $job) use ($inboundMessage): bool {
            return $job->sourceMessageId === $inboundMessage->id
                && $job->forceSend === true;
        });
    }

    public function test_contact_display_name_prefers_operator_profile_names(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Имя из мессенджера',
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
        ]);

        $this->assertSame('Герман Абрикосов', $contact->display_name);
    }

    public function test_contact_effective_age_years_prefers_birth_date_over_manual_age(): void
    {
        $birthDate = now()->subYears(25)->subDay()->toDateString();
        $contact = Contact::factory()->create([
            'birth_date' => $birthDate,
            'age_years' => 40,
        ]);

        $this->assertSame(25, $contact->effective_age_years);
    }

    public function test_contact_effective_age_years_uses_manual_age_when_birth_date_is_missing(): void
    {
        $contact = Contact::factory()->create([
            'birth_date' => null,
            'age_years' => 34,
        ]);

        $this->assertSame(34, $contact->effective_age_years);
    }

    public function test_admin_can_edit_contact_profile_from_contact_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Имя из мессенджера',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Профиль')
            ->assertMountedActionModalSee('Имя из мессенджера')
            ->call('openEditProfileDialog')
            ->set('editingFirstName', 'Герман')
            ->set('editingLastName', 'Абрикосов')
            ->set('editingGender', 'male')
            ->set('editingBirthDate', now()->subYears(29)->toDateString())
            ->set('editingAgeYears', '35')
            ->set('editingAgeRange', '30_39')
            ->set('editingCountry', 'Россия')
            ->set('editingCity', 'Москва')
            ->set('editingRegion', 'Московская область')
            ->call('saveMountedContactProfile')
            ->assertHasNoErrors()
            ->assertMountedActionModalSee('Герман')
            ->assertMountedActionModalSee('Абрикосов')
            ->assertMountedActionModalSee('Мужской')
            ->assertMountedActionModalSee('30 - 39 лет')
            ->assertMountedActionModalSee('Россия')
            ->assertMountedActionModalSee('Москва')
            ->assertMountedActionModalSee('Московская область')
            ->assertMountedActionModalSee('Определён')
            ->assertMountedActionModalSee('Имя из мессенджера');

        $contact->refresh();

        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_MANUAL, $contact->first_name_source);
        $this->assertSame('Абрикосов', $contact->last_name);
        $this->assertSame('male', $contact->gender);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Москва', $contact->city);
        $this->assertSame('Московская область', $contact->region);
        $this->assertSame(Contact::REGION_STATUS_RESOLVED, $contact->region_status);
        $this->assertSame(Contact::REGION_SOURCE_MANUAL, $contact->region_source);
        $this->assertSame('30_39', $contact->age_range);
        $this->assertNotNull($contact->birth_date);
        $this->assertNull($contact->age_years);
        $this->assertSame('Герман Абрикосов', $contact->display_name);
    }

    public function test_admin_can_edit_root_profile_from_merged_contact_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
            'first_name' => null,
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'first_name' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $merged)
            ->call('openEditProfileDialog')
            ->set('editingFirstName', 'Герман')
            ->call('saveMountedContactProfile')
            ->assertHasNoErrors()
            ->assertSet('mountedActions.0.context.recordKey', (string) $root->id)
            ->assertMountedActionModalSee('Герман');

        $this->assertSame('Герман', $root->fresh()->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_MANUAL, $root->fresh()->first_name_source);
        $this->assertNull($merged->fresh()->first_name);
    }

    public function test_contact_modal_displays_distance_to_moscow_fields(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Москва',
            'distance_to_moscow_km' => 0,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Расстояние до Москвы')
            ->assertMountedActionModalSee('0 км')
            ->assertMountedActionModalSee('Рассчитано');
    }

    public function test_admin_can_toggle_contact_auto_reply_from_contact_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'is_auto_reply_enabled' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Автоответы')
            ->assertMountedActionModalSee('Включены')
            ->call('disableMountedContactAutoReply')
            ->assertMountedActionModalSee('Отключены')
            ->call('enableMountedContactAutoReply')
            ->assertMountedActionModalSee('Включены');

        $contact->refresh();

        $this->assertTrue($contact->is_auto_reply_enabled);
    }

    public function test_admin_can_delete_contact_from_contact_modal(): void
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
            'external_user_id' => 'telegram-delete-1',
        ]);
        $dialog = Dialog::factory()->create([
            'current_contact_identity_id' => $identity->id,
        ]);
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        $message = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-delete-1',
            'external_message_id' => 'msg-delete-1',
            'text' => 'Удалить меня',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Удалить клиента')
            ->call('openDeleteContactDialog')
            ->assertMountedActionModalSee('Контакт')
            ->assertMountedActionModalSee('будет удалён вместе с диалогами, сообщениями, телефонами и идентичностями.')
            ->assertMountedActionModalSee('Контактов')
            ->assertMountedActionModalSee('Диалогов')
            ->assertMountedActionModalSee('Сообщений')
            ->assertMountedActionModalSee('Телефонов')
            ->assertMountedActionModalSee('Идентификаторов')
            ->call('deleteMountedContact')
            ->assertTableActionNotMounted('view')
            ->assertCanNotSeeTableRecords([$contact]);

        $this->assertDatabaseMissing('contacts', [
            'id' => $contact->id,
        ]);
        $this->assertDatabaseMissing('dialogs', [
            'id' => $dialog->id,
        ]);
        $this->assertDatabaseMissing('contact_identities', [
            'id' => $identity->id,
        ]);
        $this->assertDatabaseMissing('contact_phone_numbers', [
            'id' => $phoneNumber->id,
        ]);
        $this->assertDatabaseMissing('messages', [
            'id' => $message->id,
        ]);
    }

    public function test_contact_modal_shows_aggregate_delete_copy_for_root_with_merged_children(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $rootIdentity = ContactIdentity::factory()->create([
            'contact_id' => $root->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'delete-root-preview',
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'merge_reason' => 'phone_exact_match',
            'merge_trigger_phone' => '+79991234567',
        ]);
        $mergedIdentity = ContactIdentity::factory()->create([
            'contact_id' => $merged->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'delete-merged-preview',
        ]);
        Dialog::factory()->create([
            'current_contact_identity_id' => $rootIdentity->id,
        ]);
        Dialog::factory()->create([
            'current_contact_identity_id' => $mergedIdentity->id,
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $root->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $merged->id,
            'phone_raw' => '+7 999 555 55 55',
            'phone_normalized' => '+79995555555',
            'is_primary' => true,
        ]);
        Message::factory()->create([
            'contact_id' => $root->id,
            'contact_identity_id' => $rootIdentity->id,
            'channel_id' => $channel->id,
        ]);
        Message::factory()->create([
            'contact_id' => $merged->id,
            'contact_identity_id' => $mergedIdentity->id,
            'channel_id' => $channel->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $root)
            ->assertMountedActionModalSee('Дедупликация')
            ->assertMountedActionModalSee('Склеено дублей')
            ->assertMountedActionModalSee('Последние склейки')
            ->assertMountedActionModalSee('Совпадение телефона')
            ->assertMountedActionModalSee('+79991234567')
            ->assertMountedActionModalSee('Удалить клиента')
            ->call('openDeleteContactDialog')
            ->assertMountedActionModalSee('Удалить клиента целиком?')
            ->assertMountedActionModalSee('Будет удалён весь клиент')
            ->assertMountedActionModalSee('склеенные дубли')
            ->assertMountedActionModalSee('Контактов')
            ->assertMountedActionModalSee('Диалогов')
            ->assertMountedActionModalSee('Сообщений')
            ->assertMountedActionModalSee('Телефонов')
            ->assertMountedActionModalSee('Идентификаторов');
    }

    public function test_contact_modal_delete_preview_from_merged_secondary_uses_root_aggregate(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Главный клиент',
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $merged)
            ->call('openDeleteContactDialog')
            ->assertMountedActionModalSee('Удалить клиента целиком?')
            ->assertMountedActionModalSee('Главный клиент')
            ->assertMountedActionModalSee('Контактов');
    }

    public function test_contact_modal_displays_saved_phone_numbers(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Телефоны')
            ->assertMountedActionModalSee('+7 999 123 45 67')
            ->assertMountedActionModalSee('Основной')
            ->assertMountedActionModalSee('Изменить')
            ->assertMountedActionModalSee('Удалить');
    }

    public function test_admin_can_delete_contact_from_table_actions_column(): void
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
            'external_user_id' => 'telegram-delete-table-1',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 111 22 33',
            'phone_normalized' => '+79991112233',
            'is_primary' => true,
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'chat-delete-table-1',
            'external_message_id' => 'msg-delete-table-1',
            'text' => 'Удалить запись',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableActionExists('delete', null, $contact)
            ->callTableAction('delete', $contact)
            ->assertHasNoTableActionErrors()
            ->assertCanNotSeeTableRecords([$contact]);

        $this->assertModelMissing($contact);
        $this->assertDatabaseMissing('contact_identities', [
            'id' => $identity->id,
        ]);
    }

    public function test_admin_can_delete_root_aggregate_from_table_actions_column(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'name' => 'Контакт с историей склейки',
        ]);
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableActionExists('delete', null, $root)
            ->callTableAction('delete', $root)
            ->assertHasNoTableActionErrors()
            ->assertCanNotSeeTableRecords([$root]);

        $this->assertModelMissing($root);
        $this->assertModelMissing($merged);
    }

    public function test_admin_can_edit_saved_phone_number_from_contact_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openEditPhoneDialog', $phoneNumber->id)
            ->set('editingPhoneRaw', '+7 999 555 55 55')
            ->call('saveMountedContactPhone')
            ->assertHasNoErrors()
            ->assertMountedActionModalSee('+7 999 555 55 55');

        $phoneNumber->refresh();

        $this->assertSame('+7 999 555 55 55', $phoneNumber->phone_raw);
        $this->assertSame('+79995555555', $phoneNumber->phone_normalized);
    }

    public function test_employee_can_edit_saved_phone_number_from_contact_modal(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create();
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertSee('data-role="contact-edit-phone"', false)
            ->assertSee('data-role="contact-delete-phone"', false)
            ->call('openEditPhoneDialog', $phoneNumber->id)
            ->set('editingPhoneRaw', '+7 999 555 55 55')
            ->call('saveMountedContactPhone')
            ->assertHasNoErrors()
            ->assertMountedActionModalSee('+7 999 555 55 55')
            ->assertSee('data-role="contact-delete-phone"', false);

        $phoneNumber->refresh();

        $this->assertSame('+7 999 555 55 55', $phoneNumber->phone_raw);
        $this->assertSame('+79995555555', $phoneNumber->phone_normalized);
    }

    public function test_admin_cannot_edit_phone_number_to_duplicate_value_for_same_contact(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $editablePhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 555 55 55',
            'phone_normalized' => '+79995555555',
            'is_primary' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openEditPhoneDialog', $editablePhone->id)
            ->set('editingPhoneRaw', '+7 999 555 55 55')
            ->call('saveMountedContactPhone')
            ->assertHasErrors(['editingPhoneRaw']);

        $editablePhone->refresh();

        $this->assertSame('+7 999 123 45 67', $editablePhone->phone_raw);
        $this->assertSame('+79991234567', $editablePhone->phone_normalized);
    }

    public function test_employee_cannot_edit_phone_number_to_duplicate_value_for_same_contact(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create();
        $editablePhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 555 55 55',
            'phone_normalized' => '+79995555555',
            'is_primary' => false,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openEditPhoneDialog', $editablePhone->id)
            ->set('editingPhoneRaw', '+7 999 555 55 55')
            ->call('saveMountedContactPhone')
            ->assertHasErrors(['editingPhoneRaw']);

        $editablePhone->refresh();

        $this->assertSame('+7 999 123 45 67', $editablePhone->phone_raw);
        $this->assertSame('+79991234567', $editablePhone->phone_normalized);
    }

    public function test_admin_can_delete_primary_phone_number_from_contact_modal_and_promote_next_phone(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $primaryPhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        $nextPhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 555 55 55',
            'phone_normalized' => '+79995555555',
            'is_primary' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openDeletePhoneDialog', $primaryPhone->id)
            ->call('deleteMountedContactPhone')
            ->assertMountedActionModalSee('+7 999 555 55 55')
            ->assertMountedActionModalDontSee('+7 999 123 45 67');

        $this->assertDatabaseMissing('contact_phone_numbers', [
            'id' => $primaryPhone->id,
        ]);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'id' => $nextPhone->id,
            'is_primary' => true,
        ]);
    }

    public function test_employee_can_delete_primary_phone_number_from_contact_modal_and_promote_next_phone(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create();
        $primaryPhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);
        $nextPhone = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 555 55 55',
            'phone_normalized' => '+79995555555',
            'is_primary' => false,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertSee('data-role="contact-delete-phone"', false)
            ->call('openDeletePhoneDialog', $primaryPhone->id)
            ->call('deleteMountedContactPhone')
            ->assertMountedActionModalSee('+7 999 555 55 55')
            ->assertMountedActionModalDontSee('+7 999 123 45 67');

        $this->assertDatabaseMissing('contact_phone_numbers', [
            'id' => $primaryPhone->id,
        ]);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'id' => $nextPhone->id,
            'is_primary' => true,
        ]);
    }

    public function test_employee_can_delete_last_phone_number_from_contact_modal(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create();
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($employee)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openDeletePhoneDialog', $phoneNumber->id)
            ->call('deleteMountedContactPhone')
            ->assertMountedActionModalSee('Номера телефонов для этого контакта ещё не сохранены.');

        $this->assertDatabaseMissing('contact_phone_numbers', [
            'id' => $phoneNumber->id,
        ]);
    }

    public function test_contacts_table_displays_primary_phone_and_phone_count(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contactWithPrimary = Contact::factory()->create([
            'name' => 'Контакт с primary',
        ]);
        $contactWithoutPrimary = Contact::factory()->create([
            'name' => 'Контакт без primary',
        ]);
        $contactWithoutPhone = Contact::factory()->create([
            'name' => 'Контакт без телефона',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contactWithPrimary->id,
            'phone_raw' => '+7 900 111 11 11',
            'phone_normalized' => '+79001111111',
            'is_primary' => false,
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contactWithPrimary->id,
            'phone_raw' => '+7 900 222 22 22',
            'phone_normalized' => '+79002222222',
            'is_primary' => true,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contactWithoutPrimary->id,
            'phone_raw' => '+7 900 333 33 33',
            'phone_normalized' => '+79003333333',
            'is_primary' => false,
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contactWithoutPrimary->id,
            'phone_raw' => '+7 900 444 44 44',
            'phone_normalized' => '+79004444444',
            'is_primary' => false,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableColumnVisible('primary_phone_raw')
            ->assertTableColumnExists('phone_count')
            ->assertCanSeeTableRecords([$contactWithPrimary, $contactWithoutPrimary, $contactWithoutPhone]);

        $component
            ->assertTableColumnStateSet('primary_phone_raw', '+7 900 222 22 22', $contactWithPrimary)
            ->assertTableColumnStateSet('phone_count', 2, $contactWithPrimary)
            ->assertTableColumnStateSet('primary_phone_raw', '+7 900 333 33 33', $contactWithoutPrimary)
            ->assertTableColumnStateSet('phone_count', 2, $contactWithoutPrimary)
            ->assertTableColumnStateSet('primary_phone_raw', null, $contactWithoutPhone)
            ->assertTableColumnStateSet('phone_count', 0, $contactWithoutPhone);
    }

    public function test_contacts_table_phone_column_is_copyable_when_phone_exists(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт с номером',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ManageContacts::class);

        /** @var \Filament\Tables\Columns\TextColumn $column */
        $column = $component->instance()->getTable()->getColumn('primary_phone_raw');
        $record = $component->instance()->getTableRecord((string) $contact->getKey());

        $column->record($record);
        $column->clearCachedState();

        $this->assertTrue($column->isCopyable($column->getState()));
    }

    public function test_contacts_table_can_filter_contacts_by_phone_presence(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contactWithPhone = Contact::factory()->create([
            'name' => 'Контакт с телефоном',
        ]);
        $contactWithoutPhone = Contact::factory()->create([
            'name' => 'Контакт без телефона',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contactWithPhone->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableFilterExists('has_phone')
            ->assertTableFilterExists('without_phone')
            ->filterTable('has_phone')
            ->assertCanSeeTableRecords([$contactWithPhone])
            ->assertCanNotSeeTableRecords([$contactWithoutPhone]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->filterTable('without_phone')
            ->assertCanSeeTableRecords([$contactWithoutPhone])
            ->assertCanNotSeeTableRecords([$contactWithPhone]);
    }

    public function test_contacts_table_search_finds_contacts_by_phone(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Контакт по телефону',
        ]);
        $otherContact = Contact::factory()->create([
            'name' => 'Другой контакт',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 926 352 71 11',
            'phone_normalized' => '+79263527111',
            'is_primary' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->searchTable('+7 (926) 352-71-11')
            ->assertCanSeeTableRecords([$contact])
            ->assertCanNotSeeTableRecords([$otherContact]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->searchTable('3527111')
            ->assertCanSeeTableRecords([$contact])
            ->assertCanNotSeeTableRecords([$otherContact]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->searchTable('Контакт по телефону')
            ->assertCanSeeTableRecords([$contact])
            ->assertCanNotSeeTableRecords([$otherContact]);
    }

    public function test_contact_start_parameters_format_returns_null_without_start_tags(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Без параметров перехода',
        ]);

        $formatter = new ReflectionMethod(ContactResource::class, 'formatContactStartParameters');
        $formatter->setAccessible(true);

        $this->assertNull($formatter->invoke(null, $contact));
    }

    public function test_contact_diagnostics_show_latest_message_even_with_same_received_at_second(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
            'assigned_user_id' => $admin->id,
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

    public function test_contact_dialogs_renderer_shows_dialog_cards_sorted_by_latest_activity(): void
    {
        $contact = Contact::factory()->create();
        $telegramChannel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Sales',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannel->id,
            'platform' => $telegramChannel->platform,
            'external_user_id' => 'telegram-dialog-1',
            'display_name' => 'Telegram Клиент',
            'external_username' => 'telegram_customer',
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'platform' => $maxChannel->platform,
            'display_name' => 'MAX Клиент',
            'external_user_id' => '',
        ]);

        $telegramDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannel->id,
            'current_contact_identity_id' => $telegramIdentity->id,
            'external_chat_id' => 'tg-chat-1',
            'confirmed_phone_raw' => '+7 999 111-11-11',
            'last_message_at' => now(),
            'last_inbound_at' => now()->subMinute(),
            'last_outbound_at' => now()->subSeconds(10),
        ]);
        $maxDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'current_contact_identity_id' => $maxIdentity->id,
            'external_chat_id' => null,
            'confirmed_phone_raw' => null,
            'confirmed_phone_normalized' => null,
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHours(2),
            'last_outbound_at' => null,
        ]);

        Message::query()->create([
            'dialog_id' => $telegramDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegramChannel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'external_chat_id' => 'tg-chat-1',
            'text' => 'Свежий ответ оператором в Telegram',
            'raw_payload' => ['provider' => 'manual'],
            'received_at' => now(),
        ]);
        Message::query()->create([
            'dialog_id' => $maxDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $maxChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '',
            'text' => null,
            'raw_payload' => ['provider' => 'max'],
            'received_at' => now()->subHour(),
        ]);

        $dialogsBuilder = new ReflectionMethod(ContactResource::class, 'buildDialogsViewData');
        $dialogsBuilder->setAccessible(true);

        $dialogsHtml = view('filament.contacts.partials.contact-dialogs', $dialogsBuilder->invoke(null, $contact))->render();

        $this->assertStringContainsString('data-role="contact-dialogs"', $dialogsHtml);
        $this->assertStringContainsString('data-role="contact-dialog"', $dialogsHtml);
        $this->assertStringContainsString('data-role="dialog-messenger-name"', $dialogsHtml);
        $this->assertStringContainsString('Telegram Support', $dialogsHtml);
        $this->assertStringContainsString('MAX Sales', $dialogsHtml);
        $this->assertStringContainsString('Telegram Клиент', $dialogsHtml);
        $this->assertStringContainsString('MAX Клиент', $dialogsHtml);
        $this->assertStringContainsString('Маршрут готов', $dialogsHtml);
        $this->assertStringContainsString('Нет route source', $dialogsHtml);
        $this->assertStringContainsString('+7 999 111-11-11', $dialogsHtml);
        $this->assertStringContainsString('Телефон в этом канале не подтвержден', $dialogsHtml);
        $this->assertStringContainsString('ID: telegram-dialog-1', $dialogsHtml);
        $this->assertStringContainsString('Свежий ответ оператором в Telegram', $dialogsHtml);
        $this->assertStringContainsString('Поделился номером телефона', $dialogsHtml);
        $this->assertStringContainsString('data-role="dialog-preview"', $dialogsHtml);
        $this->assertStringContainsString('data-role="dialog-preview-sender"', $dialogsHtml);
        $this->assertStringContainsString('Оператор', $dialogsHtml);
        $this->assertStringContainsString('Контакт', $dialogsHtml);
        $this->assertStringContainsString(DialogResource::getUrl('view', ['record' => $telegramDialog]), $dialogsHtml);
        $this->assertStringContainsString('data-role="dialog-card-link"', $dialogsHtml);
        $this->assertLessThan(
            strpos($dialogsHtml, 'MAX Sales'),
            strpos($dialogsHtml, 'Telegram Support'),
        );
    }

    public function test_contact_dialogs_renderer_shows_max_bot_started_without_payload_as_human_readable_preview(): void
    {
        $contact = Contact::factory()->create();
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Sales',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'platform' => $maxChannel->platform,
            'external_user_id' => 'max-dialog-1',
            'external_username' => null,
        ]);
        $maxDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'current_contact_identity_id' => $maxIdentity->id,
            'external_chat_id' => 'max-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'last_outbound_at' => null,
        ]);

        Message::query()->create([
            'dialog_id' => $maxDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $maxChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-chat-1',
            'text' => null,
            'raw_payload' => [
                'update_type' => 'bot_started',
                'payload' => '',
            ],
            'received_at' => now(),
        ]);

        $dialogsBuilder = new ReflectionMethod(ContactResource::class, 'buildDialogsViewData');
        $dialogsBuilder->setAccessible(true);

        $dialogsHtml = view('filament.contacts.partials.contact-dialogs', $dialogsBuilder->invoke(null, $contact))->render();

        $this->assertStringContainsString('Открыл бота по диплинку', $dialogsHtml);
        $this->assertStringNotContainsString('Системное сообщение', $dialogsHtml);
    }

    public function test_contact_dialogs_renderer_shows_telegram_start_payload_as_human_readable_preview(): void
    {
        $contact = Contact::factory()->create();
        $telegramChannel = Channel::factory()->create([
            'name' => 'Продакшен',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannel->id,
            'platform' => $telegramChannel->platform,
            'external_user_id' => 'telegram-dialog-start',
            'external_username' => 'telegram_customer',
        ]);
        $telegramDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannel->id,
            'current_contact_identity_id' => $telegramIdentity->id,
            'external_chat_id' => 'tg-chat-start',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'last_outbound_at' => null,
        ]);

        Message::query()->create([
            'dialog_id' => $telegramDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegramChannel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'tg-chat-start',
            'text' => '/start TEXT_1',
            'raw_payload' => ['message' => ['text' => '/start TEXT_1']],
            'received_at' => now(),
        ]);

        $dialogsBuilder = new ReflectionMethod(ContactResource::class, 'buildDialogsViewData');
        $dialogsBuilder->setAccessible(true);

        $dialogsHtml = view('filament.contacts.partials.contact-dialogs', $dialogsBuilder->invoke(null, $contact))->render();

        $this->assertStringContainsString('Открыл бота по диплинку: TEXT_1', $dialogsHtml);
        $this->assertStringNotContainsString('/start TEXT_1', $dialogsHtml);
    }

    public function test_contact_dialogs_renderer_shows_empty_state_when_contact_has_no_dialogs(): void
    {
        $contact = Contact::factory()->create();

        $dialogsBuilder = new ReflectionMethod(ContactResource::class, 'buildDialogsViewData');
        $dialogsBuilder->setAccessible(true);

        $dialogsHtml = view('filament.contacts.partials.contact-dialogs', $dialogsBuilder->invoke(null, $contact))->render();

        $this->assertStringContainsString('data-role="contact-dialogs"', $dialogsHtml);
        $this->assertStringContainsString('data-role="contact-dialogs-empty"', $dialogsHtml);
        $this->assertStringContainsString('Диалоги ещё не появились.', $dialogsHtml);
    }

    public function test_contact_dialogs_renderer_shows_missing_token_route_status(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'name' => 'Telegram No Token',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tokenless-contact',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tokenless-chat',
        ]);

        Message::query()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'tokenless-chat',
            'text' => 'Диалог без токена',
            'raw_payload' => ['provider' => 'telegram'],
            'received_at' => now(),
        ]);

        $dialogsBuilder = new ReflectionMethod(ContactResource::class, 'buildDialogsViewData');
        $dialogsBuilder->setAccessible(true);

        $dialogsHtml = view('filament.contacts.partials.contact-dialogs', $dialogsBuilder->invoke(null, $contact))->render();

        $this->assertStringContainsString('Telegram No Token', $dialogsHtml);
        $this->assertStringContainsString('Нет токена', $dialogsHtml);
    }

    public function test_contact_dialogs_overview_uses_batched_preview_query_without_contact_history_query(): void
    {
        $contact = Contact::factory()->create();

        foreach (range(1, 3) as $index) {
            $channel = Channel::factory()->create([
                'name' => 'Telegram Support '.$index,
                'platform' => Channel::PLATFORM_TELEGRAM,
            ]);
            $identity = ContactIdentity::factory()->create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'platform' => $channel->platform,
                'external_user_id' => 'telegram-overview-'.$index,
            ]);

            $dialog = Dialog::factory()->create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'current_contact_identity_id' => $identity->id,
                'external_chat_id' => 'chat-overview-'.$index,
                'last_message_at' => now()->subMinutes($index),
            ]);

            Message::query()->create([
                'dialog_id' => $dialog->id,
                'contact_id' => $contact->id,
                'contact_identity_id' => $identity->id,
                'channel_id' => $channel->id,
                'direction' => Message::DIRECTION_INBOUND,
                'message_kind' => Message::KIND_INBOUND_USER,
                'external_chat_id' => 'chat-overview-'.$index,
                'text' => 'Preview '.$index,
                'raw_payload' => ['provider' => 'telegram'],
                'received_at' => now()->subMinutes($index),
            ]);
        }

        $dialogsBuilder = new ReflectionMethod(ContactResource::class, 'buildDialogsViewData');
        $dialogsBuilder->setAccessible(true);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $dialogsHtml = view('filament.contacts.partials.contact-dialogs', $dialogsBuilder->invoke(null, $contact))->render();

        $messageQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, 'from "messages"'));

        $this->assertStringContainsString('Preview 1', $dialogsHtml);
        $this->assertCount(1, $messageQueries->filter(
            fn (string $query): bool => str_contains($query, 'distinct on (dialog_id)')
        ));
        $this->assertFalse($messageQueries->contains(
            fn (string $query): bool => str_contains($query, '"messages"."contact_id"') && str_contains($query, 'coalesce(received_at, created_at) desc')
        ));
        $this->assertFalse($messageQueries->contains(
            fn (string $query): bool => str_contains($query, '"messages"."dialog_id" =')
        ));
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

    public function test_contacts_table_sorts_by_message_chronology_desc(): void
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
            ->assertCanSeeTableRecords([$olderContact, $newerContact], inOrder: true);
    }

    public function test_contact_modal_can_assign_current_employee_via_responsible_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Администратор 1',
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Изменить')
            ->call('openAssignContactDialog')
            ->assertMountedActionModalSee('Выберите сотрудника, который будет вести этот контакт.')
            ->set('selectedAssigneeId', (string) $admin->id)
            ->call('saveMountedContactAssignee')
            ->assertNotified()
            ->assertMountedActionModalSee('Администратор 1');

        $contact->refresh();

        $this->assertSame($admin->id, $contact->assigned_user_id);
    }

    public function test_contact_modal_can_assign_other_employee_via_responsible_dialog(): void
    {
        $firstAdmin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Первый администратор',
        ]);
        $secondAdmin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Второй администратор',
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($firstAdmin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openAssignContactDialog')
            ->set('selectedAssigneeId', (string) $secondAdmin->id)
            ->call('saveMountedContactAssignee')
            ->assertNotified()
            ->assertMountedActionModalSee('Второй администратор');

        $contact->refresh();

        $this->assertSame($secondAdmin->id, $contact->assigned_user_id);
    }

    public function test_contact_modal_can_clear_responsible_via_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openAssignContactDialog')
            ->set('selectedAssigneeId', '')
            ->call('saveMountedContactAssignee')
            ->assertNotified()
            ->assertMountedActionModalSee('Свободен');

        $contact->refresh();

        $this->assertNull($contact->assigned_user_id);
    }

    public function test_contact_modal_does_not_accept_inactive_non_admin_as_responsible(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $invalidAssignee = User::factory()->create([
            'is_active' => false,
            'is_admin' => false,
        ]);
        $contact = Contact::factory()->create();

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->call('openAssignContactDialog')
            ->set('selectedAssigneeId', (string) $invalidAssignee->id)
            ->call('saveMountedContactAssignee')
            ->assertNotified()
            ->assertMountedActionModalSee('Свободен');

        $contact->refresh();

        $this->assertNull($contact->assigned_user_id);
    }

    public function test_contacts_table_filters_support_my_and_unassigned_contacts(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $otherAdmin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $myContact = Contact::factory()->create([
            'name' => 'Мой контакт',
            'assigned_user_id' => $admin->id,
        ]);
        $otherContact = Contact::factory()->create([
            'name' => 'Чужой контакт',
            'assigned_user_id' => $otherAdmin->id,
        ]);
        $freeContact = Contact::factory()->create([
            'name' => 'Свободный контакт',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->filterTable('assigned_to_me')
            ->assertCanSeeTableRecords([$myContact])
            ->assertCanNotSeeTableRecords([$otherContact, $freeContact]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->filterTable('unassigned_contacts')
            ->assertCanSeeTableRecords([$freeContact])
            ->assertCanNotSeeTableRecords([$myContact, $otherContact]);
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

    public function test_contact_modal_prefers_message_chronology_over_saved_id_order(): void
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
            ->assertMountedActionModalDontSee('mid.0000000003e3748c019d30476b8e52e7')
            ->assertMountedActionModalSee('old-payload')
            ->assertMountedActionModalDontSee('new-payload');
    }

    public function test_contacts_table_uses_message_chronology_for_last_message_column(): void
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

        $fallbackCreatedAt = now()->startOfMinute();
        $olderReceivedAt = now()->subDay()->startOfMinute();

        Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => null,
            'text' => 'проверка',
            'raw_payload' => ['message' => 'old-payload'],
            'received_at' => null,
            'created_at' => $fallbackCreatedAt,
        ]);

        Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-latest',
            'text' => 'тест7',
            'raw_payload' => ['message' => 'latest-payload'],
            'received_at' => $olderReceivedAt,
            'created_at' => now()->subDays(2),
        ]);

        $this->actingAs($admin)
            ->get('/admin/contacts')
            ->assertOk()
            ->assertSee($fallbackCreatedAt->format('d.m.Y H:i'))
            ->assertDontSee($olderReceivedAt->format('d.m.Y H:i'));
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
                $this->assertTrue($table->getColumn('assignedUser.name')?->isToggleable());
                $this->assertTrue($table->getColumn('primaryIdentity.external_username')?->isToggleable());
            });
    }

    public function test_contact_policy_for_active_admin_uses_role_permission_matrix(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Contact::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $contact));
        $this->assertFalse(Gate::forUser($admin)->allows('create', Contact::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $contact));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $contact));
        $this->assertFalse(Gate::forUser($admin)->allows('deleteAny', Contact::class));
    }

    public function test_contact_permissions_and_helpers_respect_disabled_employee_matrix_values(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $contact = Contact::factory()->create();

        \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereIn('permission_key', ['contacts.view', 'contacts.edit', 'contacts.delete'])
            ->update(['granted' => false]);

        $employee = User::query()->findOrFail($employee->id);

        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($employee)->allows('viewAny', Contact::class));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($employee)->allows('view', $contact));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($employee)->allows('update', $contact));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($employee)->allows('delete', $contact));
        $this->assertFalse($employee->canManageContactWorkspaceMutations());
        $this->assertFalse($employee->canManageContactProfile());
        $this->assertFalse($employee->canManageContactOwnership());
        $this->assertFalse($employee->canEditExistingContactPhones());
        $this->assertFalse($employee->canDeleteExistingContactPhones());
        $this->assertFalse($employee->canDeleteContacts());
    }
}
