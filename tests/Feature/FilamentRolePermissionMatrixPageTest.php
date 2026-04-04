<?php

namespace Tests\Feature;

use App\Filament\Pages\RolePermissionMatrix;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentRolePermissionMatrixPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_superadmin_can_open_role_permission_matrix_page(): void
    {
        $superadmin = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_SUPERADMIN,
            'is_admin' => true,
        ]);

        $this->actingAs($superadmin)
            ->get(RolePermissionMatrix::getUrl(panel: Filament::getPanel('admin')))
            ->assertOk()
            ->assertSee('Матрица ролей и прав')
            ->assertSee('data-role="role-permission-matrix-table"', false)
            ->assertSee('Контакты')
            ->assertSee('Системные разделы')
            ->assertSee('Администратор')
            ->assertSee('Сотрудник')
            ->assertSee('contacts.view')
            ->assertSee('contacts.edit')
            ->assertSee('dialogs.delete')
            ->assertSee('Подготовительное право')
            ->assertSee('bitrix24.view')
            ->assertSee('Уже влияет на доступ')
            ->assertSee('Пока только конфигурация')
            ->assertSee('Суперадминистратор вне матрицы')
            ->assertSee('role_permissions')
            ->assertSee('Сохранить матрицу')
            ->assertSee('Сбросить несохранённые изменения')
            ->assertSee('часть пока остаётся только конфигурацией')
            ->assertSee('recovery-контуром вне этой таблицы')
            ->assertSee('data-runtime="runtime-active"', false)
            ->assertSee('data-runtime="config-only"', false)
            ->assertDontSee('Runtime не переключён')
            ->assertDontSee('Включено')
            ->assertDontSee('Выключено')
            ->assertDontSee('contacts.phone.edit_existing');
    }

    public function test_page_reflects_database_matrix_values(): void
    {
        $superadmin = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_SUPERADMIN,
            'is_admin' => true,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'contacts.view')
            ->update(['granted' => false]);

        $this->actingAs($superadmin)
            ->get(RolePermissionMatrix::getUrl(panel: Filament::getPanel('admin')))
            ->assertOk()
            ->assertSee('data-state-key="contacts.view:employee:disabled"', false);
    }

    public function test_superadmin_can_save_role_permission_matrix_changes(): void
    {
        $superadmin = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_SUPERADMIN,
            'is_admin' => true,
        ]);

        $this->actingAs($superadmin);

        Livewire::test(RolePermissionMatrix::class)
            ->set('permissionState.employee.contacts.delete', true)
            ->call('savePermissionMatrix');

        $this->assertTrue(
            (bool) DB::table('role_permissions')
                ->where('role', User::ROLE_EMPLOYEE)
                ->where('permission_key', 'contacts.delete')
                ->value('granted'),
        );
    }

    public function test_critical_admin_rights_remain_enabled_after_superadmin_save_attempt(): void
    {
        $superadmin = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_SUPERADMIN,
            'is_admin' => true,
        ]);

        $this->actingAs($superadmin);

        Livewire::test(RolePermissionMatrix::class)
            ->set('permissionState.admin.users.view', false)
            ->set('permissionState.admin.users.edit', false)
            ->call('savePermissionMatrix')
            ->assertSet('permissionState.admin.users.view', true)
            ->assertSet('permissionState.admin.users.edit', true);

        $this->assertTrue(
            (bool) DB::table('role_permissions')
                ->where('role', User::ROLE_ADMIN)
                ->where('permission_key', 'users.view')
                ->value('granted'),
        );

        $this->assertTrue(
            (bool) DB::table('role_permissions')
                ->where('role', User::ROLE_ADMIN)
                ->where('permission_key', 'users.edit')
                ->value('granted'),
        );
    }

    public function test_employee_cannot_access_role_permission_matrix_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($employee)
            ->get(RolePermissionMatrix::getUrl(panel: Filament::getPanel('admin')))
            ->assertForbidden();
    }

    public function test_admin_without_superadmin_role_cannot_access_role_permission_matrix_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(RolePermissionMatrix::getUrl(panel: Filament::getPanel('admin')))
            ->assertForbidden();
    }

    public function test_employee_with_granted_system_permissions_still_cannot_access_role_permission_matrix_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'channels.edit', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.edit', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.delete', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'bitrix24.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'users.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'users.edit', true);

        $this->actingAs($employee)
            ->get(RolePermissionMatrix::getUrl(panel: Filament::getPanel('admin')))
            ->assertForbidden();
    }

    private function setRolePermission(string $role, string $permissionKey, bool $granted): void
    {
        DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission_key', $permissionKey)
            ->update(['granted' => $granted]);
    }
}
