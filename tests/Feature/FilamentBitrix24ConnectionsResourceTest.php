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
use App\Services\Bitrix24\AutoSetupBitrix24OpenLineRouteAction;
use App\Services\Bitrix24\Bitrix24ApiClient;
use App\Services\Bitrix24\Bitrix24ApiException;
use App\Services\Bitrix24\Bitrix24AuthRefreshException;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryClient;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
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

    public function test_bitrix24_connection_queue_health_tracks_configured_bot_reply_queue(): void
    {
        config()->set('bots.auto_reply_queue', 'bot-replies');

        $admin = $this->makeAdmin();
        $connection = $this->makeConnection([
            'portal_domain' => 'crm.bot-replies-queue.test',
        ]);
        $queuedAt = now()->subMinute();

        DB::table('jobs')->insert([
            'queue' => 'bot-replies',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessAutoReplyJob'], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $queuedAt->timestamp,
            'created_at' => $queuedAt->timestamp,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()]);

        $health = $component->instance()->getQueueHealthCard();

        $this->assertSame('danger', $health['tone']);
        $this->assertStringStartsWith('1 · 1 мин', $health['details'][2]['value']);
        $this->assertSame('Запустите worker-ы очередей: default, bot-replies, bitrix-live.', $health['recommendation']);
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
            'application_name' => 'Герман-4',
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
        $telegramAccount = Channel::factory()->account()->create([
            'name' => 'Локальный Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
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
        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $telegramAccount->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($telegramAccount),
            'connector_code' => 'abc_telegram_account',
            'line_id' => '7',
            'line_name' => '7 Локальный Telegram Account - Герман-1',
            'callback_owner_id' => $callbackOwner->id,
            'source_id' => 'ABC_TELEGRAM_ACCOUNT',
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
        $this->assertStringContainsString("'name' => 'Герман-4 Telegram'", $snippet);
        $this->assertStringContainsString("'component' => 'abrikosoff:imconnector.telegram'", $snippet);
        $this->assertStringContainsString("'line_id' => '9'", $snippet);
        $this->assertStringContainsString("'line_name' => '9 Локальный бот телеграм - Герман-1'", $snippet);
        $this->assertStringContainsString("'color' => '#27A7E7'", $snippet);
        $this->assertStringContainsString("'label' => 'TG'", $snippet);
        $this->assertStringContainsString('9 =>', $snippet);
        $this->assertStringContainsString("'abc_max'", $snippet);
        $this->assertStringContainsString("'name' => 'Герман-4 MAX'", $snippet);
        $this->assertStringContainsString("'component' => 'abrikosoff:imconnector.max'", $snippet);
        $this->assertStringContainsString("'line_id' => '8'", $snippet);
        $this->assertStringContainsString("'line_name' => '8 Локальный бот MAX - Герман-1'", $snippet);
        $this->assertStringContainsString("'color' => '#7B4DFF'", $snippet);
        $this->assertStringContainsString("'label' => 'MX'", $snippet);
        $this->assertStringContainsString('8 =>', $snippet);
        $this->assertStringNotContainsString("'abc_telegram_account'", $snippet);
        $this->assertStringNotContainsString("'line_id' => '7'", $snippet);
        $this->assertStringContainsString("'owner_profile_key' => 'local-1'", $snippet);
        $this->assertStringContainsString("'owner_callback_base_url' => 'https://local-ngrok.example.test'", $snippet);
    }

    public function test_bitrix_box_config_snippet_fails_closed_for_conflicting_connector_types(): void
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

        foreach ([
            [$telegram, '9', 'ABC_TELEGRAM'],
            [$max, '8', 'ABC_MAX'],
        ] as [$channel, $lineId, $sourceId]) {
            Bitrix24OpenLineRoute::query()->create([
                'bitrix24_profile_id' => $profile->id,
                'channel_id' => $channel->id,
                'portal_domain' => $profile->portal_domain,
                'profile_key' => $profile->profile_key,
                'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
                'connector_code' => 'shared_connector',
                'line_id' => $lineId,
                'line_name' => $channel->name,
                'callback_owner_id' => $callbackOwner->id,
                'source_id' => $sourceId,
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        }

        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()]);

        $this->assertNull($component->instance()->getBitrixBoxConfigSnippet());
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

    public function test_employee_cannot_create_usable_route_without_published_registry_owner(): void
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
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock): void {
            $mock->shouldReceive('acquireLineLease')
                ->once()
                ->andThrow(new Bitrix24OpenLinesRouteRegistryException(
                    'route_registry_line_owner_missing',
                ));
            $mock->shouldNotReceive('releaseLineLease');
        });

        Livewire::actingAs($employee)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.status", Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_telegram')
            ->set("openLineRouteForms.{$channel->id}.line_id", '15')
            ->set("openLineRouteForms.{$channel->id}.line_name", '9 Локальный бот телеграм - Герман-1')
            ->set("openLineRouteForms.{$channel->id}.source_id", 'source-editable')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet(
                'openLineRouteErrorMessage',
                'Для этой открытой линии ещё не опубликован владелец в общем OpenLines registry. Сначала выполните разрешённую публикацию ownership.',
            );

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
        ]);
    }

    public function test_employee_cannot_send_non_numeric_line_id_to_registry_lease(): void
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
        Http::preventStrayRequests();

        Livewire::actingAs($employee)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.status", Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_telegram')
            ->set("openLineRouteForms.{$channel->id}.line_id", 'line-editable')
            ->set("openLineRouteForms.{$channel->id}.line_name", '9 Локальный бот телеграм - Герман-1')
            ->set("openLineRouteForms.{$channel->id}.source_id", 'source-editable')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet(
                'openLineRouteErrorMessage',
                'LINE_ID открытой линии должен состоять из 1–64 цифр.',
            );

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
        ]);
    }

    public function test_employee_with_edit_permission_can_save_exact_prepublished_registry_owner(): void
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
        $owner = $profile->callbackOwners()->firstOrFail();
        $client = $this->mock(Bitrix24OpenLinesRouteRegistryClient::class);
        $client->shouldReceive('acquireLineLease')
            ->once()
            ->withArgs(fn (
                Bitrix24Profile $usedProfile,
                Bitrix24CallbackOwner $usedOwner,
                string $connectorCode,
                string $connectorType,
                string $lineId,
                int $leaseSeconds,
            ): bool => $usedProfile->is($profile)
                && $usedOwner->is($owner)
                && $connectorCode === 'abc_telegram'
                && $connectorType === 'telegram'
                && $lineId === '16'
                && $leaseSeconds >= 180)
            ->andReturn([
                'lease_token' => str_repeat('a', 64),
                'expires_at' => now()->addHour()->toIso8601String(),
            ]);
        $client->shouldReceive('releaseLineLease')
            ->once()
            ->withArgs(fn (
                Bitrix24Profile $usedProfile,
                Bitrix24CallbackOwner $usedOwner,
                string $lineId,
                string $leaseToken,
            ): bool => $usedProfile->is($profile)
                && $usedOwner->is($owner)
                && $lineId === '16'
                && $leaseToken === str_repeat('a', 64));

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.edit', true);

        Livewire::actingAs($employee)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.status", Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_telegram')
            ->set("openLineRouteForms.{$channel->id}.line_id", '16')
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
            'line_id' => '16',
            'line_name' => '9 Локальный бот телеграм - Герман-1',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'line_owner_key' => 'crm.edit.test#16',
            'source_id' => 'source-editable',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'created_by_user_id' => $employee->id,
            'updated_by_user_id' => $employee->id,
        ]);
    }

    public function test_shared_registry_owner_conflict_blocks_usable_route_before_database_insert(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Blocked MAX',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock): void {
            $mock->shouldReceive('acquireLineLease')
                ->once()
                ->andThrow(new Bitrix24OpenLinesRouteRegistryException(
                    'route_registry_line_owner_conflict',
                ));
            $mock->shouldNotReceive('releaseLineLease');
        });

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.status", Bitrix24OpenLineRoute::STATUS_ACTIVE)
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_max')
            ->set("openLineRouteForms.{$channel->id}.line_id", '14')
            ->set("openLineRouteForms.{$channel->id}.source_id", 'ABC_MAX')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet(
                'openLineRouteErrorMessage',
                'Открытая линия закреплена в общем OpenLines registry за другим контуром.',
            );

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
        ]);
    }

    public function test_generic_save_cannot_change_existing_route_identity_or_dialog_binding(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.identity.test',
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
            'line_id' => 'line-original',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $dialog = Dialog::factory()->create([
            'bitrix24_open_line_route_id' => $route->id,
            'bitrix24_open_line_user_code_override' => 'abc_telegram|line-original|abrikosoff-dialog:42|26',
            'bitrix24_open_line_resolved_chat_id_override' => '42',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $this->allowPublishedOpenLineOwnership(2);

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.line_id", 'line-changed')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet(
                'openLineRouteErrorMessage',
                'Обычное сохранение не меняет код соединителя и LINE_ID существующего маршрута.',
            );

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_changed')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet(
                'openLineRouteErrorMessage',
                'Обычное сохранение не меняет код соединителя и LINE_ID существующего маршрута.',
            );

        $route->refresh();
        $dialog->refresh();

        $this->assertSame('abc_telegram', $route->connector_code);
        $this->assertSame('line-original', $route->line_id);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame($route->id, $dialog->bitrix24_open_line_route_id);
        $this->assertSame(
            'abc_telegram|line-original|abrikosoff-dialog:42|26',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('42', $dialog->bitrix24_open_line_resolved_chat_id_override);
    }

    public function test_generic_save_cannot_change_misconfigured_route_status_or_bypass_gate_through_inactive(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.misconfigured.test',
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
            'line_id' => 'line-broken',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
        ]);
        $dialog = Dialog::factory()->create([
            'bitrix24_open_line_route_id' => $route->id,
            'bitrix24_open_line_user_code_override' => 'abc_telegram|line-broken|abrikosoff-dialog:77|26',
            'bitrix24_open_line_resolved_chat_id_override' => '77',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $this->allowPublishedOpenLineOwnership(2);

        foreach ([
            Bitrix24OpenLineRoute::STATUS_INACTIVE,
            Bitrix24OpenLineRoute::STATUS_ACTIVE,
            Bitrix24OpenLineRoute::STATUS_LEGACY,
        ] as $status) {
            Livewire::actingAs($admin)
                ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
                ->set("openLineRouteForms.{$channel->id}.status", $status)
                ->call('saveOpenLineRoute', $channel->id)
                ->assertSet(
                    'openLineRouteErrorMessage',
                    'Статус маршрута с ошибкой нельзя менять обычным сохранением.',
                );

            $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->fresh()->status);
        }

        $route->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertSame('line-broken', $route->line_id);
        $this->assertNull($route->line_owner_key);
        $this->assertSame($route->id, $dialog->bitrix24_open_line_route_id);
        $this->assertSame(
            'abc_telegram|line-broken|abrikosoff-dialog:77|26',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('77', $dialog->bitrix24_open_line_resolved_chat_id_override);
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
        $this->allowPublishedOpenLineOwnership();

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

    public function test_admin_can_see_last_stale_open_line_callback_on_route_card(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.stale-callback.test',
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

        Bitrix24OpenLineRoute::query()->create([
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
        $event = $this->makeWebhookEvent($connection, [
            'callback_type' => Bitrix24WebhookEvent::TYPE_OPENLINES,
            'event_name' => 'OnSendMessageCustom',
            'processing_status' => Bitrix24WebhookEvent::STATUS_PROCESSED,
            'payload' => [
                'data' => [
                    'CONNECTOR' => 'abc_telegram',
                    'LINE' => '10',
                    'DATA' => [[
                        'chat' => ['id' => 'abrikosoff-dialog:24'],
                        'im' => ['chat_id' => 23, 'message_id' => 922],
                    ]],
                ],
            ],
        ]);

        $this->makeSyncLog($connection, [
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_stale_chat_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => (string) $event->id,
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'http_status' => null,
            'request_payload' => [
                'chat_id' => 'abrikosoff-dialog:24',
                'line_id' => '10',
                'dialog_id' => 24,
                'event_name' => 'OnSendMessageCustom',
                'connector_code' => 'abc_telegram',
                'webhook_event_id' => $event->id,
                'bitrix_message_id' => '922',
                'source_bitrix_chat_id' => '23',
                'current_bitrix_chat_id' => '26',
            ],
        ]);

        $this->makeWebhookEvent($connection, [
            'callback_type' => Bitrix24WebhookEvent::TYPE_OPENLINES,
            'event_name' => 'OnSendMessageCustom',
            'processing_status' => Bitrix24WebhookEvent::STATUS_PROCESSED,
            'payload' => [
                'data' => [
                    'CONNECTOR' => 'abc_telegram',
                    'LINE' => '10',
                    'DATA' => [[
                        'chat' => ['id' => 'abrikosoff-dialog:24'],
                        'im' => ['chat_id' => 26, 'message_id' => 923],
                    ]],
                ],
            ],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->assertSee('Старая ОЛ')
            ->assertSee('chat 23 -> 26');

        $cards = collect($component->instance()->getOpenLineRouteCards())->keyBy('channel_id');
        $card = $cards->get($channel->id);

        $this->assertTrue($card['stale_callback_visible']);
        $this->assertSame('chat 23 -> 26', $card['stale_callback_label']);
        $this->assertSame('danger', $card['stale_callback_tone']);
        $this->assertStringContainsString('Источник: chat 23.', $card['stale_callback_title']);
        $this->assertStringContainsString('Текущая ОЛ: chat 26.', $card['stale_callback_title']);
    }

    public function test_admin_can_repair_latest_stale_open_line_callback_from_route_card(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.stale-repair.test',
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
            'bitrix24_open_line_user_code_override' => 'abc_telegram|9|abrikosoff-dialog:24|15',
            'bitrix24_open_line_resolved_chat_id_override' => '23',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $sameLineStaleIdentity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $sameLineStaleDialog = Dialog::factory()->create([
            'current_contact_identity_id' => $sameLineStaleIdentity->id,
            'bitrix24_open_line_route_id' => $route->id,
            'bitrix24_open_line_user_code_override' => 'abc_telegram|10|abrikosoff-dialog:24|15',
            'bitrix24_open_line_resolved_chat_id_override' => '23',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $currentIdentity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $currentDialog = Dialog::factory()->create([
            'current_contact_identity_id' => $currentIdentity->id,
            'bitrix24_open_line_route_id' => $route->id,
            'bitrix24_open_line_user_code_override' => 'imol|abc_telegram|10|abrikosoff-dialog:24|26',
            'bitrix24_open_line_resolved_chat_id_override' => '26',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $staleLog = $this->makeSyncLog($connection, [
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_stale_chat_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => '111',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'http_status' => null,
            'request_payload' => [
                'chat_id' => 'abrikosoff-dialog:24',
                'line_id' => '10',
                'dialog_id' => 24,
                'event_name' => 'OnSendMessageCustom',
                'connector_code' => 'abc_telegram',
                'webhook_event_id' => 111,
                'bitrix_message_id' => '922',
                'source_bitrix_chat_id' => '23',
                'current_bitrix_chat_id' => '26',
            ],
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection, bool $transportRetry): bool => $method === 'imopenlines.operator.another.finish'
                    && ($params['CHAT_ID'] ?? null) === '23'
                    && $usedConnection->is($connection)
                    && $transportRetry === false)
                ->andReturn($this->bitrixResponse(true, true));
        });

        $component = Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->assertSee('Старая ОЛ')
            ->assertSee('Закрыть')
            ->call('repairLatestStaleOpenLine', $channel->id)
            ->assertHasNoErrors();

        $dialog->refresh();
        $this->assertNull($dialog->bitrix24_open_line_user_code_override);
        $this->assertNull($dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNull($dialog->bitrix24_open_line_binding_verified_at);

        $sameLineStaleDialog->refresh();
        $this->assertNull($sameLineStaleDialog->bitrix24_open_line_user_code_override);
        $this->assertNull($sameLineStaleDialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNull($sameLineStaleDialog->bitrix24_open_line_binding_verified_at);

        $currentDialog->refresh();
        $this->assertSame('imol|abc_telegram|10|abrikosoff-dialog:24|26', $currentDialog->bitrix24_open_line_user_code_override);
        $this->assertSame('26', $currentDialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($currentDialog->bitrix24_open_line_binding_verified_at);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'connection_id' => $connection->id,
            'operation' => 'openlines_stale_chat_repair_completed',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'entity_type' => 'open_line_route',
            'entity_id' => (string) $route->id,
        ]);

        $repairLog = Bitrix24SyncLog::query()
            ->where('operation', 'openlines_stale_chat_repair_completed')
            ->firstOrFail();
        $this->assertSame($staleLog->id, (int) data_get($repairLog->request_payload, 'stale_log_id'));
        $this->assertSame('23', (string) data_get($repairLog->request_payload, 'source_bitrix_chat_id'));
        $this->assertSame(2, (int) data_get($repairLog->request_payload, 'reset_dialog_count'));

        $cards = collect($component->instance()->getOpenLineRouteCards())->keyBy('channel_id');
        $card = $cards->get($channel->id);
        $this->assertSame('success', $card['stale_callback_tone']);
        $this->assertSame('chat 23 закрыта', $card['stale_callback_label']);
        $this->assertFalse($card['stale_callback_can_repair']);
    }

    public function test_stale_open_line_repair_does_not_reset_bindings_when_bitrix_rejects_finish(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.stale-repair-fail.test',
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
            'bitrix24_open_line_user_code_override' => 'abc_telegram|9|abrikosoff-dialog:24|15',
            'bitrix24_open_line_resolved_chat_id_override' => '23',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);

        $this->makeSyncLog($connection, [
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_stale_chat_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => '111',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'http_status' => null,
            'request_payload' => [
                'chat_id' => 'abrikosoff-dialog:24',
                'line_id' => '10',
                'dialog_id' => 24,
                'event_name' => 'OnSendMessageCustom',
                'connector_code' => 'abc_telegram',
                'webhook_event_id' => 111,
                'bitrix_message_id' => '922',
                'source_bitrix_chat_id' => '23',
                'current_bitrix_chat_id' => '26',
            ],
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection, bool $transportRetry): bool => $method === 'imopenlines.operator.another.finish'
                    && ($params['CHAT_ID'] ?? null) === '23'
                    && $usedConnection->is($connection)
                    && $transportRetry === false)
                ->andReturn($this->bitrixResponse(false, null, 'ACCESS_DENIED', 'Недостаточно прав.'));
        });

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('repairLatestStaleOpenLine', $channel->id)
            ->assertSet('openLineRouteErrorMessage', 'Не удалось закрыть старую ОЛ: Недостаточно прав.');

        $dialog->refresh();
        $this->assertSame('abc_telegram|9|abrikosoff-dialog:24|15', $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('23', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'connection_id' => $connection->id,
            'operation' => 'openlines_stale_chat_repair_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'error_code' => 'ACCESS_DENIED',
            'error_message' => 'Недостаточно прав.',
            'entity_type' => 'open_line_route',
            'entity_id' => (string) $route->id,
        ]);
    }

    public function test_stale_open_line_repair_logs_failure_when_bitrix_transport_fails(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'crm.stale-repair-transport-fail.test',
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
            'bitrix24_open_line_user_code_override' => 'imol|abc_telegram|10|abrikosoff-dialog:24|15',
            'bitrix24_open_line_resolved_chat_id_override' => '23',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);

        $this->makeSyncLog($connection, [
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'openlines_stale_chat_ignored',
            'entity_type' => 'openlines_webhook_event',
            'entity_id' => '111',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
            'http_status' => null,
            'request_payload' => [
                'chat_id' => 'abrikosoff-dialog:24',
                'line_id' => '10',
                'dialog_id' => 24,
                'event_name' => 'OnSendMessageCustom',
                'connector_code' => 'abc_telegram',
                'webhook_event_id' => 111,
                'bitrix_message_id' => '922',
                'source_bitrix_chat_id' => '23',
                'current_bitrix_chat_id' => '26',
            ],
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection, bool $transportRetry): bool => $method === 'imopenlines.operator.another.finish'
                    && ($params['CHAT_ID'] ?? null) === '23'
                    && $usedConnection->is($connection)
                    && $transportRetry === false)
                ->andThrow(new Bitrix24ApiException('Bitrix24 REST call failed without transport retry.'));
        });

        Livewire::actingAs($admin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('repairLatestStaleOpenLine', $channel->id)
            ->assertSet('openLineRouteErrorMessage', 'Не удалось закрыть старую ОЛ: Bitrix24 REST call failed without transport retry.');

        $dialog->refresh();
        $this->assertSame('imol|abc_telegram|10|abrikosoff-dialog:24|15', $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('23', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'connection_id' => $connection->id,
            'operation' => 'openlines_stale_chat_repair_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'error_code' => 'transport_uncertain',
            'error_message' => 'Bitrix24 REST call failed without transport retry.',
            'entity_type' => 'open_line_route',
            'entity_id' => (string) $route->id,
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
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_telegram_account')
            ->set("openLineRouteForms.{$channel->id}.line_id", 'account-line')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', 'Telegram account пока нельзя сделать рабочим маршрутом открытых линий.');

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'channel_id' => $channel->id,
            'line_id' => 'account-line',
        ]);
    }

    public function test_refresh_action_does_not_create_missing_route_or_call_bitrix24(): void
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
            'scope' => ['crm', 'im', 'imopenlines', 'imconnector'],
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')->never();
        });

        $component = Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()]);

        $card = collect($component->instance()->getOpenLineRouteCards())
            ->firstWhere('channel_id', $channel->id);

        $this->assertIsArray($card);
        $this->assertSame('Обновить карточку', $card['auto_setup_label']);
        $this->assertFalse($card['auto_setup_enabled']);
        $this->assertSame('Маршрут ОЛ ещё не сохранён', $card['auto_setup_reason']);

        $component
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet(
                'openLineRouteErrorMessage',
                'Сохранённый маршрут ОЛ не найден. Автоматическое создание новой линии отключено.',
            );

        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
        ]);
    }

    public function test_refresh_failure_marks_route_misconfigured_and_preserves_identity_and_dialog_binding(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => 'stagecrm.fvds.ru',
            'profile_key' => 'dev-german-main',
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
            'is_active' => true,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-original',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $dialog = Dialog::factory()->create([
            'bitrix24_open_line_route_id' => $route->id,
            'bitrix24_open_line_user_code_override' => 'abc_telegram|line-original|abrikosoff-dialog:535|26',
            'bitrix24_open_line_resolved_chat_id_override' => '56',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $this->allowPublishedOpenLineOwnership();

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldNotReceive('call')->with('imopenlines.config.add', \Mockery::any(), \Mockery::any());
            $mock->shouldNotReceive('call')->with('app.info', \Mockery::any(), \Mockery::any());
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andReturn($this->bitrixResponse(true, [
                    'result' => false,
                    'error_description' => 'Connector rejected',
                ]));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', 'Connector rejected');

        $route->refresh();
        $dialog->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertSame('abc_telegram', $route->connector_code);
        $this->assertSame('line-original', $route->line_id);
        $this->assertNull($route->line_owner_key);
        $this->assertSame('Connector rejected', $route->last_error_message);
        $this->assertSame($route->id, $dialog->bitrix24_open_line_route_id);
        $this->assertSame(
            'abc_telegram|line-original|abrikosoff-dialog:535|26',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('56', $dialog->bitrix24_open_line_resolved_chat_id_override);
    }

    public function test_successful_refresh_keeps_misconfigured_route_unusable_and_preserves_dialog_binding(): void
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
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram_dev_german_main',
            'line_id' => 'line-existing',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
            'last_error_message' => 'Старая ошибка',
            'last_error_at' => now()->subMinute(),
        ]);
        $dialog = Dialog::factory()->create([
            'bitrix24_open_line_route_id' => $route->id,
            'bitrix24_open_line_user_code_override' => 'abc_telegram_dev_german_main|line-existing|abrikosoff-dialog:535|26',
            'bitrix24_open_line_resolved_chat_id_override' => '56',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $this->allowPublishedOpenLineOwnership();

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

            $mock->shouldNotReceive('call')
                ->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['CONFIG_ID'] ?? null) === 'line-existing'
                    && ($params['PARAMS'] ?? null) === [
                        'CRM' => 'Y',
                        'CRM_CREATE' => 'deal',
                        'CRM_SOURCE' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
                    ])
                ->andReturn($this->bitrixResponse(true, true));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null)
            ->assertSet(
                'openLineRouteSuccessMessage',
                sprintf('Карточка соединителя обновлена: #%d %s, LINE_ID line-existing.', $channel->id, $channel->name),
            );

        $route->refresh();
        $dialog->refresh();

        $this->assertSame(1, Bitrix24OpenLineRoute::query()->count());
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_MISCONFIGURED, $route->status);
        $this->assertSame('line-existing', $route->line_id);
        $this->assertNull($route->line_owner_key);
        $this->assertNull($route->last_error_message);
        $this->assertNull($route->last_error_at);
        $this->assertSame($route->id, $dialog->bitrix24_open_line_route_id);
        $this->assertSame(
            'abc_telegram_dev_german_main|line-existing|abrikosoff-dialog:535|26',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('56', $dialog->bitrix24_open_line_resolved_chat_id_override);
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
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram_dev_german_main',
            'line_id' => 'line-existing',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $dialog = Dialog::factory()->create([
            'bitrix24_open_line_route_id' => $route->id,
            'bitrix24_open_line_user_code_override' => 'abc_telegram_dev_german_main|line-existing|abrikosoff-dialog:535|26',
            'bitrix24_open_line_resolved_chat_id_override' => '56',
            'bitrix24_open_line_binding_verified_at' => now(),
        ]);
        $this->allowPublishedOpenLineOwnership(2);

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
                    && ($params['NAME'] ?? null) === 'Герман-4 Telegram')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['LINE'] ?? null) === 'line-existing'
                    && data_get($params, 'DATA.ID') === 'channel:'.$channel->id.':connector:abc_telegram_dev_german_main:line:line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldNotReceive('call')
                ->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['CONFIG_ID'] ?? null) === 'line-existing'
                    && ($params['PARAMS'] ?? null) === [
                        'CRM' => 'Y',
                        'CRM_CREATE' => 'deal',
                        'CRM_SOURCE' => 'ABC_TELEGRAM_UPDATED',
                    ])
                ->andReturn($this->bitrixResponse(true, true));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set("openLineRouteForms.{$channel->id}.source_id", 'ABC_TELEGRAM_UPDATED')
            ->call('saveOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null)
            ->set("openLineRouteForms.{$channel->id}.connector_code", 'abc_changed_in_unsaved_form')
            ->set("openLineRouteForms.{$channel->id}.line_id", 'line-changed-in-unsaved-form')
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', null);

        $route->refresh();
        $dialog->refresh();

        $this->assertSame('Герман-4', $connection->refresh()->application_name);
        $this->assertSame(1, Bitrix24OpenLineRoute::query()->count());
        $this->assertSame('abc_telegram_dev_german_main', $route->connector_code);
        $this->assertSame('line-existing', $route->line_id);
        $this->assertSame('ABC_TELEGRAM_UPDATED', $route->source_id);
        $this->assertSame('stagecrm.fvds.ru#line-existing', $route->line_owner_key);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame($route->id, $dialog->bitrix24_open_line_route_id);
        $this->assertSame(
            'abc_telegram_dev_german_main|line-existing|abrikosoff-dialog:535|26',
            $dialog->bitrix24_open_line_user_code_override,
        );
        $this->assertSame('56', $dialog->bitrix24_open_line_resolved_chat_id_override);
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
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $this->allowPublishedOpenLineOwnership();

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
                    && ($params['NAME'] ?? null) === 'Герман-4 Telegram'
                    && ($params['COMMENT'] ?? null) === 'Настройки канала Герман-4 Telegram')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.connector.data.set'
                    && $usedConnection->is($connection)
                    && ($params['LINE'] ?? null) === 'line-existing'
                    && data_get($params, 'DATA.ID') === 'channel:'.$channel->id.':connector:abc_telegram_dev_german_main:line:line-existing')
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldNotReceive('call')
                ->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['CONFIG_ID'] ?? null) === 'line-existing'
                    && ($params['PARAMS'] ?? null) === [
                        'CRM' => 'Y',
                        'CRM_CREATE' => 'deal',
                        'CRM_SOURCE' => 'ABC_TELEGRAM_DEV_GERMAN_MAIN',
                    ])
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
                'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
                'source_id' => $sourceId,
                'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            ]);
        }
        $this->allowPublishedOpenLineOwnership(2);

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
            $mock->shouldNotReceive('call')
                ->with('imconnector.activate', \Mockery::any(), \Mockery::any());

            $mock->shouldReceive('call')
                ->twice()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imopenlines.config.update'
                    && $usedConnection->is($connection)
                    && ($params['PARAMS']['CRM'] ?? null) === 'Y'
                    && ($params['PARAMS']['CRM_CREATE'] ?? null) === 'deal'
                    && ! array_key_exists('ACTIVE', $params['PARAMS'] ?? [])
                    && in_array([
                        $params['CONFIG_ID'] ?? null,
                        $params['PARAMS']['CRM_SOURCE'] ?? null,
                    ], [
                        ['line-telegram', 'ABC_TELEGRAM_DEV_GERMAN_MAIN'],
                        ['line-max', 'ABC_MAX_DEV_GERMAN_MAIN'],
                    ], true))
                ->andReturn($this->bitrixResponse(true, true));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_telegram_dev_german_main'
                    && ($params['NAME'] ?? null) === 'Новое имя Telegram'
                    && ($params['ICON']['COLOR'] ?? null) === '#2AABEE')
                ->andReturn($this->bitrixResponse(true, ['result' => true]));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method, array $params, Bitrix24Connection $usedConnection): bool => $method === 'imconnector.register'
                    && $usedConnection->is($connection)
                    && ($params['ID'] ?? null) === 'abc_max_dev_german_main'
                    && ($params['NAME'] ?? null) === 'Новое имя MAX'
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

    public function test_save_application_name_keeps_active_route_when_refresh_context_is_unsupported(): void
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
            'application_name' => 'Старое имя',
            'scope' => AutoSetupBitrix24OpenLineRouteAction::REQUIRED_SCOPES,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-existing',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => 'Предыдущее предупреждение',
            'last_error_at' => now()->subMinute(),
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldNotReceive('call');
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set('applicationNameForm.application_name', 'Новое имя')
            ->call('saveApplicationName')
            ->assertSet('applicationNameForm.application_name', 'Новое имя');

        $route->refresh();

        $this->assertSame('Новое имя', $connection->refresh()->application_name);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame('other.example.test#line-existing', $route->line_owner_key);
        $this->assertSame('Предыдущее предупреждение', $route->last_error_message);
        $this->assertNotNull($route->last_error_at);
    }

    public function test_save_application_name_handles_auth_refresh_failure_without_invalidating_route(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
            'telegram_connector_code' => 'abc_telegram',
            'telegram_source_id' => 'ABC_TELEGRAM',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'application_name' => 'Старое имя',
            'scope' => AutoSetupBitrix24OpenLineRouteAction::REQUIRED_SCOPES,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-existing',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => 'Предыдущее предупреждение',
            'last_error_at' => now()->subMinute(),
        ]);
        $this->allowPublishedOpenLineOwnership();

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andThrow(new Bitrix24AuthRefreshException('Bitrix24 token refresh request failed.'));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->set('applicationNameForm.application_name', 'Новое имя')
            ->call('saveApplicationName')
            ->assertSet('applicationNameForm.application_name', 'Новое имя');

        $route->refresh();

        $this->assertSame('Новое имя', $connection->refresh()->application_name);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame('stagecrm.fvds.ru#line-existing', $route->line_owner_key);
        $this->assertSame('Предыдущее предупреждение', $route->last_error_message);
        $this->assertNotNull($route->last_error_at);
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
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-existing',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => 'Предыдущее предупреждение',
            'last_error_at' => now()->subMinute(),
        ]);
        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')->never();
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet('openLineRouteErrorMessage', 'Обновление регистрации ОЛ доступно только для stagecrm.fvds.ru.');

        $route->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame('other.example.test#line-existing', $route->line_owner_key);
        $this->assertSame('Предыдущее предупреждение', $route->last_error_message);
        $this->assertNotNull($route->last_error_at);
    }

    public function test_auto_setup_reports_auth_refresh_failure_without_invalidating_route(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeProfile([
            'portal_domain' => AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN,
            'telegram_connector_code' => 'abc_telegram',
            'telegram_source_id' => 'ABC_TELEGRAM',
        ]);
        $connection = $this->makeConnection([
            'profile_id' => $profile->id,
            'portal_domain' => $profile->portal_domain,
            'scope' => AutoSetupBitrix24OpenLineRouteAction::REQUIRED_SCOPES,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-existing',
            'callback_owner_id' => $profile->callbackOwners()->firstOrFail()->id,
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
            'last_error_message' => 'Предыдущее предупреждение',
            'last_error_at' => now()->subMinute(),
        ]);
        $this->allowPublishedOpenLineOwnership();

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imconnector.register')
                ->andThrow(new Bitrix24AuthRefreshException('Bitrix24 token refresh request failed.'));
        });

        Livewire::actingAs($superadmin)
            ->test(ViewBitrix24Connection::class, ['record' => $connection->getKey()])
            ->call('setupOpenLineRoute', $channel->id)
            ->assertSet(
                'openLineRouteErrorMessage',
                'Не удалось обновить авторизацию Bitrix24: Bitrix24 token refresh request failed.',
            );

        $route->refresh();

        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->status);
        $this->assertSame('stagecrm.fvds.ru#line-existing', $route->line_owner_key);
        $this->assertSame('Предыдущее предупреждение', $route->last_error_message);
        $this->assertNotNull($route->last_error_at);
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
        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-existing',
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
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
        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abc_telegram',
            'line_id' => 'line-existing',
            'source_id' => 'ABC_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
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

        foreach ([
            [$maxBot, 'abc_max', 'line-max', 'ABC_MAX'],
            [$telegramBot, 'abc_telegram', 'line-telegram', 'ABC_TELEGRAM'],
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

    private function allowPublishedOpenLineOwnership(int $times = 1): void
    {
        $client = $this->mock(Bitrix24OpenLinesRouteRegistryClient::class);
        $client->shouldReceive('acquireLineLease')
            ->times($times)
            ->andReturn([
                'lease_token' => str_repeat('b', 64),
                'expires_at' => now()->addHour()->toIso8601String(),
            ]);
        $client->shouldReceive('releaseLineLease')
            ->times($times);
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
