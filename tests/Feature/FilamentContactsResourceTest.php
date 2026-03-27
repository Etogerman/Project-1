<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
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
            ->assertCanSeeTableRecords([$contact])
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
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->mountTableAction('view', $contact)
            ->assertMountedActionModalSee('Идентификаторы')
            ->assertMountedActionModalSee('Последние сообщения')
            ->assertMountedActionModalSee('@max_customer')
            ->assertMountedActionModalSee('max-200')
            ->assertMountedActionModalSee('MAX Support')
            ->assertMountedActionModalSee('Нужна помощь по заказу');
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
