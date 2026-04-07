<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE_ADMIN = 'admin';

    private const ROLE_EMPLOYEE = 'employee';

    /**
     * @var list<string>
     */
    private const PERMISSION_KEYS = [
        'tags.view',
        'tags.edit',
        'tags.delete',
    ];

    /**
     * @var array<string, bool>
     */
    private const EMPLOYEE_GRANTED = [
        'tags.view' => true,
        'tags.edit' => true,
        'tags.delete' => false,
    ];

    public function up(): void
    {
        $timestamp = now();

        DB::table('role_permissions')->upsert(
            $this->buildRecords($timestamp),
            ['role', 'permission_key'],
            ['granted', 'updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('role', [self::ROLE_ADMIN, self::ROLE_EMPLOYEE])
            ->whereIn('permission_key', self::PERMISSION_KEYS)
            ->delete();
    }

    /**
     * @return list<array{
     *     role: string,
     *     permission_key: string,
     *     granted: bool,
     *     created_at: \Illuminate\Support\Carbon,
     *     updated_at: \Illuminate\Support\Carbon
     * }>
     */
    private function buildRecords(\Illuminate\Support\Carbon $timestamp): array
    {
        $records = [];

        foreach (self::PERMISSION_KEYS as $permissionKey) {
            $records[] = [
                'role' => self::ROLE_ADMIN,
                'permission_key' => $permissionKey,
                'granted' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $records[] = [
                'role' => self::ROLE_EMPLOYEE,
                'permission_key' => $permissionKey,
                'granted' => self::EMPLOYEE_GRANTED[$permissionKey],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        return $records;
    }
};
