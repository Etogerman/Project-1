<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactTimelineEvent;
use App\Models\Dialog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContactNameSliceThreeUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_contact_view_page_hides_legacy_messenger_name_row_and_shows_source_badge(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Legacy имя',
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'last_name' => 'Абрикосов',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Абрикосов Герман')
            ->assertSee('Клиент назвал')
            ->assertDontSee('Имя (мессенджер)');
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
            'external_user_id' => 'slice-3-header-user',
            'display_name' => 'Telegram Клиент',
        ]);

        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'slice-3-header-chat',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertSee('Telegram Клиент')
            ->assertDontSee('Legacy имя')
            ->assertDontSee('Имя (мессенджер)');
    }

    public function test_contacts_table_shows_first_name_source_indicator(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Legacy имя',
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'last_name' => 'Абрикосов',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageContacts::class)
            ->assertCanSeeTableRecords([$contact])
            ->assertSee('Герман Абрикосов')
            ->assertSee('Клиент назвал');
    }

    public function test_contact_history_renders_name_change_and_merge_conflict_events(): void
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

        ContactTimelineEvent::query()->create([
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_MERGE_NAME_CONFLICT,
            'payload' => [
                'merged_contact_id' => 77,
                'merged_first_name' => 'Другое имя',
                'merged_first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
            ],
            'occurred_at' => now()->subMinute(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('Имя изменено')
            ->assertSee('«—» → «Герман»')
            ->assertSee('Источник: Клиент назвал')
            ->assertSee('Конфликт имени при объединении')
            ->assertSee('При объединении с контактом #77 найдено другое имя: «Другое имя»')
            ->assertSee('Источник: Авто (из мессенджера)');
    }
}
