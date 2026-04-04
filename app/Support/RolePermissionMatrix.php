<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RolePermissionMatrix
{
    /**
     * @return array{
     *     roles: list<array{key:string,label:string,tone:string}>,
     *     groups: list<array{
     *         key:string,
     *         label:string,
     *         description:string,
     *         actions:list<array{
     *             code:string,
     *             label:string,
     *             description:string,
     *             isPreparatory: bool,
     *             preparatoryLabel: ?string,
     *             preparatoryDescription: ?string,
     *             states: array<string, array{allowed:bool,label:string,tone:string,status:string}>
     *         }>
     *     }>
     * }
     */
    public function build(): array
    {
        $roles = $this->roles();
        $databaseStates = $this->databaseStates(array_keys($roles));

        return [
            'roles' => array_map(
                fn (array $role): array => $role,
                array_values($roles),
            ),
            'groups' => array_map(
                fn (array $group): array => [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'description' => $group['description'],
                    'actions' => $this->buildActions($group['actions'], $roles, $databaseStates),
                ],
                app(RolePermissionCatalog::class)->groups(),
            ),
        ];
    }

    /**
     * @param  array<int, array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     isPreparatory: bool,
     *     preparatoryLabel: ?string,
     *     preparatoryDescription: ?string
     * }>  $actions
     * @param  array<string, array{key:string,label:string,tone:string}>  $roles
     * @param  array<string, array<string, bool>>  $databaseStates
     * @return list<array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     isPreparatory: bool,
     *     preparatoryLabel: ?string,
     *     preparatoryDescription: ?string,
     *     states: array<string, array{allowed:bool,label:string,tone:string,status:string}>
     * }>
     */
    protected function buildActions(array $actions, array $roles, array $databaseStates): array
    {
        return array_map(function (array $action) use ($roles, $databaseStates): array {
            $states = [];

            foreach ($roles as $role) {
                $states[$role['key']] = $this->resolveState(
                    $role['key'],
                    $action['code'],
                    $databaseStates,
                );
            }

            return [
                'code' => $action['code'],
                'label' => $action['label'],
                'description' => $action['description'],
                'isPreparatory' => $action['isPreparatory'],
                'preparatoryLabel' => $action['preparatoryLabel'],
                'preparatoryDescription' => $action['preparatoryDescription'],
                'states' => $states,
            ];
        }, $actions);
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, array<string, bool>>
     */
    protected function databaseStates(array $roles): array
    {
        $states = [];

        $rows = DB::table('role_permissions')
            ->select(['role', 'permission_key', 'granted'])
            ->whereIn('role', $roles)
            ->get();

        foreach ($rows as $row) {
            $states[$row->role][$row->permission_key] = (bool) $row->granted;
        }

        return $states;
    }

    /**
     * @return array{allowed:bool,label:string,tone:string,status:string}
     */
    protected function resolveState(string $role, string $permissionKey, array $databaseStates): array
    {
        $granted = $databaseStates[$role][$permissionKey] ?? null;

        if ($granted === null) {
            return [
                'allowed' => false,
                'label' => 'Нет записи',
                'tone' => 'warning',
                'status' => 'missing',
            ];
        }

        return [
            'allowed' => $granted,
            'label' => $granted ? 'Включено' : 'Выключено',
            'tone' => $granted ? 'success' : 'gray',
            'status' => $granted ? 'enabled' : 'disabled',
        ];
    }

    /**
     * @return array<string, array{key:string,label:string,tone:string}>
     */
    protected function roles(): array
    {
        return [
            User::ROLE_ADMIN => [
                'key' => User::ROLE_ADMIN,
                'label' => 'Администратор',
                'tone' => 'warning',
            ],
            User::ROLE_EMPLOYEE => [
                'key' => User::ROLE_EMPLOYEE,
                'label' => 'Сотрудник',
                'tone' => 'info',
            ],
        ];
    }
}
