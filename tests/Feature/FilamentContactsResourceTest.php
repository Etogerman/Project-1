<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('Кнопки')
            ->assertSee('Просмотр');

        $this->assertSame('Контакты', ContactResource::getNavigationLabel());

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableColumnVisible('id')
            ->assertTableColumnVisible('inbox_status')
            ->assertCanSeeTableRecords([$contact])
            ->assertTableFilterExists('requires_manual_reply')
            ->assertTableFilterExists('assigned_to_me')
            ->assertTableFilterExists('unassigned_contacts')
            ->assertTableActionExists('view', null, $contact)
            ->assertTableActionExists('delete', null, $contact)
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
            ->assertMountedActionModalSee('Контакт')
            ->assertMountedActionModalSee('Работа с контактом')
            ->assertMountedActionModalSee('Анкета')
            ->assertMountedActionModalSee('Телефоны')
            ->assertMountedActionModalSee('История сообщений')
            ->assertMountedActionModalSee('Подробности')
            ->assertMountedActionModalSee('Последнее сообщение')
            ->assertMountedActionModalSee('Диагностика webhook')
            ->assertMountedActionModalSee('Свободен')
            ->assertMountedActionModalSee('Изменить')
            ->assertMountedActionModalSee('@max_customer')
            ->assertMountedActionModalSee('max-200')
            ->assertMountedActionModalSee('MAX Support')
            ->assertMountedActionModalSee('msg-700')
            ->assertMountedActionModalSee('max-debug')
            ->assertMountedActionModalDontSee('Назначение')
            ->assertMountedActionModalDontSee('Identities list')
            ->assertMountedActionModalDontSee('Recent messages')
            ->assertMountedActionModalSee('Нужна помощь по заказу');
    }

    public function test_contact_infolist_uses_compact_section_order_and_collapsed_technical_sections(): void
    {
        $schema = ContactResource::infolist(new Schema(null));

        /** @var array<int, Section> $sections */
        $sections = $schema->getComponents();

        $this->assertSame([
            'Контакт',
            'Профиль',
            'Анкета',
            'Работа с контактом',
            'Телефоны',
            'История сообщений',
            'Подробности',
            'Последнее сообщение',
            'Диагностика webhook',
        ], array_map(
            fn (Section $section): string => (string) $section->getHeading(),
            $sections,
        ));

        $sectionsByHeading = collect($sections)
            ->mapWithKeys(fn (Section $section): array => [(string) $section->getHeading() => $section]);

        $this->assertFalse($sectionsByHeading['Контакт']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Профиль']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Анкета']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Работа с контактом']->isCollapsible());
        $this->assertFalse($sectionsByHeading['Телефоны']->isCollapsible());
        $this->assertFalse($sectionsByHeading['История сообщений']->isCollapsible());

        $this->assertTrue($sectionsByHeading['Подробности']->isCollapsible());
        $this->assertTrue($sectionsByHeading['Подробности']->isCollapsed());

        $this->assertTrue($sectionsByHeading['Последнее сообщение']->isCollapsible());
        $this->assertTrue($sectionsByHeading['Последнее сообщение']->isCollapsed());

        $this->assertTrue($sectionsByHeading['Диагностика webhook']->isCollapsible());
        $this->assertTrue($sectionsByHeading['Диагностика webhook']->isCollapsed());
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
            ->assertMountedActionModalSee('Анкета')
            ->assertMountedActionModalSee('История сообщений')
            ->assertMountedActionModalSee('Диагностика webhook')
            ->assertMountedActionModalSee('Ответ')
            ->assertMountedActionModalSee('Отправить');
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
            ->set('editingBirthDate', now()->subYears(29)->toDateString())
            ->set('editingAgeYears', '35')
            ->set('editingAgeRange', '30_39')
            ->set('editingCountry', 'Россия')
            ->set('editingCity', 'Москва')
            ->call('saveMountedContactProfile')
            ->assertHasNoErrors()
            ->assertMountedActionModalSee('Герман')
            ->assertMountedActionModalSee('Абрикосов')
            ->assertMountedActionModalSee('30 - 39 лет')
            ->assertMountedActionModalSee('Россия')
            ->assertMountedActionModalSee('Москва')
            ->assertMountedActionModalSee('Имя из мессенджера');

        $contact->refresh();

        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame('Абрикосов', $contact->last_name);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Москва', $contact->city);
        $this->assertSame('30_39', $contact->age_range);
        $this->assertNotNull($contact->birth_date);
        $this->assertNull($contact->age_years);
        $this->assertSame('Герман Абрикосов', $contact->display_name);
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
            ->assertMountedActionModalSee('будет удалён вместе с телефонами, сообщениями и идентичностями.')
            ->call('deleteMountedContact')
            ->assertTableActionNotMounted('view')
            ->assertCanNotSeeTableRecords([$contact]);

        $this->assertDatabaseMissing('contacts', [
            'id' => $contact->id,
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

    public function test_contact_history_renderer_renders_messages_as_chat_bubbles_without_technical_noise(): void
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
        $this->assertStringContainsString('data-role="conversation-date-separator"', $historyHtml);
        $this->assertStringContainsString('data-role="conversation-message"', $historyHtml);
        $this->assertStringContainsString('data-direction="inbound"', $historyHtml);
        $this->assertStringContainsString('data-direction="outbound"', $historyHtml);
        $this->assertStringContainsString('data-kind="inbound_user"', $historyHtml);
        $this->assertStringContainsString('data-kind="outbound_auto_reply"', $historyHtml);
        $this->assertStringContainsString('Входящее сообщение от пользователя', $historyHtml);
        $this->assertStringContainsString('Исходящий автоответ', $historyHtml);
        $this->assertStringContainsString(now()->format('H:i d.m.Y'), $historyHtml);
        $this->assertStringContainsString('Сегодня', $historyHtml);
        $this->assertStringContainsString('justify-content: flex-start', $historyHtml);
        $this->assertStringContainsString('justify-content: flex-end', $historyHtml);
        $this->assertStringNotContainsString('Event key: telegram-update-950', $historyHtml);
        $this->assertStringNotContainsString('Статус: Ответ отправлен', $historyHtml);
        $this->assertStringNotContainsString('Ответ на event key: telegram-update-950', $historyHtml);
        $this->assertStringNotContainsString('Telegram Support (Telegram)', $historyHtml);
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
        $this->assertStringContainsString('data-kind="unknown"', $historyHtml);
    }

    public function test_contact_history_renderer_uses_display_text_for_contact_share_messages_without_text(): void
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
            'external_user_id' => 'telegram-contact-share',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => 'chat-contact-share',
            'external_message_id' => 'contact-share-1',
            'text' => null,
            'raw_payload' => ['provider' => 'telegram-contact-share'],
            'received_at' => now(),
        ]);

        $historyRenderer = new ReflectionMethod(ContactResource::class, 'renderConversationHistory');
        $historyRenderer->setAccessible(true);

        $historyHtml = $historyRenderer->invoke(null, $contact)->toHtml();

        $this->assertStringContainsString('Поделился номером телефона', $historyHtml);
        $this->assertStringContainsString('data-kind="inbound_contact_share"', $historyHtml);
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
            'assigned_user_id' => $admin->id,
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
        $contact->update(['assigned_user_id' => $admin->id]);
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
        $contact = Contact::factory()->create([
            'assigned_user_id' => $admin->id,
        ]);
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
        $contact = Contact::factory()->create([
            'assigned_user_id' => $admin->id,
        ]);
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

    public function test_inline_reply_composer_auto_claims_free_contact_before_sending_reply(): void
    {
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 99011,
                ],
            ]),
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
            'external_user_id' => 'telegram-unassigned',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-unassigned',
            'external_chat_id' => 'chat-unassigned',
            'external_message_id' => 'msg-unassigned',
            'text' => 'Входящее сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Ответственный пока не выбран.')
            ->set('inlineReplyText', 'Ответ с авто-claim')
            ->call('sendInlineReply')
            ->assertNotified()
            ->assertSet('inlineReplyText', '');

        $contact->refresh();

        $this->assertSame($admin->id, $contact->assigned_user_id);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'text' => 'Ответ с авто-claim',
        ]);
    }

    public function test_inline_reply_composer_blocks_manual_reply_for_contact_owned_by_another_user(): void
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
        $contact = Contact::factory()->create([
            'assigned_user_id' => $owner->id,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-owned-by-other',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-owned-by-other',
            'external_chat_id' => 'chat-owned-by-other',
            'external_message_id' => 'msg-owned-by-other',
            'text' => 'Входящее сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Контакт уже назначен сотруднику Другой сотрудник.')
            ->set('inlineReplyText', 'Ответ чужому контакту')
            ->call('sendInlineReply')
            ->assertNotified()
            ->assertSet('inlineReplyText', 'Ответ чужому контакту');

        $this->assertDatabaseCount('messages', 1);
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
                $this->assertTrue($table->getColumn('assignedUser.name')?->isToggleable());
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
