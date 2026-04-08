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
            ->assertSee('Права доступа')
            ->assertSee('Раздел и действия')
            ->assertSee('data-role="role-permission-matrix-table"', false)
            ->assertSee('Контакты')
            ->assertSee('Диалоги')
            ->assertSee('Теги')
            ->assertSee('Каналы связи')
            ->assertSee('Автоответы')
            ->assertSee('Bitrix24')
            ->assertSee('Сценарии')
            ->assertSee('Сотрудники')
            ->assertSee('Администратор')
            ->assertSee('Сотрудник')
            ->assertSee('Создание и редактирование')
            ->assertSee('Создание, редактирование и архивация')
            ->assertSee('Сохранить')
            ->assertSee('Отмена')
            ->assertDontSee('Матрица ролей и прав')
            ->assertDontSeeText('contacts.view')
            ->assertDontSeeText('contacts.edit')
            ->assertDontSeeText('contacts.delete')
            ->assertDontSeeText('dialogs.view')
            ->assertDontSeeText('dialogs.edit')
            ->assertDontSeeText('dialogs.delete')
            ->assertDontSeeText('tags.view')
            ->assertDontSeeText('users.delete')
            ->assertDontSeeText('channels.delete')
            ->assertDontSeeText('bitrix24.view')
            ->assertDontSeeText('bitrix24.edit')
            ->assertDontSeeText('bitrix24.delete')
            ->assertDontSeeText('scenarios.view')
            ->assertDontSeeText('scenarios.edit')
            ->assertDontSeeText('scenarios.archive')
            ->assertDontSee('Архивация')
            ->assertDontSee('Системные разделы')
            ->assertDontSee('Уже влияет на доступ')
            ->assertDontSee('Пока только конфигурация')
            ->assertDontSee('Суперадминистратор вне матрицы')
            ->assertDontSee('Сохранить матрицу')
            ->assertDontSee('Сбросить несохранённые изменения')
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
