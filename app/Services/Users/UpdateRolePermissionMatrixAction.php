<?php

namespace App\Services\Users;

use App\Models\User;
use App\Support\RolePermissionCatalog;
use App\Support\RolePermissionMatrix;
use Illuminate\Support\Facades\DB;

class UpdateRolePermissionMatrixAction
{
    public function handle(array $permissionState): array
    {
        $timestamp = now();
        $forcedAssignments = [];
        $records = [];

        foreach ($this->roles() as $role) {
            foreach ($this->permissionKeys() as $permissionKey) {
                $granted = (bool) data_get($permissionState, sprintf('%s.%s', $role, $permissionKey), false);

                if ($this->rolePermissionMatrix()->isProtectedAssignment($role, $permissionKey)) {
                    if (! $granted) {
                        $forcedAssignments[] = sprintf('%s.%s', $role, $permissionKey);
                    }

                    $granted = true;
                }

                $records[] = [
                    'role' => $role,
                    'permission_key' => $permissionKey,
                    'granted' => $granted,
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

        return $forcedAssignments;
    }

    /**
     * @return list<string>
     */
    protected function roles(): array
    {
        return [
            User::ROLE_ADMIN,
            User::ROLE_EMPLOYEE,
        ];
    }

    /**
     * @return list<string>
     */
    protected function permissionKeys(): array
    {
        return collect(app(RolePermissionCatalog::class)->groups())
            ->pluck('actions')
            ->flatten(1)
            ->pluck('code')
            ->values()
            ->all();
    }

    protected function rolePermissionMatrix(): RolePermissionMatrix
    {
        return app(RolePermissionMatrix::class);
    }
}
