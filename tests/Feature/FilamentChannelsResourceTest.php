<?php

namespace Tests\Feature;

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Channels\Pages\ManageChannels;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\ChannelConnectionCheckRun;
use App\Models\ChannelRuntimeState;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Bots\CheckChannelConnectionAction;
use App\Services\Scenarios\CreateScenarioAction;
use App\Services\Scenarios\PublishScenarioVersionAction;
use App\Services\Scenarios\WarmupScenario;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Events\QueryExecuted;
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
            ->assertTableActionHidden('manageScenarios', $channel)
            ->assertTableActionHidden('hideChannel', $channel)
            ->assertTableActionHidden('showChannel', $channel)
            ->assertTableActionHidden('edit', $channel)
            ->assertTableActionHidden('delete', $channel);
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
            ->assertTableActionVisible('manageScenarios', $channel)
            ->assertTableActionVisible('hideChannel', $channel)
            ->assertTableActionHidden('showChannel', $channel)
            ->assertTableActionVisible('edit', $channel)
            ->assertTableActionHidden('delete', $channel);
    }

    public function test_admin_can_hide_channel_from_table_without_disabling_it(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Old Smoke Bot',
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $activeChannel = Channel::factory()->create([
            'name' => 'Working Bot',
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $inactiveButVisibleChannel = Channel::factory()->create([
            'name' => 'Inactive But Visible Bot',
            'is_active' => false,
            'is_hidden' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertCanSeeTableRecords([$channel, $activeChannel, $inactiveButVisibleChannel])
            ->assertTableActionVisible('hideChannel', $channel)
            ->assertTableActionHidden('showChannel', $channel)
            ->callTableAction('hideChannel', $channel)
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertTrue($channel->is_active);
        $this->assertTrue($channel->is_hidden);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertCanSeeTableRecords([$activeChannel, $inactiveButVisibleChannel])
            ->assertCanNotSeeTableRecords([$channel]);
    }

    public function test_admin_can_show_hidden_channels_without_enabling_them(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Hidden Smoke Bot',
            'is_active' => false,
            'is_hidden' => true,
        ]);
        $activeChannel = Channel::factory()->create([
            'name' => 'Visible Bot',
            'is_active' => true,
            'is_hidden' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->filterTable('visibility', 'hidden')
            ->assertCanSeeTableRecords([$channel])
            ->assertCanNotSeeTableRecords([$activeChannel])
            ->assertTableActionVisible('showChannel', $channel)
            ->assertTableActionHidden('hideChannel', $channel)
            ->callTableAction('showChannel', $channel)
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertFalse($channel->is_active);
        $this->assertFalse($channel->is_hidden);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertCanSeeTableRecords([$channel, $activeChannel]);
    }

    public function test_admin_can_use_visibility_filter_to_show_all_channels(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $visibleChannel = Channel::factory()->create([
            'name' => 'Visible Channel',
            'is_active' => false,
            'is_hidden' => false,
        ]);
        $hiddenChannel = Channel::factory()->create([
            'name' => 'Hidden Channel',
            'is_active' => true,
            'is_hidden' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertCanSeeTableRecords([$visibleChannel])
            ->assertCanNotSeeTableRecords([$hiddenChannel])
            ->filterTable('visibility', 'all')
            ->assertCanSeeTableRecords([$visibleChannel, $hiddenChannel]);
    }

    public function test_channel_delete_is_blocked_even_for_channel_editor(): void
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

        $this->assertFalse(Gate::forUser($employee->fresh())->allows('delete', $channel));

        Livewire::actingAs($employee)
            ->test(ManageChannels::class)
            ->assertTableActionHidden('delete', $channel);

        $this->actingAs($employee->fresh());

        $authorizer = new ReflectionMethod(ChannelResource::class, 'authorizeChannelDelete');
        $authorizer->setAccessible(true);

        $this->assertHttpForbidden(fn () => $authorizer->invoke(null, $channel));
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
                $this->assertFalse($table->hasDeferredFilters());
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

    public function test_account_channel_table_summary_is_hidden_to_keep_name_column_compact(): void
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
            'last_gateway_heartbeat_at' => now(),
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => true,
                ],
            ],
        ]);

        $summaryBuilder = new ReflectionMethod(ChannelResource::class, 'buildChannelTableSummary');
        $summaryBuilder->setAccessible(true);

        $channel = $channel->fresh('runtimeState');
        $summary = $summaryBuilder->invoke(null, $channel);

        $this->assertNull($summary);
        $this->assertSame('Работает', $channel->getHealthStatusLabel());
    }

    public function test_bot_channel_table_summary_does_not_duplicate_username_column(): void
    {
        $summaryBuilder = new ReflectionMethod(ChannelResource::class, 'buildChannelTableSummary');
        $summaryBuilder->setAccessible(true);

        $channel = Channel::factory()->create([
            'name' => 'MAX локальный',
            'platform' => Channel::PLATFORM_MAX,
            'bot_username' => 'id262403882602_bot',
            'bot_name' => null,
        ]);

        $this->assertNull($summaryBuilder->invoke(null, $channel));

        $channel->forceFill([
            'bot_name' => 'Bot profile name',
        ])->saveQuietly();

        $this->assertSame('Bot profile name', $summaryBuilder->invoke(null, $channel->fresh()));
    }

    public function test_account_channel_username_column_shows_gateway_account_identity(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'runtime_payload' => [
                'account' => [
                    'id' => '100001',
                    'username' => 'gateway_account',
                    'display_name' => 'Gateway Account',
                ],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableColumnStateSet('bot_username', '@gateway_account', $channel->fresh('runtimeState'));

        $channel = $channel->fresh('runtimeState');
        $modalData = ChannelResource::buildChannelViewModalData($channel);
        $flatRows = collect($modalData['summaryTables'])->flatten(1);
        $overviewHtml = view(
            'filament.channels.partials.channel-view-overview',
            $modalData,
        )->render();

        $this->assertTrue($flatRows->contains(fn (array $row): bool => $row['label'] === 'Аккаунт' && $row['value'] === '@gateway_account'));
        $this->assertTrue($flatRows->contains(fn (array $row): bool => $row['label'] === 'Имя аккаунта' && $row['value'] === 'Gateway Account'));
        $this->assertSame('https://t.me/gateway_account', $channel->getTelegramAccountProfileUrl());
        $this->assertTrue($flatRows->contains(fn (array $row): bool => $row['label'] === 'Аккаунт' && $row['url'] === 'https://t.me/gateway_account'));
        $this->assertStringContainsString('href="https://t.me/gateway_account"', $overviewHtml);
    }

    public function test_account_channel_username_column_is_searchable_by_gateway_account_username(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $matchingChannel = Channel::factory()->account()->create([
            'name' => 'Matching Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        ChannelRuntimeState::query()->create([
            'channel_id' => $matchingChannel->id,
            'runtime_payload' => [
                'account' => [
                    'id' => '100001',
                    'username' => 'gateway_account',
                    'display_name' => 'Gateway Account',
                ],
            ],
        ]);

        $otherAccountChannel = Channel::factory()->account()->create([
            'name' => 'Other Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        ChannelRuntimeState::query()->create([
            'channel_id' => $otherAccountChannel->id,
            'runtime_payload' => [
                'account' => [
                    'id' => '100002',
                    'username' => 'other_account',
                    'display_name' => 'Other Account',
                ],
            ],
        ]);

        $botChannel = Channel::factory()->create([
            'name' => 'Classic Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'bot_username' => 'classic_bot',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->searchTable('@gateway_account')
            ->assertCanSeeTableRecords([$matchingChannel])
            ->assertCanNotSeeTableRecords([$otherAccountChannel, $botChannel]);
    }

    public function test_account_channel_username_column_sorts_by_gateway_account_identity(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $zetaAccountChannel = Channel::factory()->account()->create([
            'name' => 'Zeta Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        ChannelRuntimeState::query()->create([
            'channel_id' => $zetaAccountChannel->id,
            'runtime_payload' => [
                'account' => [
                    'username' => 'zeta_account',
                ],
            ],
        ]);

        $botChannel = Channel::factory()->create([
            'name' => 'Middle Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'bot_username' => 'middle_bot',
        ]);

        $alphaAccountChannel = Channel::factory()->account()->create([
            'name' => 'Alpha Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        ChannelRuntimeState::query()->create([
            'channel_id' => $alphaAccountChannel->id,
            'runtime_payload' => [
                'account' => [
                    'username' => 'alpha_account',
                ],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->call('sortTable', 'bot_username', 'asc')
            ->assertCanSeeTableRecords([$alphaAccountChannel, $botChannel, $zetaAccountChannel], inOrder: true)
            ->call('sortTable', 'bot_username', 'desc')
            ->assertCanSeeTableRecords([$zetaAccountChannel, $botChannel, $alphaAccountChannel], inOrder: true);
    }

    public function test_account_channel_identity_falls_back_to_external_id_without_username(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'runtime_payload' => [
                'account' => [
                    'id' => 100001,
                    'username' => null,
                    'display_name' => 'Gateway Account',
                ],
            ],
        ]);

        $channel = $channel->fresh('runtimeState');

        $this->assertSame('ID 100001', $channel->getTelegramAccountIdentityLabel());
        $this->assertSame('Gateway Account', $channel->getTelegramAccountDisplayNameLabel());
        $this->assertSame('100001', $channel->getTelegramAccountExternalIdLabel());
    }

    public function test_account_channel_view_modal_shows_gateway_diagnostics_instead_of_unsupported_connection_error(): void
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
            ->assertMountedActionModalSee('Синхронизация')
            ->assertMountedActionModalSee('Webhook')
            ->assertMountedActionModalSee('Не применяется')
            ->assertMountedActionModalSee('Исходящие ответы')
            ->assertMountedActionModalSee('Синхронизация Telegram account не в реальном времени')
            ->assertMountedActionModalSee('Ошибок не было')
            ->assertDontSee('Проверка подключения не применяется к Telegram account');
    }

    public function test_channel_view_modal_uses_clickable_disclosures_for_feed_and_activity_log(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $html = view(
            'filament.channels.partials.channel-view-overview',
            ChannelResource::buildChannelViewModalData($channel),
        )->render();

        $this->assertSame(2, substr_count($html, 'class="ac-channel-view-panel__header ac-channel-view-panel__summary"'));
        $this->assertSame(2, substr_count($html, 'x-show'));
        $this->assertSame(2, substr_count($html, 'x-data="{ open: false }"'));
        $this->assertStringContainsString('Лента сообщений', $html);
        $this->assertStringContainsString('Техжурнал', $html);
    }

    public function test_channel_view_modal_escapes_recent_feed_and_activity_values(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'user-<img src=x onerror=alert(1)>',
        ]);
        $messageText = '<script>alert("message")</script>';
        $providerEventKey = 'event-<img src=x onerror=alert(2)>';
        $activityMessage = '<img src=x onerror=alert(3)>';

        Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => $providerEventKey,
            'external_chat_id' => 'chat-escaped',
            'external_message_id' => 'msg-escaped',
            'text' => $messageText,
            'raw_payload' => ['message' => $messageText],
            'received_at' => now(),
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'webhook.duplicate_ignored',
            'message' => $activityMessage,
            'context' => [
                'provider_event_key' => $providerEventKey,
            ],
            'created_at' => now(),
        ]);

        $html = view(
            'filament.channels.partials.channel-view-overview',
            ChannelResource::buildChannelViewModalData($channel),
        )->render();

        $this->assertStringContainsString(e($messageText), $html);
        $this->assertStringContainsString(e($providerEventKey), $html);
        $this->assertStringContainsString(e($activityMessage), $html);
        $this->assertStringNotContainsString($messageText, $html);
        $this->assertStringNotContainsString($activityMessage, $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
    }

    public function test_stale_successful_connection_is_displayed_as_warning_in_table_and_modal(): void
    {
        config()->set('app.url', 'https://connector.example');

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Stale Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'bot_token_present' => true,
            'is_active' => true,
        ]);
        $webhookUrl = sprintf('https://connector.example/webhooks/telegram/%d', $channel->id);
        ChannelConnectionCheckRun::query()->create([
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'status' => ChannelConnectionCheckRun::STATUS_SUCCESS,
            'processed_count' => 1,
            'success_count' => 1,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);

        $channel->forceFill([
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now()->subMinutes(11),
            'connection_error_message' => null,
            'expected_webhook_url' => $webhookUrl,
            'provider_webhook_url' => $webhookUrl,
        ])->saveQuietly();

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableColumnStateSet('health_status', 'Проверка устарела', $channel)
            ->assertTableColumnStateSet('webhook_secret_status', 'Проверка устарела', $channel)
            ->assertTableColumnStateSet('connection_error_message', Channel::CONNECTION_ERROR_STALE, $channel)
            ->mountTableAction('view', $channel)
            ->assertMountedActionModalSee('Проверка устарела')
            ->assertMountedActionModalSee(Channel::CONNECTION_ERROR_STALE);

        $connectionState = app(CheckChannelConnectionAction::class)
            ->resolveEffectiveState($channel->fresh());
        $statusColorResolver = new ReflectionMethod(ChannelResource::class, 'resolveConnectionStatusColor');
        $statusColorResolver->setAccessible(true);
        $webhookColorResolver = new ReflectionMethod(ChannelResource::class, 'resolveLiveWebhookStatusColor');
        $webhookColorResolver->setAccessible(true);

        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $connectionState['connection_status']);
        $this->assertSame('warning', $statusColorResolver->invoke(null, $channel, $connectionState));
        $this->assertSame('warning', $webhookColorResolver->invoke(null, $channel, $connectionState));
    }

    public function test_scheduler_health_problem_is_displayed_separately_from_stale_bot_webhook_state(): void
    {
        config()->set('app.url', 'https://connector.example');

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Stale Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'bot_token_present' => true,
            'is_active' => true,
        ]);
        $webhookUrl = sprintf('https://connector.example/webhooks/telegram/%d', $channel->id);

        $channel->forceFill([
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now()->subMinutes(11),
            'connection_error_message' => null,
            'expected_webhook_url' => $webhookUrl,
            'provider_webhook_url' => $webhookUrl,
        ])->saveQuietly();

        $connectionState = app(CheckChannelConnectionAction::class)
            ->resolveEffectiveState($channel->fresh());
        $errorResolver = new ReflectionMethod(ChannelResource::class, 'resolveConnectionErrorDisplay');
        $errorResolver->setAccessible(true);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertSee('Планировщик проверок ещё не запускался')
            ->assertTableColumnStateSet('health_status', 'Нет heartbeat', $channel)
            ->assertTableColumnStateSet('webhook_secret_status', 'Был установлен', $channel)
            ->mountTableAction('view', $channel)
            ->assertMountedActionModalSee('Планировщик')
            ->assertMountedActionModalSee('Планировщик проверок ещё не запускался')
            ->assertMountedActionModalSee('Планировщик проверок каналов ещё не записывал heartbeat.');

        $this->assertNull($errorResolver->invoke(null, $channel, $connectionState));
    }

    public function test_connection_checker_health_is_cached_for_current_request(): void
    {
        ChannelConnectionCheckRun::query()->create([
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'status' => ChannelConnectionCheckRun::STATUS_SUCCESS,
            'processed_count' => 5,
            'success_count' => 5,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);

        $healthResolver = new ReflectionMethod(ChannelResource::class, 'resolveConnectionCheckerHealth');
        $healthResolver->setAccessible(true);
        $healthQueries = 0;

        DB::listen(function (QueryExecuted $query) use (&$healthQueries): void {
            if (str_contains($query->sql, 'channel_connection_check_runs')) {
                $healthQueries++;
            }
        });

        $firstHealth = $healthResolver->invoke(null);
        $secondHealth = $healthResolver->invoke(null);
        $thirdHealth = $healthResolver->invoke(null);

        $this->assertSame('ok', $firstHealth['status']);
        $this->assertSame($firstHealth, $secondHealth);
        $this->assertSame($firstHealth, $thirdHealth);
        $this->assertSame(1, $healthQueries);
    }

    public function test_account_channel_allows_safe_edit_and_hides_bot_only_actions(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->account()->create([
            'name' => 'Local Telegram Account Gateway',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'credentials' => [],
            'bot_token_present' => false,
            'is_active' => true,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'telegram_account_media_auto_download_max_bytes' => 32 * 1024 * 1024,
        ]);
        $originalChannelConnectionTypeId = $channel->channel_connection_type_id;

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionVisible('edit', $channel)
            ->assertTableActionHidden('checkConnection', $channel)
            ->assertTableActionHidden('manageScenarios', $channel)
            ->assertTableActionHidden('syncBotMetadata', $channel)
            ->callTableAction('edit', $channel, [
                'name' => 'Аккаунт Telegram локальный',
                'channel_connection_type_id' => 999,
                'platform' => Channel::PLATFORM_MAX,
                'connection_type' => Channel::CONNECTION_TYPE_BOT,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => 'should-not-be-saved',
                ],
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertSame('Аккаунт Telegram локальный', $channel->name);
        $this->assertSame(Channel::PLATFORM_TELEGRAM, $channel->platform);
        $this->assertSame(Channel::CONNECTION_TYPE_ACCOUNT, $channel->connection_type);
        $this->assertSame($originalChannelConnectionTypeId, $channel->channel_connection_type_id);
        $this->assertSame([], $channel->credentials);
        $this->assertFalse($channel->bot_token_present);
        $this->assertTrue($channel->is_active);
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);
        $this->assertSame(32 * 1024 * 1024, $channel->telegram_account_media_auto_download_max_bytes);
    }

    public function test_admin_can_edit_account_channel_external_outgoing_sync_toggle(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->account()->create([
            'name' => 'Local Telegram Account Gateway',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'sync_external_outgoing_enabled' => false,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('edit', $channel)
            ->assertTableActionDataSet([
                'sync_external_outgoing_enabled' => false,
            ])
            ->setTableActionData([
                'name' => $channel->name,
                'channel_connection_type_id' => $channel->channel_connection_type_id,
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => null,
                ],
                'is_active' => false,
                'sync_external_outgoing_enabled' => true,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertTrue($channel->sync_external_outgoing_enabled);
        $this->assertSame(Channel::PLATFORM_TELEGRAM, $channel->platform);
        $this->assertSame(Channel::CONNECTION_TYPE_ACCOUNT, $channel->connection_type);
        $this->assertTrue($channel->is_active);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('edit', $channel)
            ->assertTableActionDataSet([
                'sync_external_outgoing_enabled' => true,
            ]);
    }

    public function test_admin_can_edit_account_channel_media_auto_download_limit(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $channel = Channel::factory()->account()->create([
            'name' => 'Local Telegram Account Gateway',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'telegram_account_media_auto_download_max_bytes' => 32 * 1024 * 1024,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->mountTableAction('edit', $channel)
            ->assertTableActionDataSet([
                'telegram_account_media_auto_download_max_mb' => 32,
            ])
            ->setTableActionData([
                'name' => $channel->name,
                'channel_connection_type_id' => $channel->channel_connection_type_id,
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
                'credentials' => [
                    'token' => null,
                ],
                'is_active' => false,
                'sync_external_outgoing_enabled' => false,
                'telegram_account_media_auto_download_max_mb' => 64,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(64 * 1024 * 1024, $channel->fresh()->telegram_account_media_auto_download_max_bytes);
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

    public function test_channel_delete_policy_blocks_hard_delete_and_bulk_delete(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $channel = Channel::factory()->create();

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', false);

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $channel));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $channel));
        $this->assertFalse(Gate::forUser($admin)->allows('deleteAny', Channel::class));

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', true);

        $this->assertFalse(Gate::forUser($employee->fresh())->allows('delete', $channel));
        $this->assertFalse(Gate::forUser($employee->fresh())->allows('deleteAny', Channel::class));
    }

    public function test_admin_cannot_delete_unused_channel_from_resource_table(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Unused Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionHidden('delete', $channel);

        $this->assertDatabaseHas('channels', [
            'id' => $channel->id,
        ]);
    }

    public function test_used_channel_delete_action_is_hidden_and_keeps_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
        ]);

        Dialog::factory()->create([
            'current_contact_identity_id' => $identity->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionHidden('delete', $channel);

        $this->assertDatabaseHas('channels', [
            'id' => $channel->id,
        ]);
    }

    public function test_admin_cannot_delete_channel_with_history_after_dialogs_are_removed(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->assertTableActionHidden('delete', $channel);

        $this->assertDatabaseHas('channels', [
            'id' => $channel->id,
        ]);
        $this->assertDatabaseHas('contact_identities', [
            'id' => $identity->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
        ]);
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
            ->assertDontSee('Прогрев')
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
            ->assertDontSee('Прогрев')
            ->assertMountedActionModalSee('Выявление потребностей');
    }

    public function test_admin_cannot_enable_disabled_warmup_scenario_for_telegram_channel(): void
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
            ->assertHasTableActionErrors();

        $this->assertDatabaseMissing('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
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
            'scenario_code' => 'needs_discovery',
            'is_active' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => ['needs_discovery'],
            ])
            ->assertHasNoTableActionErrors()
            ->callTableAction('manageScenarios', $channel, [
                'scenario_codes' => ['needs_discovery'],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, ScenarioChannelBinding::query()
            ->where('channel_id', $channel->id)
            ->where('scenario_code', 'needs_discovery')
            ->count());
        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'needs_discovery',
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
            'scenario_code' => 'needs_discovery',
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
            ->where('scenario_code', 'needs_discovery')
            ->count());
        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'needs_discovery',
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

    public function test_channel_delete_blockers_ignore_inactive_scenario_bindings_and_archived_v3_blocks(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $scenario = Scenario::query()->create([
            'code' => 'delete_blockers_cleanup',
            'name' => 'Delete Blockers Cleanup',
            'is_active' => true,
        ]);
        $archivedVersion = ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_ARCHIVED,
            'schema_payload' => [],
        ]);
        $archivedBlock = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $archivedVersion->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Старый старт',
            'settings_payload' => [],
        ]);

        $archivedBlock->channels()->sync([$channel->id]);
        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => false,
        ]);

        $this->assertSame([], $this->channelDeleteBlockers($channel));
    }

    public function test_channel_delete_auto_cleans_inactive_bitrix24_routes_without_dialogs(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $profileId = DB::table('bitrix24_profiles')->insertGetId([
            'portal_domain' => 'stagecrm.example',
            'profile_key' => 'staging',
            'profile_type' => 'full_live',
            'display_name' => 'Staging',
            'callback_base_url' => 'https://example.test/callbacks/bitrix24',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profileId,
            'channel_id' => $channel->id,
            'portal_domain' => 'stagecrm.example',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abc_telegram',
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_INACTIVE,
        ]);

        $this->assertSame([], $this->channelDeleteBlockers($channel));
        $this->assertStringContainsString(
            'Неактивные Bitrix24-маршруты без диалогов будут очищены автоматически: 1',
            $this->channelDeleteModalDescription($channel),
        );

        $this->deleteAutoCleanableBitrix24Routes($channel);

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'id' => $route->id,
        ]);
    }

    public function test_admin_can_enable_needs_discovery_scenario_for_max_channel(): void
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
                'scenario_codes' => ['needs_discovery'],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'channel_id' => $channel->id,
            'scenario_code' => 'needs_discovery',
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

    public function test_recent_messages_renderer_shows_no_rule_skip_as_business_auto_reply_status(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'name' => 'Telegram Account No Rule',
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'ext-no-rule',
        ]);

        $message = Message::query()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-account-no-rule-901',
            'external_chat_id' => 'chat-no-rule-901',
            'external_message_id' => 'msg-no-rule-901',
            'text' => 'Нет подходящего правила',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => Carbon::create(2026, 4, 23, 13, 0, 0),
            'auto_reply_sent_at' => null,
        ]);

        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'bot.reply_skipped_no_rule',
            'message' => 'Автоответ не отправлен: правило не найдено.',
            'context' => [
                'message_id' => $message->id,
                'provider_event_key' => $message->provider_event_key,
                'auto_reply_source' => 'skipped_no_rule',
            ],
            'created_at' => Carbon::create(2026, 4, 23, 13, 0, 1),
        ]);

        $modalData = ChannelResource::buildChannelViewModalData($channel);

        $this->assertSame('Автоответ пропущен: правило не найдено', $modalData['latestMessageTables'][3][1]['value']);
        $this->assertSame('warning', $modalData['latestMessageTables'][3][1]['tone']);

        $recentMessagesRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentSavedMessages');
        $recentMessagesRenderer->setAccessible(true);

        $recentMessagesHtml = $recentMessagesRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Статус: Автоответ пропущен: правило не найдено', $recentMessagesHtml);
        $this->assertStringNotContainsString('Статус: Ответ еще не отправлен', $recentMessagesHtml);

        $recentActivityRenderer = new ReflectionMethod(ChannelResource::class, 'renderRecentActivityLogs');
        $recentActivityRenderer->setAccessible(true);

        $recentActivityHtml = $recentActivityRenderer->invoke(null, $channel)->toHtml();

        $this->assertStringContainsString('Автоответ пропущен', $recentActivityHtml);
        $this->assertStringContainsString('Автоответ не отправлен: правило не найдено.', $recentActivityHtml);
        $this->assertStringNotContainsString('Ошибка ответа', $recentActivityHtml);
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

    /**
     * @return list<string>
     */
    private function channelDeleteBlockers(Channel $channel): array
    {
        $method = new ReflectionMethod(ChannelResource::class, 'channelDeleteBlockers');
        $method->setAccessible(true);

        return $method->invoke(null, $channel);
    }

    private function channelDeleteModalDescription(Channel $channel): string
    {
        $method = new ReflectionMethod(ChannelResource::class, 'channelDeleteModalDescription');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $channel);
    }

    private function deleteAutoCleanableBitrix24Routes(Channel $channel): void
    {
        $method = new ReflectionMethod(ChannelResource::class, 'deleteAutoCleanableBitrix24Routes');
        $method->setAccessible(true);

        $method->invoke(null, $channel);
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
