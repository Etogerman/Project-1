<?php

namespace Tests\Feature;

use App\Filament\Pages\RolePermissionMatrix;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Рабочий контур')
            ->assertSee('Системные настройки')
            ->assertSee('Администратор')
            ->assertSee('Сотрудник')
            ->assertSee('contacts.view')
            ->assertSee('contacts.phone.edit_existing')
            ->assertSee('contacts.phone.delete_existing')
            ->assertSee('bitrix24.view')
            ->assertDontSee('contacts.phone.create');
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
