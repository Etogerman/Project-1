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
            foreach ($this->catalogActions() as $action) {
                $granted = (bool) data_get($permissionState, sprintf('%s.%s', $role, $action['code']), false);

                foreach ($this->actionPermissionKeys($action) as $permissionKey) {
                    if ($this->rolePermissionMatrix()->isProtectedAssignment($role, $permissionKey)) {
                        if (! $granted) {
                            $forcedAssignments[] = sprintf('%s.%s', $role, $permissionKey);
                        }

                        $granted = true;
                    }

                    $records[sprintf('%s:%s', $role, $permissionKey)] = [
                        'role' => $role,
                        'permission_key' => $permissionKey,
                        'granted' => $granted,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
            }
        }

        DB::table('role_permissions')->upsert(
            array_values($records),
            ['role', 'permission_key'],
            ['granted', 'updated_at'],
        );

        return array_values(array_unique($forcedAssignments));
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
     * @return list<array{code:string, mirroredCodes?: list<string>}>
     */
    protected function catalogActions(): array
    {
        return collect(app(RolePermissionCatalog::class)->groups())
            ->pluck('actions')
            ->flatten(1)
            ->values()
            ->all();
    }

    /**
     * @param  array{code:string, mirroredCodes?: list<string>}  $action
     * @return list<string>
     */
    protected function actionPermissionKeys(array $action): array
    {
        return array_values(array_unique([
            $action['code'],
            ...($action['mirroredCodes'] ?? []),
        ]));
    }

    protected function rolePermissionMatrix(): RolePermissionMatrix
    {
        return app(RolePermissionMatrix::class);
    }
}
