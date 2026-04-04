<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RolePermissionMatrix
{
    /**
     * @var array<string, array<string, string>>
     */
    private const PROTECTED_ASSIGNMENTS = [
        User::ROLE_ADMIN => [
            'users.view' => 'Это право остаётся включённым, чтобы администратор не потерял доступ к управлению сотрудниками.',
            'users.edit' => 'Это право остаётся включённым, чтобы администратор мог восстановить конфигурацию прав из панели.',
        ],
    ];

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
     *             states: array<string, array{
     *                 allowed:bool,
     *                 label:string,
     *                 tone:string,
     *                 status:string,
     *                 editable:bool,
     *                 lockReason:?string
     *             }>
     *         }>
     *     }>
     * }
     */
    public function build(?array $overrides = null): array
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
                    'actions' => $this->buildActions($group['actions'], $roles, $databaseStates, $overrides),
                ],
                app(RolePermissionCatalog::class)->groups(),
            ),
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function editableState(): array
    {
        $roles = array_keys($this->roles());
        $databaseStates = $this->databaseStates($roles);
        $state = [];

        foreach (app(RolePermissionCatalog::class)->groups() as $group) {
            foreach ($group['actions'] as $action) {
                [$resource, $ability] = explode('.', $action['code'], 2);

                foreach ($roles as $role) {
                    $state[$role][$resource][$ability] = (bool) ($databaseStates[$role][$action['code']] ?? false);
                }
            }
        }

        return $state;
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
     * @param  array<string, mixed>|null  $overrides
     * @return list<array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     isPreparatory: bool,
     *     preparatoryLabel: ?string,
     *     preparatoryDescription: ?string,
     *     states: array<string, array{
     *         allowed:bool,
     *         label:string,
     *         tone:string,
     *         status:string,
     *         editable:bool,
     *         lockReason:?string
     *     }>
     * }>
     */
    protected function buildActions(array $actions, array $roles, array $databaseStates, ?array $overrides): array
    {
        return array_map(function (array $action) use ($roles, $databaseStates, $overrides): array {
            $states = [];

            foreach ($roles as $role) {
                $states[$role['key']] = $this->resolveState(
                    $role['key'],
                    $action['code'],
                    $databaseStates,
                    $overrides,
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
     * @param  array<string, mixed>|null  $overrides
     * @return array{allowed:bool,label:string,tone:string,status:string,editable:bool,lockReason:?string}
     */
    protected function resolveState(string $role, string $permissionKey, array $databaseStates, ?array $overrides): array
    {
        $granted = data_get($overrides, sprintf('%s.%s', $role, $permissionKey));

        if ($granted === null) {
            $granted = $databaseStates[$role][$permissionKey] ?? null;
        }

        $editable = ! $this->isProtectedAssignment($role, $permissionKey);
        $lockReason = $this->protectedAssignmentReason($role, $permissionKey);

        if ($granted === null) {
            return [
                'allowed' => false,
                'label' => 'Нет записи',
                'tone' => 'warning',
                'status' => 'missing',
                'editable' => $editable,
                'lockReason' => $lockReason,
            ];
        }

        return [
            'allowed' => (bool) $granted,
            'label' => $granted ? 'Включено' : 'Выключено',
            'tone' => $granted ? 'success' : 'gray',
            'status' => $granted ? 'enabled' : 'disabled',
            'editable' => $editable,
            'lockReason' => $lockReason,
        ];
    }

    public function isProtectedAssignment(string $role, string $permissionKey): bool
    {
        return array_key_exists($permissionKey, self::PROTECTED_ASSIGNMENTS[$role] ?? []);
    }

    public function protectedAssignmentReason(string $role, string $permissionKey): ?string
    {
        return self::PROTECTED_ASSIGNMENTS[$role][$permissionKey] ?? null;
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
