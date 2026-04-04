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
        'contacts.view',
        'contacts.edit',
        'contacts.delete',
        'dialogs.view',
        'dialogs.edit',
        'dialogs.delete',
        'users.view',
        'users.edit',
        'users.delete',
        'channels.view',
        'channels.edit',
        'channels.delete',
        'auto_reply_rules.view',
        'auto_reply_rules.edit',
        'auto_reply_rules.delete',
        'bitrix24.view',
        'bitrix24.edit',
        'bitrix24.delete',
    ];

    /**
     * @var array<string, bool>
     */
    private const EMPLOYEE_GRANTED = [
        'contacts.view' => true,
        'contacts.edit' => true,
        'contacts.delete' => false,
        'dialogs.view' => true,
        'dialogs.edit' => true,
        'dialogs.delete' => false,
        'users.view' => false,
        'users.edit' => false,
        'users.delete' => false,
        'channels.view' => false,
        'channels.edit' => false,
        'channels.delete' => false,
        'auto_reply_rules.view' => false,
        'auto_reply_rules.edit' => false,
        'auto_reply_rules.delete' => false,
        'bitrix24.view' => false,
        'bitrix24.edit' => false,
        'bitrix24.delete' => false,
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
