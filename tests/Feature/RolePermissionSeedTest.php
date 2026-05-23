<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permissions_table_is_fully_seeded_for_admin_and_employee(): void
    {
        $this->assertSame(
            count($this->expectedDatabasePermissionKeys()) * 2,
            DB::table('role_permissions')->count(),
        );

        $this->assertSame(
            $this->expectedDatabasePermissionKeys(),
            DB::table('role_permissions')
                ->where('role', User::ROLE_ADMIN)
                ->orderBy('permission_key')
                ->pluck('permission_key')
                ->all(),
        );

        $this->assertSame(
            $this->expectedDatabasePermissionKeys(),
            DB::table('role_permissions')
                ->where('role', User::ROLE_EMPLOYEE)
                ->orderBy('permission_key')
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
            'analytics.debug' => true,
            'analytics.view' => true,
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
            'scenarios.archive' => true,
            'scenarios.edit' => true,
            'scenarios.view' => true,
            'tags.delete' => true,
            'tags.edit' => true,
            'tags.view' => true,
            'users.delete' => true,
            'users.edit' => true,
            'users.view' => true,
        ], $adminMatrix);

        $this->assertSame([
            'analytics.debug' => false,
            'analytics.view' => false,
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
            'scenarios.archive' => false,
            'scenarios.edit' => false,
            'scenarios.view' => false,
            'tags.delete' => false,
            'tags.edit' => true,
            'tags.view' => true,
            'users.delete' => false,
            'users.edit' => false,
            'users.view' => false,
        ], $employeeMatrix);
    }

    /**
     * @return list<string>
     */
    private function expectedDatabasePermissionKeys(): array
    {
        return [
            'analytics.debug',
            'analytics.view',
            'auto_reply_rules.delete',
            'auto_reply_rules.edit',
            'auto_reply_rules.view',
            'bitrix24.delete',
            'bitrix24.edit',
            'bitrix24.view',
            'channels.delete',
            'channels.edit',
            'channels.view',
            'contacts.delete',
            'contacts.edit',
            'contacts.view',
            'dialogs.delete',
            'dialogs.edit',
            'dialogs.view',
            'scenarios.archive',
            'scenarios.edit',
            'scenarios.view',
            'tags.delete',
            'tags.edit',
            'tags.view',
            'users.delete',
            'users.edit',
            'users.view',
        ];
    }
}
