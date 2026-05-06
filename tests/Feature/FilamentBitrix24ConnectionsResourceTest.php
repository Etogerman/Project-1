<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use App\Filament\Resources\Bitrix24Connections\Pages\ListBitrix24Connections;
use App\Filament\Resources\Bitrix24Connections\Pages\ViewBitrix24Connection;
use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\User;
use App\Services\Bitrix24\Bitrix24ApiClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentBitrix24ConnectionsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_bitrix24_connections_index_page(): void
    {
        $admin = $this->makeAdmin();
        $connection = $this->makeConnection();

        $this->assertSame('Настройки', Bitrix24ConnectionResource::getNavigationGroup());

        $this->actingAs($admin)
            ->get(Bitrix24ConnectionResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Настройки Bitrix24')
            ->assertSee('Подключение, маршруты открытых линий, callback-и и sync-логи.')
            ->assertSee('Открыть настройки')
            ->assertSee($connection->portal_domain);

        Livewire::actingAs($admin)
            ->test(ListBitrix24Connections::class)
            ->assertSee('Настройки Bitrix24')
            ->assertSee('Открыть настройки')
            ->assertCanSeeTableRecords([$connection]);
    }

    public function test_non_admin_cannot_open_bitrix24_connections_pages(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $connection = $this->makeConnection();

        $this->actingAs($user)
            ->get(Bitrix24ConnectionResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(Bitrix24ConnectionResource::getUrl('view', ['record' => $connection]))
            ->assertForbidden();
    }

    public function test_employee_bitrix24_access_is_controlled_by_role_permission_matrix(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $connection = $this->makeConnection([
            'portal_domain' => 'crm.employee.test',
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.view', true);

        $this->actingAs($employee)
            ->get(Bitrix24ConnectionResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Bitrix24')
            ->assertSee('crm.employee.test');

        $this->actingAs($employee)
            ->get(Bitrix24ConnectionResource::getUrl('view', ['record' => $connection]))
            ->assertOk()
            ->assertSee('crm.employee.test');

        $this->assertTrue(Gate::forUser($employee)->allows('viewAny', Bitrix24Connection::class));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $connection));
        $this->assertFalse(Gate::forUser($employee)->allows('create', Bitrix24Connection::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $connection));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $connection));

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.edit', true);
        $employee = $employee->fresh();

        $this->assertTrue(Gate::forUser($employee)->allows('update', $connection));
    }

    public function test_admin_can_open_bitrix24_connection_view_and_see_diagnostics(): void
    {
        $admin = $this->makeAdmin();
        $connection = $this->makeConnection([
            'portal_domain' => 'crm.example.test',
            'last_error_message' => 'Token refresh failed.',
        ]);

        $this->makeWebhookEvent($connection, [
            'event_name' => 'ONCRMCONTACTUPDATE',
            'processing_status' => Bitrix24WebhookEvent::STATUS_FAILED,
            'failure_reason' => 'Callback processing failed.',
        ]);

        $this->makeSyncLog($connection, [
            'operation' => 'contact.update',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'error_message' => 'Bitrix API returned 500.',
        ]);

        $this->actingAs($admin)
            ->get(Bitrix24ConnectionResource::getUrl('view', ['record' => $connection]))
            ->assertOk()
            ->assertSee('Настройки Bitrix24')
            ->assertSee('crm.example.test')
            ->assertSee('Очередь и worker')
            ->assertSee('Очередь без задержек')
            ->assertSee('Маршруты открытых линий')
            ->assertSee('Token refresh failed.')
            ->assertSee('Последние callback-и')
            ->assertSee('ONCRMCONTACTUPDATE')
            ->assertSee('Callback processing failed.')
            ->assertSee('Последние sync-логи')
            ->assertSee('contact.update')
            ->assertSee('Bitrix API returned 500.')
            ->assertDontSee('secret-access-token')
            ->assertDontSee('secret-refresh-token');
    }

    public function test_bitrix24_connection_view_warns_when_openlines_queue_is_stalled(): void
    {
        $admin = $this->makeAdmin();
        $connection = $this->makeConnection([
            'portal_domain' => 'crm.queue.test',
        ]);
        $queuedAt = now()->subMinute();

        $event = $this->makeWebhookEvent($connection, [
            'callback_type' => Bitrix24WebhookEvent::TYPE_OPENLINES,
            'event_name' => 'OnSendMessageCustom',
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);

        DB::table($event->getTable())
            ->where('id', $event->id)
            ->update([
                'created_at' => $queuedAt->copy()->utc()->toIso8601String(),
                'updated_at' => $queuedAt->copy()->utc()->toIso8601String(),
            ]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessBitrix24WebhookEventJob'], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $queuedAt->timestamp,
            'created_at' => $queuedAt->timestamp,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->assertSee('Очередь и worker')
            ->assertSee('Очередь не обрабатывается')
            ->assertSee('Callback-и Open Lines')
            ->assertSee('Задачи к обработке');

        $health = $component->instance()->getQueueHealthCard();

        $this->assertSame('danger', $health['tone']);
        $this->assertSame('Очередь не обрабатывается', $health['label']);
        $this->assertStringStartsWith('1 · 1 мин', $health['details'][1]['value']);
        $this->assertStringStartsWith('1 · 1 мин', $health['details'][2]['value']);
    }

    public function test_bitrix24_connection_view_builds_bitrix_box_config_snippet(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'staging',
            'callback_base_url' => 'https://local-ngrok.example.test',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);
        $telegram = Channel::factory()->create([
            'name' => 'Локальный Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $max = Channel::factory()->create([
            'name' => 'Локальный MAX',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $callbackOwner = $profile->callbackOwners()->firstOrFail();

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $telegram->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($telegram),
            'connector_code' => 'abc_telegram',
            'line_id' => '9',
            'line_name' => '9 Локальный бот телеграм - Герман-1',
            'callback_owner_id' => $callbackOwner->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $max->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($max),
            'connector_code' => 'abc_max',
            'line_id' => '8',
            'line_name' => '8 Локальный бот MAX - Герман-1',
            'callback_owner_id' => $callbackOwner->id,
            'source_id' => 'ABC_MAX',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->assertSee('Настройка Bitrix-box для админа')
            ->assertSee('Показать PHP snippet')
            ->assertSee('local/php_interface/include/abrikosoff_openlines/config.php')
            ->assertSee('Не удаляйте старые `abrikosoff_*`');

        $snippet = $component->instance()->getBitrixBoxConfigSnippet();

        $this->assertIsString($snippet);
        $this->assertStringNotContainsString('openlines_callback_url', $snippet);
        $this->assertStringContainsString("'connectors' =>", $snippet);
        $this->assertStringContainsString("'abc_telegram'", $snippet);
        $this->assertStringContainsString("'name' => 'ABC Telegram'", $snippet);
        $this->assertStringContainsString("'component' => 'abrikosoff:imconnector.telegram'", $snippet);
        $this->assertStringContainsString("'line_id' => '9'", $snippet);
        $this->assertStringContainsString("'line_name' => '9 Локальный бот телеграм - Герман-1'", $snippet);
        $this->assertStringContainsString("'color' => '#27A7E7'", $snippet);
        $this->assertStringContainsString("'label' => 'TG'", $snippet);
        $this->assertStringContainsString('9 =>', $snippet);
        $this->assertStringContainsString("'abc_max'", $snippet);
        $this->assertStringContainsString("'name' => 'ABC MAX'", $snippet);
        $this->assertStringContainsString("'component' => 'abrikosoff:imconnector.max'", $snippet);
        $this->assertStringContainsString("'line_id' => '8'", $snippet);
        $this->assertStringContainsString("'line_name' => '8 Локальный бот MAX - Герман-1'", $snippet);
        $this->assertStringContainsString("'color' => '#7B4DFF'", $snippet);
        $this->assertStringContainsString("'label' => 'MX'", $snippet);
        $this->assertStringContainsString('8 =>', $snippet);
        $this->assertStringContainsString("'owner_profile_key' => 'local-1'", $snippet);
        $this->assertStringContainsString("'owner_callback_base_url' => 'https://local-ngrok.example.test'", $snippet);
    }

    public function test_callback_owner_cannot_reuse_another_profile_callback_url(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.owner.test',
            'callback_base_url' => 'https://owner-local.example.test',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);
        $foreignProfile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.foreign.test',
            'profile_key' => 'foreign',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Foreign',
            'callback_base_url' => 'https://foreign-profile.example.test',
        ]);
        $owner = $profile->callbackOwners()->firstOrFail();

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("callbackOwnerForms.{$owner->id}.callback_base_url", $foreignProfile->callback_base_url)
            ->call('saveCallbackOwner', $owner->id)
            ->assertSet('callbackOwnersErrorMessage', 'Такой callback URL уже используется другим профилем Bitrix24.');

        $this->assertDatabaseHas('bitrix24_callback_owners', [
            'id' => $owner->id,
            'callback_base_url' => 'https://owner-local.example.test',
        ]);
        $this->assertDatabaseMissing('bitrix24_callback_owners', [
            'id' => $owner->id,
            'callback_base_url' => 'https://foreign-profile.example.test',
        ]);
    }

    public function test_view_page_filters_webhook_events_and_sync_logs(): void
    {
        $admin = $this->makeAdmin();
        $connection = $this->makeConnection();

        $this->makeWebhookEvent($connection, [
            'event_name' => 'Processed event',
            'processing_status' => Bitrix24WebhookEvent::STATUS_PROCESSED,
            'failure_reason' => null,
        ]);
        $this->makeWebhookEvent($connection, [
            'event_name' => 'Failed event',
            'processing_status' => Bitrix24WebhookEvent::STATUS_FAILED,
            'failure_reason' => 'Failed for diagnostics.',
        ]);

        $this->makeSyncLog($connection, [
            'operation' => 'sync.success',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'error_message' => null,
        ]);
        $this->makeSyncLog($connection, [
            'operation' => 'sync.failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'error_message' => 'Sync failed for diagnostics.',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set('webhookEventProcessingStatusFilter', Bitrix24WebhookEvent::STATUS_FAILED)
            ->assertSee('Failed event')
            ->assertDontSee('Processed event')
            ->set('syncLogStatusFilter', Bitrix24SyncLog::STATUS_FAILED)
            ->assertSee('sync.failed')
            ->assertDontSee('sync.success');
    }

    public function test_view_page_shows_open_line_routes_and_channels_without_route(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile();
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);
        $firstChannel = Channel::factory()->create([
            'name' => 'Первый Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        Channel::factory()->create([
            'name' => 'Второй Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $firstChannel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($firstChannel),
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-one',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->get(Bitrix24ConnectionResource::getUrl('view', ['record' => $connection]))
            ->assertOk()
            ->assertSee('Маршруты открытых линий')
            ->assertSee('Первый Telegram')
            ->assertSee('Второй Telegram')
            ->assertSee('line-one')
            ->assertSee('Требует настройки')
            ->assertSee('Сохранить');
    }

    public function test_employee_with_view_only_can_see_open_line_routes_but_cannot_edit_them(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.view-only.test',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);

        Channel::factory()->create([
            'name' => 'View only channel',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.edit', false);

        Livewire::actingAs($employee)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->assertSee('View only channel')
            ->assertSee('Для изменения маршрутов нужно право bitrix24.edit.')
            ->assertDontSee('Сохранить маршрут');
    }

    public function test_employee_with_edit_permission_can_create_open_line_route(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.edit.test',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Editable Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.edit', true);

        Livewire::actingAs($employee)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.status", Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_telegram')
            ->set("openLineRouteForms.{$channel->id}.line_id", 'line-editable')
            ->set("openLineRouteForms.{$channel->id}.line_name", '9 Локальный бот телеграм - Герман-1')
            ->set("openLineRouteForms.{$channel->id}.source_id", 'source-editable')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => 'crm.edit.test',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-editable',
            'line_name' => '9 Локальный бот телеграм - Герман-1',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'line_owner_key' => 'crm.edit.test#line-editable',
            'source_id' => 'source-editable',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'created_by_user_id' => $employee->id,
            'updated_by_user_id' => $employee->id,
        ]);
    }

    public function test_open_line_route_save_blocks_active_line_conflict(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.conflict.test',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);
        $firstChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $secondChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $firstChannel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($firstChannel),
            'connector_code' => 'abc_telegram',
            'line_id' => 'shared-line',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$secondChannel->id}.status", Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->set("openLineRouteForms.{$secondChannel->id}.connector_code", 'abc_max')
            ->set("openLineRouteForms.{$secondChannel->id}.line_id", 'shared-line')
            ->call('saveOpenLineRoute', $secondChannel->id)
            ->assertSet('openLineRouteErrorMessage', 'Открытая линия уже занята другим рабочим маршрутом.');

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'channel_id' => $secondChannel->id,
            'line_owner_key' => 'crm.conflict.test#shared-line',
        ]);
    }

    public function test_admin_can_see_and_reset_stale_open_line_dialog_bindings(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.stale.test',
            'telegram_connector_code' => 'abc_telegram',
            'telegram_source_id' => 'ABC_TELEGRAM',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram',
            'line_id' => '10',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $dialog = Dialog::factory()->create([
            'current_contact_identity_id' => $identity->id,
            'bitrix24_open_line_route_id' => $route->id,
            'bitrix24_open_line_user_code_override' => 'abc_telegram|9|abrikosoff-dialog:1|26',
            'bitrix24_open_line_resolved_chat_id_override' => '33',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()]);

        $cards = collect($component->instance()->getOpenLineRouteCards())->keyBy('channel_id');
        $this->assertSame('Устаревших: 1', $cards->get($channel->id)['binding_diagnostic_label']);

        $component
            ->call('resetStaleOpenLineBindings', $channel->id)
            ->assertHasNoErrors();

        $dialog->refresh();
        $this->assertNull($dialog->bitrix24_open_line_user_code_override);
        $this->assertNull($dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNull($dialog->bitrix24_open_line_binding_verified_at);
    }

    public function test_telegram_account_route_cannot_be_saved_as_active_in_first_slice(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.account.test',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.status", Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_telegram_account')
            ->set("openLineRouteForms.{$channel->id}.line_id", 'account-line')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', 'Telegram account пока нельзя сделать рабочим маршрутом открытых линий.');

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'channel_id' => $channel->id,
            'line_id' => 'account-line',
        ]);
    }

    public function test_superadmin_can_auto_setup_telegram_bot_open_line_route(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'telegram_connector_code' => 'abc_telegram_dev_german_main',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Герман-4',
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Локальный бот',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection, $channel): void {
            $mock->shouldNotReceive('call')->with('app.info', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.add'
                    && $usedConnection->is($connection)
                    && data_get($params, 'PARAMS.LINE_NAME') === 'Локальный бот'
                    && data_get($params, 'PARAMS.CRM_SOURCE') === 'ABC_TELEGRAM_DEV_GERMAN_MAIN')
                ->andReturn($this->bitrixResponse(true, 'line-777'));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(function (string $method, array $params, Bitrix24Connection $usedConnection) use ($connection): bool {
                    $icon = $this->decodedSvgDataImage(data_get($params, 'ICON.DATA_IMAGE'));

                    return $method === 'imconnector.register'
                        && $usedConnection->is($connection)
                        && ($params['ID'] ?? null) === 'abc_telegram_dev_german_main'
                        && ($params['NAME'] ?? null) === 'ABC Telegram'
                        && ($params['COMMENT'] ?? null) === 'Настройки канала ABC Telegram'
                        && data_get($params, 'ICON.COLOR') === '#2AABEE'
                        && data_get($params, 'ICON_DISABLED.COLOR') === '#99ADB3'
                        && str_contains($icon, '<path')
                        && ! str_contains($icon, 'MAX');
                })
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['CONNECTOR'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-777'
                    && data_get($params, 'DATA.ID') === 'channel:'.$channel->id.':connector:abc_telegram_dev_german_main:line:line-777'
                    && data_get($params, 'DATA.NAME') === 'ABC Telegram')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.activate'
                    && $usedConnection->is($connection)
                    && ($params['CONNECTOR'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-777'
                    && ($params['ACTIVE'] ?? null) === '1')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['CONFIG_ID'] ?? null) === 'line-777'
                    && data_get($params, 'PARAMS.CRM_SOURCE') === 'ABC_TELEGRAM_DEV_GERMAN_MAIN')
                ->andReturn($this->bitrixResponse(true, true));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abc_telegram_dev_german_main',
            'line_id' => 'line-777',
            'line_name' => 'Локальный бот',
            'line_owner_key' => 'stagecrm.fvds.ru#line-777',
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => null,
            'created_by_user_id' => $superadmin->id,
            'updated_by_user_id' => $superadmin->id,
        ]);

        $profile->refresh();

        $this->assertSame('abc_telegram_dev_german_main', $profile->telegram_connector_code);
        $this->assertNull($profile->telegram_line_id);
        $this->assertSame('ABC_TELEGRAM_DEV_GERMAN_MAIN', $profile->telegram_source_id);
    }

    public function test_superadmin_can_auto_setup_max_open_line_route(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'max_connector_code' => 'abc_max_dev_german_main',
            'max_source_id' => 'ABC_MAX_DEV_GERMAN_MAIN',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Герман-4',
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX локалка',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'max-token'],
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection, $channel): void {
            $mock->shouldNotReceive('call')->with('app.info', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.add'
                    && $usedConnection->is($connection)
                    && data_get($params, 'PARAMS.LINE_NAME') === 'MAX локалка'
                    && data_get($params, 'PARAMS.CRM_SOURCE') === 'ABC_MAX_DEV_GERMAN_MAIN')
                ->andReturn($this->bitrixResponse(true, 'line-max'));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(function (string $method, array $params, Bitrix24Connection $usedConnection) use ($connection): bool {
                    $icon = $this->decodedSvgDataImage(data_get($params, 'ICON.DATA_IMAGE'));

                    return $method === 'imconnector.register'
                        && $usedConnection->is($connection)
                        && ($params['ID'] ?? null) === 'abc_max_dev_german_main'
                        && ($params['NAME'] ?? null) === 'ABC MAX'
                        && ($params['COMMENT'] ?? null) === 'Настройки канала ABC MAX'
                        && data_get($params, 'ICON.COLOR') === '#7C3AED'
                        && data_get($params, 'ICON_DISABLED.COLOR') === '#99ADB3'
                        && str_contains($icon, '>MAX<');
                })
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['CONNECTOR'] ?? null) === 'abc_max_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-max'
                    && data_get($params, 'DATA.ID') === 'channel:'.$channel->id.':connector:abc_max_dev_german_main:line:line-max'
                    && data_get($params, 'DATA.NAME') === 'ABC MAX')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.activate'
                    && $usedConnection->is($connection)
                    && ($params['CONNECTOR'] ?? null) === 'abc_max_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-max'
                    && ($params['ACTIVE'] ?? null) === '1')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['CONFIG_ID'] ?? null) === 'line-max'
                    && data_get($params, 'PARAMS.CRM_SOURCE') === 'ABC_MAX_DEV_GERMAN_MAIN')
                ->andReturn($this->bitrixResponse(true, true));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            'connector_code' => 'abc_max_dev_german_main',
            'line_id' => 'line-max',
            'line_name' => 'MAX локалка',
            'line_owner_key' => 'stagecrm.fvds.ru#line-max',
            'source_id' => 'ABC_MAX_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => null,
            'created_by_user_id' => $superadmin->id,
            'updated_by_user_id' => $superadmin->id,
        ]);

        $profile->refresh();

        $this->assertSame('abc_max_dev_german_main', $profile->max_connector_code);
        $this->assertNull($profile->max_line_id);
        $this->assertSame('ABC_MAX_DEV_GERMAN_MAIN', $profile->max_source_id);
    }

    public function test_auto_setup_stores_misconfigured_route_when_connector_registration_fails(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'telegram_connector_code' => 'abc_telegram_dev_german_main',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Герман-4',
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imopenlines.config.add')
                ->andReturn($this->bitrixResponse(true, 'line-partial'));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturn($this->bitrixResponse(true, [
                    'result' => false,
                    'error_description' => 'Connector rejected',
                ]));

            $mock->shouldReceive('call')
                ->withAnyArgs()
                ->zeroOrMoreTimes()
                ->andReturn($this->bitrixResponse(false, null, 'unexpected', 'Unexpected call.'));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', 'Connector rejected');

        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'line_id' => 'line-partial',
            'line_owner_key' => null,
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
            'last_error_message' => 'Connector rejected',
        ]);

        $this->assertNull($profile->refresh()->telegram_line_id);
    }

    public function test_auto_setup_reuses_existing_misconfigured_line_id(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'telegram_connector_code' => 'abc_telegram_dev_german_main',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Герман-4',
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram_dev_german_main',
            'line_id' => 'line-existing',
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldNotReceive('call')->with('app.info', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->never()
                ->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['LINE'] ?? null) === 'line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imconnector.activate'
                    && ($params['LINE'] ?? null) === 'line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['CONFIG_ID'] ?? null) === 'line-existing'
                    && data_get($params, 'PARAMS.CRM_SOURCE') === 'ABC_TELEGRAM_DEV_GERMAN_MAIN'
                    && ! array_key_exists('LINE_NAME', $params['PARAMS'] ?? []))
                ->andReturn($this->bitrixResponse(true, true));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $this->assertSame(1, Bitrix24OpenLineRoute::query()->count());
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'channel_id' => $channel->id,
            'line_id' => 'line-existing',
            'line_owner_key' => 'stagecrm.fvds.ru#line-existing',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
    }

    public function test_existing_active_route_button_refreshes_connector_without_rebinding_open_line(): void
    {
        config()->set('bitrix24.application.name', 'Герман-4');

        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'telegram_connector_code' => 'abc_telegram_dev_german_main',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Abrikosoff Connector',
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Локальный бот',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram_dev_german_main',
            'line_id' => 'line-existing',
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($channel, $connection): void {
            $mock->shouldNotReceive('call')->with('app.info', \Mockery::any(), \Mockery::any());
            $mock->shouldReceive('call')
                ->never()
                ->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['NAME'] ?? null) === 'ABC Telegram')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['LINE'] ?? null) === 'line-existing'
                    && data_get($params, 'DATA.ID') === 'channel:'.$channel->id.':connector:abc_telegram_dev_german_main:line:line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.activate'
                    && $usedConnection->is($connection)
                    && ($params['LINE'] ?? null) === 'line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['CONFIG_ID'] ?? null) === 'line-existing'
                    && ! array_key_exists('LINE_NAME', $params['PARAMS'] ?? []))
                ->andReturn($this->bitrixResponse(true, true));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $this->assertSame('Герман-4', $connection->refresh()->application_name);
        $this->assertSame(1, Bitrix24OpenLineRoute::query()->count());
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'channel_id' => $channel->id,
            'line_id' => 'line-existing',
            'line_owner_key' => 'stagecrm.fvds.ru#line-existing',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
    }

    public function test_existing_active_route_button_keeps_route_identity_when_connection_has_generic_name(): void
    {
        config()->set('bitrix24.application.name', 'Герман-4');

        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'telegram_connector_code' => 'abc_telegram_dev_german_main',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Abrikosoff Connector',
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Локальный бот',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram_dev_german_main',
            'line_id' => 'line-existing',
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($channel, $connection): void {
            $mock->shouldNotReceive('call')->with('app.info', \Mockery::any(), \Mockery::any());
            $mock->shouldReceive('call')
                ->never()
                ->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['NAME'] ?? null) === 'ABC Telegram'
                    && ($params['COMMENT'] ?? null) === 'Настройки канала ABC Telegram')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['LINE'] ?? null) === 'line-existing'
                    && data_get($params, 'DATA.ID') === 'channel:'.$channel->id.':connector:abc_telegram_dev_german_main:line:line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.activate'
                    && $usedConnection->is($connection)
                    && ($params['LINE'] ?? null) === 'line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['CONFIG_ID'] ?? null) === 'line-existing'
                    && ! array_key_exists('LINE_NAME', $params['PARAMS'] ?? []))
                ->andReturn($this->bitrixResponse(true, true));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $this->assertSame('Герман-4', $connection->refresh()->application_name);
        $this->assertSame(1, Bitrix24OpenLineRoute::query()->count());
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'channel_id' => $channel->id,
            'line_id' => 'line-existing',
            'line_owner_key' => 'stagecrm.fvds.ru#line-existing',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
    }

    public function test_superadmin_can_save_application_name_and_refresh_existing_open_line_connectors(): void
    {
        config()->set('bitrix24.application.name', 'Configured Name');

        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
            'telegram_connector_code' => 'abc_telegram_dev_german_main',
            'telegram_source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'max_connector_code' => 'abc_max_dev_german_main',
            'max_source_id' => 'ABC_MAX_DEV_GERMAN_MAIN',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Герман-4',
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $telegram = Channel::factory()->create([
            'name' => 'Локальный бот',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $max = Channel::factory()->create([
            'name' => 'MAX локально',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'max-token'],
        ]);

        foreach ([
            [$telegram, 'abc_telegram_dev_german_main', 'line-telegram', 'ABC_TELEGRAM_DEV_GERMAN_MAIN'],
            [$max, 'abc_max_dev_german_main', 'line-max', 'ABC_MAX_DEV_GERMAN_MAIN'],
        ] as [$channel, $connectorCode, $lineId, $sourceId]) {
            Bitrix24OpenLineRoute::query()->create([
                'bitrix24_profile_id' => $profile->id,
                'channel_id' => $channel->id,
                'portal_domain' => $profile->portal_domain,
                'profile_key' => $profile->profile_key,
                'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'source_id' => $sourceId,
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        }

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldNotReceive('call')->with('app.info', \Mockery::any(), \Mockery::any());
            $mock->shouldReceive('call')
                ->never()
                ->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());
            $mock->shouldReceive('call')
                ->twice()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && in_array($params['LINE'] ?? null, ['line-telegram', 'line-max'], true))
                ->andReturn($this->bitrixResponse(true, true));
            $mock->shouldReceive('call')
                ->twice()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.activate'
                    && $usedConnection->is($connection)
                    && in_array($params['LINE'] ?? null, ['line-telegram', 'line-max'], true))
                ->andReturn($this->bitrixResponse(true, true));
            $mock->shouldReceive('call')
                ->twice()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && in_array($params['CONFIG_ID'] ?? null, ['line-telegram', 'line-max'], true)
                    && ! array_key_exists('LINE_NAME', $params['PARAMS'] ?? []))
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['NAME'] ?? null) === 'ABC Telegram'
                    && ($params['ICON']['COLOR'] ?? null) === '#2AABEE')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_max_dev_german_main'
                    && ($params['NAME'] ?? null) === 'ABC MAX'
                    && ($params['ICON']['COLOR'] ?? null) === '#7C3AED')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set('applicationNameForm.application_name', ' Новое имя ')
            ->call('saveApplicationName')
            ->assertSet('applicationNameForm.application_name', 'Новое имя');

        $this->assertSame('Новое имя', $connection->refresh()->application_name);
        $this->assertSame(2, Bitrix24OpenLineRoute::query()->count());
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'channel_id' => $telegram->id,
            'connector_code' => 'abc_telegram_dev_german_main',
            'line_id' => 'line-telegram',
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'channel_id' => $max->id,
            'connector_code' => 'abc_max_dev_german_main',
            'line_id' => 'line-max',
            'source_id' => 'ABC_MAX_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
    }

    public function test_superadmin_can_save_profile_crm_schema_settings(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.schema.test',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->assertSee('CRM поля Bitrix24')
            ->assertSee('CRM значения enum')
            ->set('profileSettingsForm.crm_field_name_source', ' UF_CRM_ABC_NAME_SOURCE ')
            ->set('profileSettingsForm.crm_field_gender', ' UF_CRM_ABC_GENDER ')
            ->set('profileSettingsForm.crm_field_contact_id', ' UF_CRM_ABC_CONTACT_ID ')
            ->set('profileSettingsForm.crm_name_source_self_reported_id', '9002')
            ->set('profileSettingsForm.crm_gender_male_id', '9001')
            ->call('saveProfileSettings')
            ->assertSet('profileSettingsErrorMessage', null)
            ->assertSet('profileSettingsForm.crm_field_name_source', 'UF_CRM_ABC_NAME_SOURCE')
            ->assertSet('profileSettingsForm.crm_field_gender', 'UF_CRM_ABC_GENDER')
            ->assertSet('profileSettingsForm.crm_field_contact_id', 'UF_CRM_ABC_CONTACT_ID')
            ->assertSet('profileSettingsForm.crm_name_source_self_reported_id', '9002')
            ->assertSet('profileSettingsForm.crm_gender_male_id', '9001');

        $this->assertDatabaseHas('bitrix24_profiles', [
            'id' => $profile->id,
            'crm_field_name_source' => 'UF_CRM_ABC_NAME_SOURCE',
            'crm_field_gender' => 'UF_CRM_ABC_GENDER',
            'crm_field_contact_id' => 'UF_CRM_ABC_CONTACT_ID',
            'crm_name_source_self_reported_id' => 9002,
            'crm_gender_male_id' => 9001,
        ]);
    }

    public function test_employee_with_bitrix24_edit_cannot_save_application_name(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $connection = $this->makeConnection([
            'application_name' => 'Герман-4',
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.edit', true);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')->never();
        });

        $component = Livewire::actingAs($employee)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->assertSee('Герман-4')
            ->assertDontSee('Сохранить имя');

        $this->assertFalse($component->instance()->canEditApplicationName());
    }

    public function test_auto_setup_is_blocked_for_non_stagecrm_portal(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'other.example.test',
            'telegram_connector_code' => 'abc_telegram',
            'telegram_source_id' => 'ABC_TELEGRAM',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')->never();
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', 'Автонастройка ОЛ в первом срезе доступна только для stagecrm.fvds.ru.');
    }

    public function test_auto_setup_button_is_disabled_when_bitrix24_scopes_are_missing(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'telegram_connector_code' => 'abc_telegram',
            'telegram_source_id' => 'ABC_TELEGRAM',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'scope' => ['crm'],
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $component = Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()]);

        $cards = collect($component
            ->instance()
            ->getOpenLineRouteCards())
            ->keyBy('channel_id');

        $this->assertFalse($cards->get($channel->id)['auto_setup_enabled']);
        $this->assertSame('Не хватает прав приложения Bitrix24', $cards->get($channel->id)['auto_setup_reason']);
    }

    public function test_auto_setup_button_accepts_local_bitrix24_app_scope(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'telegram_connector_code' => 'abc_telegram',
            'telegram_source_id' => 'ABC_TELEGRAM',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'scope' => ['app'],
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $component = Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()]);

        $cards = collect($component
            ->instance()
            ->getOpenLineRouteCards())
            ->keyBy('channel_id');

        $this->assertTrue($cards->get($channel->id)['auto_setup_enabled']);
        $this->assertSame('', $cards->get($channel->id)['auto_setup_reason']);
    }

    public function test_auto_setup_button_is_visible_for_supported_bot_routes(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'telegram_connector_code' => 'abc_telegram',
            'telegram_source_id' => 'ABC_TELEGRAM',
            'max_connector_code' => 'abc_max',
            'max_source_id' => 'ABC_MAX',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'scope' => ['app'],
        ]);
        $maxBot = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'max-token'],
        ]);
        $telegramAccount = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $telegramBot = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $component = Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()]);

        $cards = collect($component
            ->instance()
            ->getOpenLineRouteCards())
            ->keyBy('channel_id');

        $this->assertTrue($cards->get($maxBot->id)['auto_setup_visible']);
        $this->assertTrue($cards->get($maxBot->id)['auto_setup_enabled']);
        $this->assertFalse($cards->get($telegramAccount->id)['auto_setup_visible']);
        $this->assertTrue($cards->get($telegramBot->id)['auto_setup_visible']);
        $this->assertTrue($cards->get($telegramBot->id)['auto_setup_enabled']);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_SUPERADMIN,
        ]);
    }

    private function decodedSvgDataImage(mixed $value): string
    {
        if (! is_string($value) || ! str_starts_with($value, 'data:image/svg+xml,')) {
            return '';
        }

        return rawurldecode(Str::after($value, 'data:image/svg+xml,'));
    }

    private function bitrixResponse(
        bool $successful,
        mixed $result,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): Bitrix24RestResponseData {
        return new Bitrix24RestResponseData(
            successful: $successful,
            httpStatus: $successful ? 200 : 400,
            result: $result,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            raw: [
                'result' => $result,
                'error' => $errorCode,
                'error_description' => $errorMessage,
            ],
            requestMethod: 'POST',
            restMethod: 'test.method',
            attemptedRefresh: false,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeConnection(array $overrides = []): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate(array_merge([
            'portal_domain' => 'crm.default.test',
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.app',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'secret-access-token',
            'refresh_token_encrypted' => 'secret-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm'],
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'installed_at' => now()->subHour(),
            'last_refreshed_at' => now()->subMinutes(30),
            'last_install_callback_at' => now()->subHour(),
            'last_events_callback_at' => now()->subMinutes(10),
            'last_openlines_callback_at' => now()->subMinutes(5),
            'last_error_at' => now()->subMinute(),
            'last_error_message' => 'Connection error.',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProfile(array $overrides = []): Bitrix24Profile
    {
        $profile = Bitrix24Profile::query()->create(array_merge([
            'portal_domain' => 'crm.default.test',
            'profile_key' => 'staging',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://project.example.test/'.str()->uuid(),
        ], $overrides));

        Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY,
            'display_name' => 'Локалка 1',
            'callback_base_url' => $profile->callback_base_url,
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeWebhookEvent(Bitrix24Connection $connection, array $overrides = []): Bitrix24WebhookEvent
    {
        return Bitrix24WebhookEvent::query()->create(array_merge([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
            'event_name' => 'ONCRMCONTACTADD',
            'member_id' => $connection->member_id,
            'application_token' => 'application-token',
            'portal_domain' => $connection->portal_domain,
            'payload_hash' => sha1((string) str()->uuid()),
            'payload' => ['event' => 'payload'],
            'headers' => ['Accept' => 'application/json'],
            'query' => ['source' => 'test'],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
            'processed_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'attempts' => 1,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSyncLog(Bitrix24Connection $connection, array $overrides = []): Bitrix24SyncLog
    {
        return Bitrix24SyncLog::query()->create(array_merge([
            'connection_id' => $connection->id,
            'direction' => Bitrix24SyncLog::DIRECTION_OUTBOUND,
            'operation' => 'contact.lookup',
            'entity_type' => 'contact',
            'entity_id' => '123',
            'request_payload' => ['request' => 'payload'],
            'response_payload' => ['response' => 'payload'],
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'http_status' => 200,
            'error_code' => null,
            'error_message' => null,
            'fingerprint' => sha1((string) str()->uuid()),
        ], $overrides));
    }

    private function setRolePermission(string $role, string $permissionKey, bool $granted): void
    {
        DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission_key', $permissionKey)
            ->update(['granted' => $granted]);
    }
}
