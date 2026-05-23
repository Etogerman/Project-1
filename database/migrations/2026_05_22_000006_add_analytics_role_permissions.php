<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PERMISSIONS = [
        'analytics.view',
        'analytics.debug',
    ];

    public function up(): void
    {
        $timestamp = now();
        $records = [];

        foreach (['admin', 'employee'] as $role) {
            foreach (self::PERMISSIONS as $permission) {
                $records[] = [
                    'role' => $role,
                    'permission_key' => $permission,
                    'granted' => $role === 'admin',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        DB::table('role_permissions')->upsert(
            $records,
            ['role', 'permission_key'],
            ['granted', 'updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission_key', self::PERMISSIONS)
            ->delete();
    }
};
