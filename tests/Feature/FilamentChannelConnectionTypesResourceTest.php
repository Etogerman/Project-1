<?php

namespace Tests\Feature;

use App\Filament\Resources\ChannelConnectionTypes\Pages\ManageChannelConnectionTypes;
use App\Models\ChannelConnectionType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentChannelConnectionTypesResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_admin_can_manage_channel_connection_types(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $type = ChannelConnectionType::query()->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/channel-connection-types')
            ->assertOk()
            ->assertSee('Типы подключений');

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', ChannelConnectionType::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $type));
        $this->assertTrue(Gate::forUser($admin)->allows('create', ChannelConnectionType::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $type));

        Livewire::actingAs($admin)
            ->test(ManageChannelConnectionTypes::class)
            ->assertCanSeeTableRecords([$type]);
    }

    public function test_employee_cannot_access_channel_connection_types_even_with_channel_permissions(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $type = ChannelConnectionType::query()->firstOrFail();

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', true);

        $this->actingAs($employee)
            ->get('/admin/channel-connection-types')
            ->assertForbidden();

        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', ChannelConnectionType::class));
        $this->assertFalse(Gate::forUser($employee)->allows('view', $type));
        $this->assertFalse(Gate::forUser($employee)->allows('create', ChannelConnectionType::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $type));
    }

    private function setRolePermission(string $role, string $permissionKey, bool $granted): void
    {
        DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission_key', $permissionKey)
            ->update([
                'granted' => $granted,
                'updated_at' => now(),
            ]);
    }
}
