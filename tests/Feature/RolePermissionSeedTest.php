<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RolePermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permissions_table_is_fully_seeded_for_admin_and_employee(): void
    {
        $permissionKeys = collect(app(RolePermissionCatalog::class)->groups())
            ->pluck('actions')
            ->flatten(1)
            ->pluck('code')
            ->values()
            ->all();

        $this->assertSame(
            count($permissionKeys) * 2,
            DB::table('role_permissions')->count(),
        );

        $this->assertSame(
            $permissionKeys,
            DB::table('role_permissions')
                ->where('role', User::ROLE_ADMIN)
                ->orderBy('id')
                ->pluck('permission_key')
                ->all(),
        );

        $this->assertSame(
            $permissionKeys,
            DB::table('role_permissions')
                ->where('role', User::ROLE_EMPLOYEE)
                ->orderBy('id')
                ->pluck('permission_key')
                ->all(),
        );
    }

    public function test_admin_receives_full_matrix_and_employee_receives_agreed_defaults(): void
    {
        $adminMatrix = DB::table('role_permissions')
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('permission_key')
            ->pluck('granted', 'permission_key')
            ->map(fn (mixed $granted): bool => (bool) $granted)
            ->all();

        $employeeMatrix = DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->orderBy('permission_key')
            ->pluck('granted', 'permission_key')
            ->map(fn (mixed $granted): bool => (bool) $granted)
            ->all();

        $this->assertSame([
            'auto_reply_rules.delete' => true,
            'auto_reply_rules.edit' => true,
            'auto_reply_rules.view' => true,
            'bitrix24.delete' => true,
            'bitrix24.edit' => true,
            'bitrix24.view' => true,
            'channels.delete' => true,
            'channels.edit' => true,
            'channels.view' => true,
            'contacts.delete' => true,
            'contacts.edit' => true,
            'contacts.view' => true,
            'dialogs.delete' => true,
            'dialogs.edit' => true,
            'dialogs.view' => true,
            'tags.delete' => true,
            'tags.edit' => true,
            'tags.view' => true,
            'users.delete' => true,
            'users.edit' => true,
            'users.view' => true,
        ], $adminMatrix);

        $this->assertSame([
            'auto_reply_rules.delete' => false,
            'auto_reply_rules.edit' => false,
            'auto_reply_rules.view' => false,
            'bitrix24.delete' => false,
            'bitrix24.edit' => false,
            'bitrix24.view' => false,
            'channels.delete' => false,
            'channels.edit' => false,
            'channels.view' => false,
            'contacts.delete' => false,
            'contacts.edit' => true,
            'contacts.view' => true,
            'dialogs.delete' => false,
            'dialogs.edit' => true,
            'dialogs.view' => true,
            'tags.delete' => false,
            'tags.edit' => true,
            'tags.view' => true,
            'users.delete' => false,
            'users.edit' => false,
            'users.view' => false,
        ], $employeeMatrix);
    }
}
