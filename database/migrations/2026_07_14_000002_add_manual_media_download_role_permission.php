<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'download_media_manual';

    public function up(): void
    {
        $timestamp = now();

        DB::table('role_permissions')->upsert([
            [
                'role' => 'admin',
                'permission_key' => self::PERMISSION,
                'granted' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'role' => 'employee',
                'permission_key' => self::PERMISSION,
                'granted' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ], ['role', 'permission_key'], ['granted', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission_key', self::PERMISSION)
            ->delete();
    }
};
