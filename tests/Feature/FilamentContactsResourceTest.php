<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
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
            ->assertSee('Кнопки')
            ->assertSee('Просмотр');

        $this->assertSame('Контакты', ContactResource::getNavigationLabel());

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertTableColumnVisible('id')
            ->assertTableColumnVisible('inbox_status')
            ->assertTableColumnVisible('dedup_status')
            ->assertCanSeeTableRecords([$contact])
            ->assertTableFilterExists('requires_manual_reply')
            ->assertTableFilterExists('assigned_to_me')
            ->assertTableFilterExists('unassigned_contacts')
            ->assertTableFilterExists('duplicate_review_pending')
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

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Контакт')
            ->assertMountedActionModalSee('Работа с контактом')
            ->assertMountedActionModalSee('Анкета')
            ->assertMountedActionModalSee('Телефоны')
            ->assertMountedActionModalSee('Диалоги')
            ->assertMountedActionModalSee('Подробности')
            ->assertMountedActionModalSee('Диагностика webhook')
            ->assertMountedActionModalSee('Свободен')
            ->assertMountedActionModalSee('Изменить')
            ->assertMountedActionModalSee('@max_customer')
            ->assertMountedActionModalSee('max-200')
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
            'Диалоги',
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
            ->assertTableActionExists('view', null, $contact)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Анкета')
            ->assertMountedActionModalSee('Диагностика webhook')
            ->assertMountedActionModalDontSee('История сообщений')
            ->assertMountedActionModalDontSee('Последнее сообщение')
            ->assertMountedActionModalDontSee('Введите текст ответа')
            ->assertMountedActionModalDontSee('Отправить');
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
            'external_username' => 'telegram_customer',
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'platform' => $maxChannel->platform,
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
        $this->assertStringContainsString('Telegram Support', $dialogsHtml);
        $this->assertStringContainsString('MAX Sales', $dialogsHtml);
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
