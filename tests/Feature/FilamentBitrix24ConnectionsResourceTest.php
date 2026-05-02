<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use App\Filament\Resources\Bitrix24Connections\Pages\ListBitrix24Connections;
use App\Filament\Resources\Bitrix24Connections\Pages\ViewBitrix24Connection;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Channel;
use App\Models\User;
use App\Services\Bitrix24\Bitrix24ApiClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

        $this->actingAs($admin)
            ->get(Bitrix24ConnectionResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Bitrix24')
            ->assertSee($connection->portal_domain);

        Livewire::actingAs($admin)
            ->test(ListBitrix24Connections::class)
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
            ->assertSee('crm.example.test')
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
            'connector_code' => 'abrikosoff_telegram',
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
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abrikosoff_telegram')
            ->set("openLineRouteForms.{$channel->id}.line_id", 'line-editable')
            ->set("openLineRouteForms.{$channel->id}.source_id", 'source-editable')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $this->assertDatabaseHas('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => 'crm.edit.test',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => 'line-editable',
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
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => 'shared-line',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$secondChannel->id}.status", Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->set("openLineRouteForms.{$secondChannel->id}.connector_code", 'abrikosoff_max')
            ->set("openLineRouteForms.{$secondChannel->id}.line_id", 'shared-line')
            ->call('saveOpenLineRoute', $secondChannel->id)
            ->assertSet('openLineRouteErrorMessage', 'Открытая линия уже занята другим рабочим маршрутом.');

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'channel_id' => $secondChannel->id,
            'line_owner_key' => 'crm.conflict.test#shared-line',
        ]);
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
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abrikosoff_telegram_account')
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
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.add'
                    && $usedConnection->is($connection)
                    && data_get($params, 'PARAMS.LINE_NAME') === sprintf('Abrikosoff / dev-german-main / #%d Локальный бот', $channel->id)
                    && data_get($params, 'PARAMS.CRM_SOURCE') === 'ABC_TELEGRAM_DEV_GERMAN_MAIN')
                ->andReturn($this->bitrixResponse(true, 'line-777'));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['NAME'] ?? null) === 'Герман-4 ABC Telegram bot'
                    && ($params['COMMENT'] ?? null) === 'Настройки канала Герман-4 ABC Telegram bot')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['CONNECTOR'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-777'
                    && data_get($params, 'DATA.ID') === 'channel:'.$channel->id
                    && data_get($params, 'DATA.NAME') === 'Локальный бот')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.activate'
                    && $usedConnection->is($connection)
                    && ($params['CONNECTOR'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-777'
                    && ($params['ACTIVE'] ?? null) === '1')
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
            'line_owner_key' => 'stagecrm.fvds.ru#line-777',
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => null,
            'created_by_user_id' => $superadmin->id,
            'updated_by_user_id' => $superadmin->id,
        ]);

        $profile->refresh();

        $this->assertSame('abc_telegram_dev_german_main', $profile->telegram_connector_code);
        $this->assertSame('line-777', $profile->telegram_line_id);
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
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.add'
                    && $usedConnection->is($connection)
                    && data_get($params, 'PARAMS.LINE_NAME') === sprintf('Abrikosoff / dev-german-main / #%d MAX локалка', $channel->id)
                    && data_get($params, 'PARAMS.CRM_SOURCE') === 'ABC_MAX_DEV_GERMAN_MAIN')
                ->andReturn($this->bitrixResponse(true, 'line-max'));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_max_dev_german_main'
                    && ($params['NAME'] ?? null) === 'Герман-4 ABC MAX bot'
                    && ($params['COMMENT'] ?? null) === 'Настройки канала Герман-4 ABC MAX bot')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['CONNECTOR'] ?? null) === 'abc_max_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-max'
                    && data_get($params, 'DATA.ID') === 'channel:'.$channel->id
                    && data_get($params, 'DATA.NAME') === 'MAX локалка')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.activate'
                    && $usedConnection->is($connection)
                    && ($params['CONNECTOR'] ?? null) === 'abc_max_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-max'
                    && ($params['ACTIVE'] ?? null) === '1')
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
            'line_owner_key' => 'stagecrm.fvds.ru#line-max',
            'source_id' => 'ABC_MAX_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => null,
            'created_by_user_id' => $superadmin->id,
            'updated_by_user_id' => $superadmin->id,
        ]);

        $profile->refresh();

        $this->assertSame('abc_max_dev_german_main', $profile->max_connector_code);
        $this->assertSame('line-max', $profile->max_line_id);
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

    public function test_auto_setup_refreshes_default_application_name_before_existing_connector_registration(): void
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

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'app.info'
                    && $params === []
                    && $usedConnection->is($connection))
                ->andReturn($this->bitrixResponse(true, ['NAME' => 'Герман-4']));

            $mock->shouldReceive('call')
                ->never()
                ->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['NAME'] ?? null) === 'Герман-4 ABC Telegram bot')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imconnector.connector.data.set'
                    && ($params['CONNECTOR'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imconnector.activate'
                    && ($params['CONNECTOR'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-existing')
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

    public function test_auto_setup_uses_configured_application_name_when_connection_has_generic_name(): void
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

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->never()
                ->with('app.info', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->never()
                ->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['NAME'] ?? null) === 'Герман-4 ABC Telegram bot'
                    && ($params['COMMENT'] ?? null) === 'Настройки канала Герман-4 ABC Telegram bot')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imconnector.connector.data.set'
                    && ($params['CONNECTOR'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params): bool => $method === 'imconnector.activate'
                    && ($params['CONNECTOR'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['LINE'] ?? null) === 'line-existing')
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

        $cards = collect(Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
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

        $cards = collect(Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
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

        $cards = collect(Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
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
        return Bitrix24Profile::query()->create(array_merge([
            'portal_domain' => 'crm.default.test',
            'profile_key' => 'staging',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://project.example.test/'.str()->uuid(),
        ], $overrides));
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
