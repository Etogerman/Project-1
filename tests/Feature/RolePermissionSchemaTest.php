<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RolePermissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permissions_table_exists_and_is_seeded_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('role_permissions'));
        $this->assertSame(
            ['id', 'role', 'permission_key', 'granted', 'created_at', 'updated_at'],
            Schema::getColumnListing('role_permissions'),
        );

        $adminPermissionCount = DB::table('role_permissions')->where('role', User::ROLE_ADMIN)->count();
        $employeePermissionCount = DB::table('role_permissions')->where('role', User::ROLE_EMPLOYEE)->count();

        $this->assertGreaterThan(0, $adminPermissionCount);
        $this->assertSame($adminPermissionCount, $employeePermissionCount);
        $this->assertSame($adminPermissionCount + $employeePermissionCount, DB::table('role_permissions')->count());
    }

    public function test_role_and_permission_key_pair_is_unique(): void
    {
        DB::table('role_permissions')->insert([
            'role' => 'custom',
            'permission_key' => 'custom.permission',
            'granted' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('role_permissions')->insert([
            'role' => 'custom',
            'permission_key' => 'custom.permission',
            'granted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
