<?php

namespace Tests\Feature;

use App\Filament\Pages\AnalyticsOverview;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAnalyticsOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        Carbon::setTestNow(Carbon::parse('2026-06-04 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_open_analytics_overview_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(AnalyticsOverview::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertSee('data-role="analytics-overview"', false)
            ->assertSee('За период')
            ->assertSee('Сейчас')
            ->assertSee('Текущие этапы диалогов');
    }

    public function test_employee_without_analytics_permission_cannot_open_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_EMPLOYEE,
            'is_admin' => false,
        ]);

        $this->actingAs($employee);

        $this->assertFalse(AnalyticsOverview::shouldRegisterNavigation());

        $this->get(AnalyticsOverview::getUrl(panel: 'admin'))
            ->assertForbidden();
    }

    public function test_overview_counts_period_events_and_snapshot_metrics(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
        ]);
        $assignee = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $telegram = Channel::factory()->create([
            'name' => 'Telegram Local',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $max = Channel::factory()->create([
            'name' => 'MAX Local',
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $newUnassignedContact = Contact::factory()->create([
            'first_name' => 'Новый',
            'last_name' => 'Клиент',
            'created_at' => Carbon::parse('2026-06-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-01 10:00:00'),
            'assigned_user_id' => null,
        ]);
        $completedContact = Contact::factory()->create([
            'first_name' => 'Анкета',
            'last_name' => 'Готова',
            'created_at' => Carbon::parse('2026-06-02 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-02 10:00:00'),
            'assigned_user_id' => $assignee->id,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => Carbon::parse('2026-06-03 09:00:00'),
        ]);
        $oldContact = Contact::factory()->create([
            'first_name' => 'Старый',
            'last_name' => 'Клиент',
            'created_at' => Carbon::parse('2026-05-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-05-01 10:00:00'),
            'assigned_user_id' => $assignee->id,
        ]);
        $mergedContact = Contact::factory()->create([
            'first_name' => 'Слитый',
            'last_name' => 'Клиент',
            'created_at' => Carbon::parse('2026-06-02 11:00:00'),
            'updated_at' => Carbon::parse('2026-06-02 11:00:00'),
            'merged_into_contact_id' => $newUnassignedContact->id,
            'merged_at' => Carbon::parse('2026-06-02 12:00:00'),
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => Carbon::parse('2026-06-03 10:00:00'),
        ]);

        $overdueDialog = $this->createDialog($newUnassignedContact, $telegram, [
            'created_at' => Carbon::parse('2026-06-02 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-04 10:00:00'),
            'phone_confirmed_at' => Carbon::parse('2026-06-03 08:00:00'),
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => Carbon::parse('2026-06-03 11:00:00'),
            'last_message_at' => Carbon::parse('2026-06-04 10:00:00'),
            'last_inbound_at' => Carbon::parse('2026-06-04 10:00:00'),
        ]);
        $recentDialog = $this->createDialog($completedContact, $max, [
            'created_at' => Carbon::parse('2026-06-03 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-04 11:30:00'),
            'last_message_at' => Carbon::parse('2026-06-04 11:30:00'),
            'last_inbound_at' => Carbon::parse('2026-06-04 11:30:00'),
        ]);
        $manualStageDialog = $this->createDialog($oldContact, $telegram, [
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
            'created_at' => Carbon::parse('2026-05-20 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-04 09:00:00'),
            'last_message_at' => Carbon::parse('2026-06-04 09:00:00'),
            'last_inbound_at' => Carbon::parse('2026-06-04 08:00:00'),
            'last_outbound_at' => Carbon::parse('2026-06-04 09:00:00'),
        ]);
        $mergedDialog = $this->createDialog($mergedContact, $telegram, [
            'created_at' => Carbon::parse('2026-06-02 12:00:00'),
            'updated_at' => Carbon::parse('2026-06-04 10:00:00'),
            'phone_confirmed_at' => Carbon::parse('2026-06-03 08:00:00'),
            'last_message_at' => Carbon::parse('2026-06-04 10:00:00'),
            'last_inbound_at' => Carbon::parse('2026-06-04 10:00:00'),
        ]);

        $this->createMessage($overdueDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => 'Нужен ответ',
            'received_at' => Carbon::parse('2026-06-04 10:00:00'),
        ]);
        $this->createMessage($recentDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => 'Свежий вопрос',
            'received_at' => Carbon::parse('2026-06-04 11:30:00'),
        ]);
        $this->createMessage($manualStageDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => 'Уже ответили',
            'received_at' => Carbon::parse('2026-06-04 08:00:00'),
        ]);
        $this->createMessage($manualStageDialog, [
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_user_id' => $assignee->id,
            'text' => 'Ответ оператора',
            'received_at' => Carbon::parse('2026-06-04 09:00:00'),
        ]);
        $this->createMessage($overdueDialog, [
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'direction' => Message::DIRECTION_INBOUND,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => 'Клиент заблокировал бота',
            'received_at' => Carbon::parse('2026-06-03 11:00:00'),
        ]);
        $this->createMessage($mergedDialog, [
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'direction' => Message::DIRECTION_INBOUND,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => 'Слитый клиент заблокировал бота',
            'received_at' => Carbon::parse('2026-06-03 11:00:00'),
        ]);

        $vipTag = Tag::factory()->create([
            'name' => 'VIP',
            'slug' => 'vip',
            'color' => Tag::COLOR_SUCCESS,
        ]);
        $newUnassignedContact->tags()->attach($vipTag->id, [
            'assigned_at' => Carbon::parse('2026-06-02 10:00:00'),
        ]);
        $mergedContact->tags()->attach($vipTag->id, [
            'assigned_at' => Carbon::parse('2026-06-02 10:00:00'),
        ]);

        Livewire::actingAs($admin)
            ->test(AnalyticsOverview::class)
            ->assertSee('data-metric="new_clients" data-value="2"', false)
            ->assertSee('data-metric="new_dialogs" data-value="2"', false)
            ->assertSee('data-metric="bot_blocks" data-value="1"', false)
            ->assertSee('data-metric="phones_received" data-value="1"', false)
            ->assertSee('data-metric="data_collected" data-value="1"', false)
            ->assertSee('data-metric="requires_reply" data-value="2"', false)
            ->assertSee('data-metric="requires_reply_overdue" data-value="1"', false)
            ->assertSee('data-metric="unassigned" data-value="1"', false)
            ->assertSee('data-metric="blocked_now" data-value="1"', false)
            ->assertSee('data-stage="'.Dialog::STAGE_PHONE_RECEIVED.'" data-count="1"', false)
            ->assertSee('data-stage="'.Dialog::STAGE_QUESTIONNAIRE_COMPLETED.'" data-count="1"', false)
            ->assertSee('data-stage="'.Dialog::STAGE_TRANSFERRED_TO_MPL.'" data-count="1"', false)
            ->assertSee('data-tag-id="'.$vipTag->id.'" data-count="1"', false)
            ->assertSee('data-channel-id="'.$telegram->id.'"', false)
            ->assertSee('data-channel-id="'.$max->id.'"', false)
            ->assertSee('data-dialog-id="'.$overdueDialog->id.'"', false)
            ->assertSee('Требует ответа больше 1 часа')
            ->assertSee('Без ответственного')
            ->assertSee('Бот заблокирован')
            ->assertDontSee('Слитый Клиент');
    }

    public function test_period_filter_changes_event_metrics_but_not_snapshot_metrics(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create([
            'created_at' => Carbon::parse('2026-06-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-01 10:00:00'),
        ]);
        $dialog = $this->createDialog($contact, $channel, [
            'created_at' => Carbon::parse('2026-06-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-04 10:00:00'),
            'last_message_at' => Carbon::parse('2026-06-04 10:00:00'),
            'last_inbound_at' => Carbon::parse('2026-06-04 10:00:00'),
        ]);

        $this->createMessage($dialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'direction' => Message::DIRECTION_INBOUND,
            'text' => 'Нужен ответ',
            'received_at' => Carbon::parse('2026-06-04 10:00:00'),
        ]);

        Livewire::actingAs($admin)
            ->test(AnalyticsOverview::class)
            ->assertSee('data-metric="new_clients" data-value="1"', false)
            ->assertSee('data-metric="requires_reply" data-value="1"', false)
            ->call('selectPeriod', 'today')
            ->assertSee('data-metric="new_clients" data-value="0"', false)
            ->assertSee('data-metric="requires_reply" data-value="1"', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createDialog(Contact $contact, Channel $channel, array $attributes = []): Dialog
    {
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'display_name' => trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: null,
        ]);

        return Dialog::factory()->create([
            ...$attributes,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => (string) fake()->unique()->numerify('########'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMessage(Dialog $dialog, array $attributes = []): Message
    {
        return Message::query()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => $attributes['direction'] ?? Message::DIRECTION_INBOUND,
            'message_kind' => $attributes['message_kind'] ?? Message::KIND_INBOUND_USER,
            'system_event_code' => $attributes['system_event_code'] ?? null,
            'sent_by_type' => $attributes['sent_by_type'] ?? null,
            'sent_by_user_id' => $attributes['sent_by_user_id'] ?? null,
            'sent_by_system_code' => $attributes['sent_by_system_code'] ?? null,
            'external_chat_id' => (string) $dialog->external_chat_id,
            'external_message_id' => $attributes['external_message_id'] ?? fake()->unique()->uuid(),
            'text' => $attributes['text'] ?? null,
            'raw_payload' => $attributes['raw_payload'] ?? [],
            'received_at' => $attributes['received_at'] ?? now(),
        ]);
    }
}
