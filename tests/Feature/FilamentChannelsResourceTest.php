<?php

namespace Tests\Feature;

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Channels\Pages\ManageChannels;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\ChannelRuntimeState;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Scenarios\CreateScenarioAction;
use App\Services\Scenarios\PublishScenarioVersionAction;
use App\Services\Scenarios\WarmupScenario;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FilamentChannelsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_channels_resource_lives_in_settings_navigation_group(): void
    {
        $this->assertSame('Настройки', ChannelResource::getNavigationGroup());
        $this->assertSame(14, ChannelResource::getNavigationSort());
    }

    public function test_active_admin_can_open_channels_page_and_see_resource(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Sales Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $this->actingAs($admin)
            ->get('/admin/channels')
            ->assertOk()
            ->assertSee('Каналы связи');

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertCanSeeTableRecords([$channel]);
    }

    public function test_active_non_admin_user_gets_forbidden_on_channels_page(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin/channels')
            ->assertForbidden();
    }

    public function test_employee_channel_access_is_controlled_by_role_permission_matrix(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $channel = Channel::factory()->create();

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', false);

        $this->actingAs($employee)
            ->get('/admin/channels')
            ->assertOk()
            ->assertSee('Каналы связи');

        $this->assertTrue(Gate::forUser($employee)->allows('viewAny', Channel::class));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $channel));
        $this->assertFalse(Gate::forUser($employee)->allows('create', Channel::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $channel));
    }

    public function test_read_only_employee_cannot_see_channel_mutation_actions(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                'token' => 'telegram-visible-token',
            ],
            'bot_token_present' => true,
            'is_active' => true,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', false);

        Livewire::actingAs($employee)
            ->test(ManageChannels::class)
            ->assertTableActionHidden('registerWebhook', $channel)
            ->assertTableActionHidden('checkConnection', $channel)
            ->assertTableActionHidden('syncBotMetadata', $channel)
            ->assertTableActionHidden('manageScenarios', $channel);
    }

    public function test_employee_with_channel_edit_can_see_channel_mutation_actions(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                'token' => 'telegram-visible-token',
            ],
            'bot_token_present' => true,
            'is_active' => true,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', true);

        Livewire::actingAs($employee)
            ->test(ManageChannels::class)
            ->assertTableActionVisible('registerWebhook', $channel)
            ->assertTableActionVisible('checkConnection', $channel)
            ->assertTableActionVisible('syncBotMetadata', $channel)
            ->assertTableActionVisible('manageScenarios', $channel);
    }

    public function test_channel_update_guard_rejects_employee_without_channel_edit_permission(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                'token' => 'telegram-visible-token',
            ],
            'bot_token_present' => true,
            'is_active' => true,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', false);

        $this->actingAs($employee->fresh());

        $authorizer = new ReflectionMethod(ChannelResource::class, 'authorizeChannelUpdate');
        $authorizer->setAccessible(true);

        $this->assertHttpForbidden(fn () => $authorizer->invoke(null, $channel));
    }

    public function test_channel_update_guard_allows_employee_with_channel_edit_permission(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', true);

        $this->actingAs($employee->fresh());

        $authorizer = new ReflectionMethod(ChannelResource::class, 'authorizeChannelUpdate');
        $authorizer->setAccessible(true);
        $authorizer->invoke(null, $channel);

        $this->assertTrue(Gate::forUser($employee->fresh())->allows('update', $channel));
    }

    public function test_employee_can_create_channel_when_channels_edit_is_enabled_in_matrix(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', true);

        Livewire::actingAs($employee)
            ->test(ManageChannels::class)
            ->callAction('create', [
                'name' => 'Employee Telegram Bot',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => 'employee-secret-token',
                ],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('channels', [
            'name' => 'Employee Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
    }

    public function test_admin_can_create_telegram_bot_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callAction('create', [
                'name' => 'Telegram Bot',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => 'telegram-secret-token',
                ],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $channel = Channel::query()
            ->where('name', 'Telegram Bot')
            ->firstOrFail();

        $this->assertSame(Channel::PLATFORM_TELEGRAM, $channel->platform);
        $this->assertSame(Channel::CONNECTION_TYPE_BOT, $channel->connection_type);
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);
        $this->assertTrue($channel->is_active);
        $this->assertSame('telegram-secret-token', $channel->credentials['token']);
    }

    public function test_channel_form_uses_polished_section_layout(): void
    {
        $schema = ChannelResource::form(new Schema(null));

        /** @var array<int, Section> $sections */
        $sections = $schema->getComponents();

        $this->assertSame([
            'Основное',
            'Доступ и режим',
            'Токен',
        ], array_map(
            fn (Section $section): string => (string) $section->getHeading(),
            $sections,
        ));
    }

    public function test_channels_table_uses_inline_list_page_standard(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create();

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionHasIcon('edit', Heroicon::OutlinedPencilSquare, $channel)
            ->assertTableActionDoesNotHaveLabel('edit', $channel)
            ->tap(function ($component): void {
                $table = $component->instance()->getTable();

                $this->assertTrue($table->hasColumnManager());
                $this->assertFalse($table->hasDeferredColumnManager());
                $this->assertFalse($table->getColumnManagerApplyAction()->isVisible());
                $this->assertSame('Кнопки', $table->getRecordActionsColumnLabel());
            });
    }

    public function test_admin_can_create_max_bot_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callAction('create', [
                'name' => 'MAX Bot',
                'platform' => Channel::PLATFORM_MAX,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => 'max-secret-token',
                ],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $channel = Channel::query()
            ->where('name', 'MAX Bot')
            ->firstOrFail();

        $this->assertSame(Channel::PLATFORM_MAX, $channel->platform);
        $this->assertSame(Channel::CONNECTION_TYPE_BOT, $channel->connection_type);
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);
        $this->assertSame('max-secret-token', $channel->credentials['token']);
    }

    public function test_token_is_saved_encrypted_and_not_visible_in_plain_text_in_database(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'plain-visible-token',
            ],
        ]);

        $storedCredentials = DB::table('channels')
            ->where('id', $channel->id)
            ->value('credentials');

        $this->assertIsString($storedCredentials);
        $this->assertStringNotContainsString('plain-visible-token', $storedCredentials);

        $channel->refresh();

        $this->assertSame('plain-visible-token', $channel->credentials['token']);
    }

    public function test_admin_can_edit_channel_without_overwriting_existing_token_with_empty_value(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Original Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'current-token',
                'webhook_secret' => 'saved-secret',
            ],
            'bot_external_id' => '101',
            'bot_username' => 'old_bot',
            'bot_name' => 'Old Bot',
            'bot_profile_url' => 'https://t.me/old_bot',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('edit', $channel, [
                'name' => 'Updated Telegram Bot',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => '',
                ],
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertSame('Updated Telegram Bot', $channel->name);
        $this->assertFalse($channel->is_active);
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);
        $this->assertSame('current-token', $channel->credentials['token']);
        $this->assertSame('saved-secret', $channel->credentials['webhook_secret']);
        $this->assertSame('old_bot', $channel->bot_username);
    }

    public function test_sync_bot_metadata_action_uses_channel_token_presence_predicate(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-visible-token'],
            'bot_token_present' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionVisible('syncBotMetadata', $channel);

        DB::table('channels')
            ->where('id', $channel->id)
            ->update(['bot_token_present' => false]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionHidden('syncBotMetadata', $channel->fresh());
    }

    public function test_admin_can_update_channel_token_on_edit_without_losing_webhook_secret(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'old-token',
                'webhook_secret' => 'saved-secret',
            ],
            'bot_external_id' => '202',
            'bot_username' => 'old_username',
            'bot_name' => 'Old Username',
            'bot_profile_url' => 'https://t.me/old_username',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('edit', $channel, [
                'name' => $channel->name,
                'platform' => $channel->platform,
                'connection_type' => $channel->connection_type,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => 'new-token',
                ],
                'is_active' => $channel->is_active,
            ])
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertSame('new-token', $channel->credentials['token']);
        $this->assertSame('saved-secret', $channel->credentials['webhook_secret']);
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);
        $this->assertNull($channel->bot_external_id);
        $this->assertNull($channel->bot_username);
        $this->assertNull($channel->bot_name);
        $this->assertNull($channel->bot_profile_url);

        $storedCredentials = DB::table('channels')
            ->where('id', $channel->id)
            ->value('credentials');

        $this->assertIsString($storedCredentials);
        $this->assertStringNotContainsString('new-token', $storedCredentials);
    }

    public function test_admin_can_open_and_replace_unreadable_channel_credentials(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Broken Local Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
        ]);

        DB::table('channels')
            ->where('id', $channel->id)
            ->update([
                'credentials' => (new Encrypter(random_bytes(32), config('app.cipher')))
                    ->encrypt(['token' => 'old-unreadable-token']),
            ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('edit', $channel->fresh())
            ->assertTableActionDataSet([
                'name' => 'Broken Local Bot',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'credentials' => [
                    'token' => null,
                ],
                'is_active' => true,
            ])
            ->setTableActionData([
                'name' => 'Fixed Local Bot',
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => 'new-local-token',
                ],
                'is_active' => true,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertSame('Fixed Local Bot', $channel->name);
        $this->assertSame('new-local-token', $channel->getToken());
        $this->assertTrue($channel->bot_token_present);
    }

    public function test_channel_record_title_is_human_readable(): void
    {
        $channel = Channel::factory()->create([
            'name' => 'Support Bot',
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $this->assertSame(
            sprintf('#%d %s (%s)', $channel->id, $channel->name, 'MAX'),
            ChannelResource::getRecordTitle($channel),
        );
    }

    public function test_account_channel_table_summary_uses_runtime_state_labels(): void
    {
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
        ]);

        $summaryBuilder = new ReflectionMethod(ChannelResource::class, 'buildChannelTableSummary');
        $summaryBuilder->setAccessible(true);

        $summary = $summaryBuilder->invoke(null, $channel->fresh('runtimeState'));

        $this->assertSame('Авторизация: Авторизован · Синхронизация: В реальном времени', $summary);
        $this->assertSame('Работает', $channel->fresh('runtimeState')->getHealthStatusLabel());
    }

    public function test_account_channel_view_modal_shows_unsupported_connection_status(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Runtime',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_BACKFILL_IN_PROGRESS,
            'last_gateway_heartbeat_at' => now()->subMinute(),
            'last_sync_started_at' => now()->subMinutes(10),
            'last_error_message' => 'Временная деградация отсутствует.',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('view', $channel)
            ->assertMountedActionModalSee('Состояние')
            ->assertMountedActionModalSee('Не поддерживается')
            ->assertMountedActionModalSee('Webhook')
            ->assertMountedActionModalSee('Проверка подключения для этого типа канала пока не поддерживается');
    }

    public function test_account_channel_hides_bot_only_edit_and_manage_scenarios_actions(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionHidden('edit', $channel)
            ->assertTableActionHidden('manageScenarios', $channel);
    }

    public function test_account_channel_table_error_columns_use_runtime_state(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'last_error_message' => 'Устаревшая bot-side ошибка',
        ]);

        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_FAILED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_CODE,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_FAILED,
            'last_error_message' => 'Актуальная account runtime ошибка',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableColumnStateSet('last_error_message', 'Актуальная account runtime ошибка', $channel->fresh('runtimeState'));
    }

    public function test_delete_and_bulk_delete_are_forbidden_by_policy(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $channel));
        $this->assertFalse(Gate::forUser($admin)->allows('deleteAny', Channel::class));
    }

    public function test_channel_defaults_to_rules_only_when_not_explicitly_set(): void
    {
        $channel = Channel::query()->create([
            'name' => 'Default Mode Channel',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                'token' => 'telegram-token',
            ],
            'is_active' => true,
        ]);

        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->fresh()->auto_reply_mode);
    }

    public function test_admin_can_see_and_update_auto_reply_mode_in_channels_ui(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->create([
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertSee('Автоответ')
            ->assertSee('Только правила')
            ->callTableAction('edit', $channel, [
                'name' => $channel->name,
                'platform' => $channel->platform,
                'connection_type' => $channel->connection_type,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => '',
                ],
                'is_active' => $channel->is_active,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->fresh()->auto_reply_mode);
    }

    public function test_admin_can_open_manage_scenarios_modal_for_telegram_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionVisible('manageScenarios', $channel)
            ->mountTableAction('manageScenarios', $channel)
            ->assertMountedActionModalSee('Сценарии канала')
            ->assertMountedActionModalSee('Активные сценарии')
            ->assertMountedActionModalSee('Прогрев')
            ->assertMountedActionModalSee('Выявление потребностей')
            ->assertTableActionDataSet([
                'scenario_codes' => [],
            ]);
    }

    public function test_manage_scenarios_modal_shows_compatible_scenarios_for_max_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('manageScenarios', $channel)
            ->assertMountedActionModalSee('Сценарии канала')
            ->assertMountedActionModalSee('Активные сценарии')
            ->assertMountedActionModalSee('Прогрев')
            ->assertMountedActionModalSee('Выявление потребностей');
    }

    public function test_admin_can_enable_warmup_scenario_for_telegram_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => ['warmup'],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);
    }

    public function test_manage_scenarios_does_not_create_duplicate_bindings_and_can_reactivate_existing_one(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => ['warmup'],
            ])
            ->assertHasNoTableActionErrors()
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => ['warmup'],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, ScenarioChannelBinding::query()
            ->where('channel_id', $channel->id)
            ->where('scenario_code', 'warmup')
            ->count());
        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_disable_existing_compatible_scenario_binding_without_deleting_it(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => [],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, ScenarioChannelBinding::query()
            ->where('channel_id', $channel->id)
            ->where('scenario_code', 'warmup')
            ->count());
        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => false,
        ]);
    }

    public function test_manage_scenarios_preserves_hidden_constructor_workspace_binding(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $workspace = app(CreateScenarioAction::class)->handle([
            'code' => Scenario::CONSTRUCTOR_WORKSPACE_CODE,
            'name' => 'Конструктор',
            'is_active' => true,
        ]);
        $workspace->draftVersion()->firstOrFail()->forceFill([
            'schema_payload' => [
                'version' => 1,
                'start_block_id' => 'welcome',
                'triggers' => [
                    [
                        'type' => 'parameter',
                        'value' => 'constructor_hidden',
                    ],
                ],
                'blocks' => [
                    'welcome' => [
                        'type' => 'message',
                        'text' => 'Ответ из конструктора',
                        'text_format' => 'plain_text',
                        'next' => 'done',
                    ],
                    'done' => [
                        'type' => 'complete',
                    ],
                ],
            ],
        ])->save();

        $publishedVersion = app(PublishScenarioVersionAction::class)
            ->handle($workspace->draftVersion()->firstOrFail());

        $this->assertSame(ScenarioVersion::STATUS_PUBLISHED, $publishedVersion->status);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => Scenario::CONSTRUCTOR_WORKSPACE_CODE,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('manageScenarios', $channel)
            ->assertTableActionDataSet([
                'scenario_codes' => [],
            ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => [],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => Scenario::CONSTRUCTOR_WORKSPACE_CODE,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_enable_warmup_scenario_for_max_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => ['warmup'],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_enable_needs_discovery_scenario_for_telegram_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => ['needs_discovery'],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'needs_discovery',
            'is_active' => true,
        ]);
    }

    public function test_manage_scenarios_deactivates_existing_incompatible_active_binding(): void
    {
        config()->set('scenarios.legacy_telegram_only', [
            'handler' => WarmupScenario::class,
            'platforms' => [
                Channel::PLATFORM_TELEGRAM,
            ],
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'legacy_telegram_only',
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => [],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'legacy_telegram_only',
            'is_active' => false,
        ]);
    }

    public function test_admin_can_view_latest_messages_in_channel_view_modal(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-100',
        ]);

        $autoReplySentAt = Carbon::create(2026, 3, 28, 10, 11, 12);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-900',
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'msg-900',
            'text' => 'Нужна помощь',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
            'auto_reply_sent_at' => $autoReplySentAt,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'webhook.duplicate_ignored',
            'message' => 'Повторный webhook обработан без повторной отправки ответа.',
            'context' => [
                'provider_event_key' => 'telegram-update-900',
            ],
            'created_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('view', $channel)
            ->assertMountedActionModalSee('Последний webhook')
            ->assertMountedActionModalSee('Лента сообщений')
            ->assertMountedActionModalSee('ext-100')
            ->assertMountedActionModalSee('telegram-update-900')
            ->assertMountedActionModalSee('28.03.2026 10:11:12')
            ->assertMountedActionModalSee('Ответ отправлен')
            ->assertMountedActionModalSee('Дубликат проигнорирован')
            ->assertMountedActionModalSee('Нужна помощь')
            ->assertMountedActionModalSee('Входящее')
            ->assertMountedActionModalSee('Пользователь');
    }

    public function test_channel_modal_prefers_message_chronology_over_saved_id_order(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => null,
            'text' => 'старт',
            'raw_payload' => ['message' => 'oldest'],
            'received_at' => now()->addYears(2),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => null,
            'text' => 'тест3',
            'raw_payload' => ['message' => 'middle'],
            'received_at' => now()->addYear(),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'external_chat_id' => 'chat-500',
            'external_message_id' => 'mid.0000000003e3748c019d30476b8e52e7',
            'text' => 'тест5',
            'raw_payload' => ['message' => 'latest'],
            'received_at' => now()->subYear(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('view', $channel)
            ->assertMountedActionModalSee('старт');

        $latestMessageResolver = new ReflectionMethod(ChannelResource::class, 'resolveLatestSavedMessage');
        $latestMessageResolver->setAccessible(true);

        /** @var Message $latestMessage */
        $latestMessage = $latestMessageResolver->invoke(null, $channel);

        $this->assertSame('старт', $latestMessage->text);

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $latestPosition = strpos($recentMessagesHtml, 'старт');
        $middlePosition = strpos($recentMessagesHtml, 'тест3');
        $oldestPosition = strpos($recentMessagesHtml, 'тест5');

        $this->assertIsInt($latestPosition);
        $this->assertIsInt($middlePosition);
        $this->assertIsInt($oldestPosition);
        $this->assertTrue($latestPosition < $middlePosition);
        $this->assertTrue($middlePosition < $oldestPosition);
    }

    public function test_recent_messages_renderer_shows_provider_event_key_auto_reply_timestamp_and_pending_status(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-200',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-901',
            'external_chat_id' => 'chat-901',
            'external_message_id' => 'msg-901',
            'text' => 'Повторное сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => Carbon::create(2026, 3, 28, 12, 30, 0),
            'auto_reply_sent_at' => null,
        ]);

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Тип: Пользователь', $recentMessagesHtml);
        $this->assertStringContainsString('Event key: telegram-update-901', $recentMessagesHtml);
        $this->assertStringContainsString('Автоответ: —', $recentMessagesHtml);
        $this->assertStringContainsString('Статус: Ответ еще не отправлен', $recentMessagesHtml);
    }

    public function test_channel_diagnostics_use_media_summary_for_media_only_account_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'name' => 'Telegram Account Media Diagnostics',
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-account-media',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-account-media-901',
            'external_chat_id' => 'chat-account-media-901',
            'external_message_id' => 'msg-account-media-901',
            'text' => null,
            'raw_payload' => [
                'media' => [
                    ['type' => 'document', 'file_name' => 'offer.pdf'],
                ],
            ],
            'received_at' => Carbon::create(2026, 4, 23, 12, 30, 0),
            'auto_reply_sent_at' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('view', $channel)
            ->assertMountedActionModalSee('Документ: offer.pdf')
            ->assertMountedActionModalSee('Ожидает загрузки');

        $latestMessageTextResolver = new ReflectionMethod(ChannelResource::class, 'resolveLatestSavedMessageDisplayText');
        $latestMessageTextResolver->setAccessible(true);

        $this->assertSame('Документ: offer.pdf', $latestMessageTextResolver->invoke(null, $channel));

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Документ: offer.pdf', $recentMessagesHtml);
        $this->assertStringContainsString('Ожидает загрузки', $recentMessagesHtml);
        $this->assertStringNotContainsString('>—<', $recentMessagesHtml);
    }

    public function test_recent_activity_renderer_shows_and_highlights_dedupe_events(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'webhook.duplicate_retry_reply',
            'message' => 'Повторный webhook использован для повторной отправки автоответа.',
            'context' => [
                'provider_event_key' => 'telegram-update-902',
            ],
            'created_at' => Carbon::create(2026, 3, 28, 12, 45, 0),
        ]);

        $recentActivityRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentActivityLogs');
        $recentActivityRenderer->setAccessible(true);

        $recentActivityHtml = $recentActivityRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Дубликат → retry ответа', $recentActivityHtml);
        $this->assertStringContainsString('Event key: telegram-update-902', $recentActivityHtml);
        $this->assertStringContainsString('data-dedupe-event="true"', $recentActivityHtml);
    }

    public function test_recent_activity_renderer_formats_delayed_webhook_event_and_shows_lag_badge(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'webhook.delayed_received',
            'message' => 'Webhook из MAX получен с заметной задержкой.',
            'context' => [
                'provider_event_key' => 'max-event-901',
                'delivery_lag_seconds' => 1547,
            ],
            'created_at' => Carbon::create(2026, 3, 31, 19, 6, 46),
        ]);

        $recentActivityRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentActivityLogs');
        $recentActivityRenderer->setAccessible(true);

        $recentActivityHtml = $recentActivityRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Webhook пришёл с задержкой', $recentActivityHtml);
        $this->assertStringContainsString('Event key: max-event-901', $recentActivityHtml);
        $this->assertStringContainsString('Лаг: 1547 сек', $recentActivityHtml);
        $this->assertStringContainsString('Webhook из MAX получен с заметной задержкой.', $recentActivityHtml);
    }

    public function test_recent_activity_renderer_formats_out_of_order_webhook_event_and_shows_offset_badge(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'webhook.out_of_order_received',
            'message' => 'Webhook из MAX получен не по порядку относительно уже сохранённых входящих сообщений.',
            'context' => [
                'provider_event_key' => 'max-event-902',
                'seconds_behind_latest_inbound' => 900,
            ],
            'created_at' => Carbon::create(2026, 3, 31, 19, 6, 47),
        ]);

        $recentActivityRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentActivityLogs');
        $recentActivityRenderer->setAccessible(true);

        $recentActivityHtml = $recentActivityRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Webhook пришёл не по порядку', $recentActivityHtml);
        $this->assertStringContainsString('Event key: max-event-902', $recentActivityHtml);
        $this->assertStringContainsString('Отставание: 900 сек', $recentActivityHtml);
        $this->assertStringContainsString('Webhook из MAX получен не по порядку относительно уже сохранённых входящих сообщений.', $recentActivityHtml);
    }

    public function test_recent_activity_renderer_formats_late_phone_capture_event(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'contact.phone_capture_arrived_late',
            'message' => 'Поздний phone share из MAX успешно дошёл до обработки.',
            'context' => [
                'provider_event_key' => 'max-event-903',
            ],
            'created_at' => Carbon::create(2026, 3, 31, 19, 6, 48),
        ]);

        $recentActivityRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentActivityLogs');
        $recentActivityRenderer->setAccessible(true);

        $recentActivityHtml = $recentActivityRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Поздний phone share обработан', $recentActivityHtml);
        $this->assertStringContainsString('Event key: max-event-903', $recentActivityHtml);
        $this->assertStringContainsString('Поздний phone share из MAX успешно дошёл до обработки.', $recentActivityHtml);
    }

    public function test_recent_messages_renderer_shows_outbound_reply_link(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-300',
        ]);

        $inboundMessage = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-update-903',
            'external_chat_id' => 'chat-903',
            'external_message_id' => 'msg-903',
            'text' => 'Входящее сообщение',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => Carbon::create(2026, 3, 28, 13, 0, 0),
            'auto_reply_sent_at' => Carbon::create(2026, 3, 28, 13, 0, 5),
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $inboundMessage->id,
            'external_chat_id' => 'chat-903',
            'external_message_id' => 'out-903',
            'text' => 'Исходящий автоответ',
            'raw_payload' => ['message' => ['message_id' => 'out-903']],
            'received_at' => Carbon::create(2026, 3, 28, 13, 0, 5),
        ]);

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Тип: Автоответ', $recentMessagesHtml);
        $this->assertStringContainsString('Исходящее', $recentMessagesHtml);
        $this->assertStringContainsString('Исходящий автоответ', $recentMessagesHtml);
        $this->assertStringContainsString('Связь: Ответ на event key: telegram-update-903', $recentMessagesHtml);
    }

    public function test_recent_messages_renderer_shows_unknown_kind_for_historical_messages_without_classification(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-legacy',
        ]);

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => null,
            'external_chat_id' => 'chat-legacy',
            'external_message_id' => 'legacy-out',
            'text' => 'Исторический outbound',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => Carbon::create(2026, 3, 28, 13, 5, 0),
        ]);

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Исторический outbound', $recentMessagesHtml);
        $this->assertStringContainsString('Тип: Не определен', $recentMessagesHtml);
    }

    public function test_recent_activity_renderer_shows_human_readable_rate_limit_warning_details(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'warning',
            'event' => 'webhook.rate_limited',
            'message' => 'Входящий webhook временно ограничен по частоте запросов.',
            'context' => [
                'retry_after_seconds' => 59,
                'max_per_minute' => 1,
                'route' => 'webhooks.telegram.handle',
                'request_ip' => '127.0.0.1',
            ],
            'created_at' => Carbon::create(2026, 4, 2, 12, 0, 0),
        ]);

        $recentActivityRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentActivityLogs');
        $recentActivityRenderer->setAccessible(true);

        $recentActivityHtml = $recentActivityRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Событие: Webhook ограничен по частоте', $recentActivityHtml);
        $this->assertStringContainsString('Уровень: Предупреждение', $recentActivityHtml);
        $this->assertStringContainsString('Retry after: 59 сек', $recentActivityHtml);
        $this->assertStringContainsString('Лимит: 1/мин', $recentActivityHtml);
        $this->assertStringContainsString('Route: webhooks.telegram.handle', $recentActivityHtml);
        $this->assertStringContainsString('IP: 127.0.0.1', $recentActivityHtml);
    }

    private function setRolePermission(string $role, string $permissionKey, bool $granted): void
    {
        DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission_key', $permissionKey)
            ->update(['granted' => $granted]);
    }

    private function assertHttpForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a 403 response exception.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
