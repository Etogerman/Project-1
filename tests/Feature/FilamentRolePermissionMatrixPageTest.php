<?php

namespace Tests\Feature;

use App\Filament\Pages\RolePermissionMatrix;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_admin_can_open_role_permission_matrix_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(RolePermissionMatrix::getUrl(panel: Filament::getPanel('admin')))
            ->assertOk()
            ->assertSee('Матрица ролей и прав')
            ->assertSee('Контакты')
            ->assertSee('Системные разделы')
            ->assertSee('Администратор')
            ->assertSee('Сотрудник')
            ->assertSee('contacts.view')
            ->assertSee('contacts.edit')
            ->assertSee('dialogs.delete')
            ->assertSee('Подготовительное право')
            ->assertSee('bitrix24.view')
            ->assertSee('Конфигурация из базы')
            ->assertSee('role_permissions')
            ->assertSee('не управляют реальным доступом')
            ->assertSee('Включено')
            ->assertSee('Выключено')
            ->assertDontSee('contacts.phone.edit_existing');
    }

    public function test_page_reflects_database_matrix_values(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'contacts.view')
            ->update(['granted' => false]);

        $this->actingAs($admin)
            ->get(RolePermissionMatrix::getUrl(panel: Filament::getPanel('admin')))
            ->assertOk()
            ->assertSee('data-state-key="contacts.view:employee:disabled"', false);
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
}
