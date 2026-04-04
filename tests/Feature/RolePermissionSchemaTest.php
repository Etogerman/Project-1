<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RolePermissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permissions_table_exists_and_is_empty_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('role_permissions'));
        $this->assertSame(
            ['id', 'role', 'permission_key', 'granted', 'created_at', 'updated_at'],
            Schema::getColumnListing('role_permissions'),
        );
        $this->assertSame(0, DB::table('role_permissions')->count());
    }

    public function test_role_and_permission_key_pair_is_unique(): void
    {
        DB::table('role_permissions')->insert([
            'role' => 'admin',
            'permission_key' => 'contacts.view',
            'granted' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('role_permissions')->insert([
            'role' => 'admin',
            'permission_key' => 'contacts.view',
            'granted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
