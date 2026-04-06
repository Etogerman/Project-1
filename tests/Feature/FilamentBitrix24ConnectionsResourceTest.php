<?php

namespace Tests\Feature;

use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use App\Filament\Resources\Bitrix24Connections\Pages\ListBitrix24Connections;
use App\Filament\Resources\Bitrix24Connections\Pages\ViewBitrix24Connection;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\User;
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

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
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
    private function makeWebhookEvent(Bitrix24Connection $connection, array $overrides = []): Bitrix24WebhookEvent
    {
        return Bitrix24WebhookEvent::query()->create(array_merge([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
            'event_name' => 'ONCRMCONTACTADD',
            'member_id' => $connection->member_id,
            'application_token' => $connection->application_token,
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
