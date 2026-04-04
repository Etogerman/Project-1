<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentUsersResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_user_can_open_users_page_and_see_records(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
            'is_admin' => true,
        ]);
        $member = User::factory()->create([
            'email' => 'member@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Сотрудники');

        $this->assertSame('Сотрудник', UserResource::getModelLabel());
        $this->assertSame('Сотрудники', UserResource::getPluralModelLabel());
        $this->assertSame('Сотрудники', UserResource::getNavigationLabel());

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->assertCanSeeTableRecords([$admin, $member]);
    }

    public function test_non_admin_user_cannot_access_users_resource(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_employee_user_access_is_controlled_by_role_permission_matrix(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);
        $record = User::factory()->create([
            'email' => 'visible-member@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'users.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'users.edit', false);

        $this->actingAs($user)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Сотрудники');

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $record));
        $this->assertFalse(Gate::forUser($user)->allows('create', User::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $record));
    }

    public function test_employee_can_create_and_update_users_when_users_edit_is_enabled_in_matrix(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);
        $record = User::factory()->create([
            'email' => 'editable@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'users.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'users.edit', true);

        Livewire::actingAs($user)
            ->test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Operator Created',
                'email' => 'operator-created@example.com',
                'is_active' => true,
                'is_admin' => false,
                'password' => 'secret12345',
                'password_confirmation' => 'secret12345',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(User::class, [
            'email' => 'operator-created@example.com',
            'name' => 'Operator Created',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        Livewire::actingAs($user)
            ->test(ManageUsers::class)
            ->callTableAction('edit', $record, [
                'name' => 'Operator Updated',
                'email' => 'editable@example.com',
                'is_active' => false,
                'is_admin' => false,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertHasNoTableActionErrors();

        $record->refresh();

        $this->assertSame('Operator Updated', $record->name);
        $this->assertFalse($record->is_active);
    }

    public function test_active_user_can_create_a_user_from_filament_resource(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Manager',
                'email' => 'Manager@Example.com',
                'is_active' => true,
                'is_admin' => false,
                'password' => 'secret12345',
                'password_confirmation' => 'secret12345',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(User::class, [
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $createdUser = User::where('email', 'manager@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('secret12345', $createdUser->password));
    }

    public function test_admin_can_open_create_user_modal_with_polished_sections(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->mountAction('create')
            ->assertMountedActionModalSee('Основное')
            ->assertMountedActionModalSee('Базовые данные сотрудника для входа и отображения в админке.')
            ->assertMountedActionModalSee('Доступ')
            ->assertMountedActionModalSee('Управление активностью учётной записи и административными правами.')
            ->assertMountedActionModalSee('Пароль')
            ->assertMountedActionModalSee('Укажите пароль для нового сотрудника.')
            ->assertMountedActionModalSee('Отключённый сотрудник не сможет войти в панель.')
            ->assertMountedActionModalSee('Администратор управляет сотрудниками и настройками панели.');
    }

    public function test_admin_can_update_status_and_role_without_overwriting_password(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
            'is_admin' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'editor@example.com',
            'is_active' => true,
            'is_admin' => false,
            'password' => 'secret12345',
        ]);

        $previousPasswordHash = $user->password;

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->callTableAction('edit', $user, [
                'name' => 'Editor Updated',
                'email' => 'editor@example.com',
                'is_active' => false,
                'is_admin' => true,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertHasNoTableActionErrors();

        $user->refresh();

        $this->assertSame('Editor Updated', $user->name);
        $this->assertFalse($user->is_active);
        $this->assertTrue($user->is_admin);
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertSame($previousPasswordHash, $user->password);
    }

    public function test_admin_can_change_user_password(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
            'is_admin' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'password-change@example.com',
            'is_active' => true,
            'is_admin' => false,
            'password' => 'old-secret123',
        ]);

        $previousPasswordHash = $user->password;

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->callTableAction('edit', $user, [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => true,
                'is_admin' => false,
                'password' => 'new-secret123',
                'password_confirmation' => 'new-secret123',
            ])
            ->assertHasNoTableActionErrors();

        $user->refresh();

        $this->assertNotSame($previousPasswordHash, $user->password);
        $this->assertTrue(Hash::check('new-secret123', $user->password));
    }

    public function test_admin_can_view_user_in_polished_overview_modal(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
            'is_admin' => true,
        ]);
        $user = User::factory()->create([
            'name' => 'Геннадий',
            'email' => 'g_e_n_a@mail.ru',
            'is_active' => true,
            'is_admin' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->mountTableAction('view', $user)
            ->assertMountedActionModalSee('Команда')
            ->assertMountedActionModalSee('Профиль сотрудника и права доступа в админке.')
            ->assertMountedActionModalSee('Основное')
            ->assertMountedActionModalSee('Доступ')
            ->assertMountedActionModalSee('Служебное')
            ->assertMountedActionModalSee('Геннадий')
            ->assertMountedActionModalSee('g_e_n_a@mail.ru')
            ->assertMountedActionModalSee('Активен')
            ->assertMountedActionModalSee('Сотрудник')
            ->assertMountedActionModalSee('Создан')
            ->assertMountedActionModalSee('Обновлён');
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = User::factory()->create([
            'email' => 'self-lock@example.com',
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->callTableAction('edit', $admin, [
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => false,
                'is_admin' => true,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertHasTableActionErrors();

        $admin->refresh();

        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->is_admin);
    }

    public function test_admin_cannot_remove_own_admin_role(): void
    {
        $admin = User::factory()->create([
            'email' => 'self-demote@example.com',
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->callTableAction('edit', $admin, [
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => true,
                'is_admin' => false,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertHasTableActionErrors();

        $admin->refresh();

        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->is_admin);
    }

    public function test_user_record_title_contains_id_and_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test-user@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->assertSame(
            sprintf('#%d %s', $user->id, $user->name),
            UserResource::getRecordTitle($user),
        );
    }

    private function setRolePermission(string $role, string $permissionKey, bool $granted): void
    {
        DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission_key', $permissionKey)
            ->update(['granted' => $granted]);
    }
}
