<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactTimelineEvent;
use App\Models\Dialog;
use App\Models\Scenario;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Contacts\MergeContactsAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContactHistoryIbizaScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_contact_history_renders_completed_vip_ibiza_run_summary_and_places_it_above_name_change_with_same_timestamp(): void
    {
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
            'external_user_id' => 'ibiza-history-1',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'ibiza-history-chat-1',
        ]);

        $this->createPublishedDatabaseScenario('vip_ibiza', 'VIP Ibiza');

        $timestamp = now();

        ScenarioRun::query()->create([
            'scenario_code' => 'vip_ibiza',
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_COMPLETED,
            'current_step' => null,
            'state_payload' => [
                'run' => [
                    'first_name' => 'Юля',
                    'dates_response' => 'Да, готова',
                    'primary_goal' => 'Выйти на мужчину более высокого уровня',
                    'commitment' => 'Готова включаться по полной',
                    'budget_tier' => '15,000 USD и выше',
                    'call_readiness' => 'Да',
                    'instagram_handle' => 'https://instagram.com/yulia',
                ],
            ],
            'exit_outcome' => 'completed',
            'started_at' => $timestamp->copy()->subMinutes(5),
            'finished_at' => $timestamp,
        ]);

        ContactTimelineEvent::query()->create([
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
            'payload' => [
                'previous_value' => null,
                'new_value' => 'Юля',
                'previous_source' => null,
                'new_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ],
            'occurred_at' => $timestamp,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSeeInOrder([
                'Пройден сценарий VIP Ibiza',
                'Имя изменено',
            ])
            ->assertSee('Сценарий завершён в канале «MAX Support (MAX)».')
            ->assertSee('Имя: Юля')
            ->assertSee('Готовность по датам: Да, готова')
            ->assertSee('Цель: Выйти на мужчину более высокого уровня')
            ->assertSee('Формат включения: Готова включаться по полной')
            ->assertSee('Бюджет: 15,000 USD и выше')
            ->assertSee('Готовность к созвону: Да')
            ->assertSee('Instagram: https://instagram.com/yulia');
    }

    public function test_contact_history_excludes_non_completed_or_non_ibiza_runs_and_supports_legacy_summary_keys(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ibiza-history-2',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'ibiza-history-chat-2',
        ]);

        $this->createPublishedDatabaseScenario('vip_ibiza', 'VIP Ibiza');
        $this->createPublishedDatabaseScenario('warmup_followup', 'Warmup followup');

        ScenarioRun::query()->create([
            'scenario_code' => 'vip_ibiza',
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_COMPLETED,
            'current_step' => null,
            'state_payload' => [
                'run' => [
                    'departure_city' => 'Казань',
                ],
            ],
            'exit_outcome' => 'completed',
            'started_at' => now()->subMinutes(15),
            'finished_at' => now()->subMinutes(10),
        ]);

        ScenarioRun::query()->create([
            'scenario_code' => 'vip_ibiza',
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_FAILED,
            'current_step' => null,
            'state_payload' => [
                'run' => [
                    'primary_goal' => 'FAILED SHOULD STAY HIDDEN',
                ],
            ],
            'exit_outcome' => 'inbound_failed',
            'started_at' => now()->subMinutes(9),
            'finished_at' => now()->subMinutes(8),
        ]);

        ScenarioRun::query()->create([
            'scenario_code' => 'warmup_followup',
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_COMPLETED,
            'current_step' => null,
            'state_payload' => [
                'run' => [
                    'primary_goal' => 'OTHER SCENARIO SHOULD STAY HIDDEN',
                ],
            ],
            'exit_outcome' => 'completed',
            'started_at' => now()->subMinutes(7),
            'finished_at' => now()->subMinutes(6),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('Пройден сценарий VIP Ibiza')
            ->assertSee('Город вылета: Казань')
            ->assertDontSee('FAILED SHOULD STAY HIDDEN')
            ->assertDontSee('OTHER SCENARIO SHOULD STAY HIDDEN');
    }

    public function test_contact_history_keeps_completed_vip_ibiza_run_visible_after_same_channel_merge(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $primary = Contact::factory()->create();
        $secondary = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $primaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ibiza-history-merge-root',
        ]);
        $secondaryIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ibiza-history-merge-secondary',
        ]);
        $primaryDialog = Dialog::factory()->create([
            'contact_id' => $primary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $primaryIdentity->id,
            'external_chat_id' => 'ibiza-history-root-chat',
        ]);
        $secondaryDialog = Dialog::factory()->create([
            'contact_id' => $secondary->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $secondaryIdentity->id,
            'external_chat_id' => 'ibiza-history-secondary-chat',
        ]);

        $this->createPublishedDatabaseScenario('vip_ibiza', 'VIP Ibiza');

        $run = ScenarioRun::query()->create([
            'scenario_code' => 'vip_ibiza',
            'dialog_id' => $secondaryDialog->id,
            'status' => ScenarioRun::STATUS_COMPLETED,
            'current_step' => null,
            'state_payload' => [
                'run' => [
                    'primary_goal' => 'Прийти к браку',
                ],
            ],
            'exit_outcome' => 'completed',
            'started_at' => now()->subMinutes(12),
            'finished_at' => now()->subMinutes(10),
        ]);

        app(MergeContactsAction::class)->handle($primary, $secondary);

        $this->assertDatabaseHas('scenario_runs', [
            'id' => $run->id,
            'dialog_id' => $primaryDialog->id,
            'status' => ScenarioRun::STATUS_COMPLETED,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContact::class, ['record' => $primary->getRouteKey()])
            ->set('activeTab', ViewContact::TAB_HISTORY)
            ->assertSee('Пройден сценарий VIP Ibiza')
            ->assertSee('Цель: Прийти к браку')
            ->assertDontSee((string) $secondaryDialog->id);
    }

    private function createPublishedDatabaseScenario(string $code, string $name): Scenario
    {
        $scenario = Scenario::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => [],
        ]);

        return $scenario->fresh('publishedVersion');
    }
}
