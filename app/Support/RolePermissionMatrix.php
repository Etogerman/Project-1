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
     *             isRuntimeActive: bool,
     *             runtimeStatus: string,
     *             runtimeLabel: string,
     *             runtimeDescription: string,
     *             runtimeTone: string,
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
                $permissionKeys = $this->actionPermissionKeys($action);

                foreach ($roles as $role) {
                    $values = array_map(
                        fn (string $permissionKey): bool => (bool) ($databaseStates[$role][$permissionKey] ?? false),
                        $permissionKeys,
                    );

                    $state[$role][$resource][$ability] = ! in_array(false, $values, true);
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
     *     mirroredCodes?: list<string>,
     *     isRuntimeActive: bool,
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
     *     isRuntimeActive: bool,
     *     runtimeStatus: string,
     *     runtimeLabel: string,
     *     runtimeDescription: string,
     *     runtimeTone: string,
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
            $permissionKeys = $this->actionPermissionKeys($action);

            foreach ($roles as $role) {
                $states[$role['key']] = $this->resolveActionState(
                    $role['key'],
                    $permissionKeys,
                    $databaseStates,
                    $overrides,
                );
            }

            return [
                'code' => $action['code'],
                'label' => $action['label'],
                'description' => $action['description'],
                'isRuntimeActive' => $action['isRuntimeActive'],
                'runtimeStatus' => $action['isRuntimeActive'] ? 'runtime-active' : 'config-only',
                'runtimeLabel' => $action['isRuntimeActive'] ? 'Уже влияет на доступ' : 'Пока только конфигурация',
                'runtimeDescription' => $action['isRuntimeActive']
                    ? 'Изменение этого права уже влияет на реальный доступ в системе.'
                    : 'Это право уже хранится в role_permissions, но runtime пока его не использует.',
                'runtimeTone' => $action['isRuntimeActive'] ? 'success' : 'gray',
                'isPreparatory' => $action['isPreparatory'],
                'preparatoryLabel' => $action['preparatoryLabel'],
                'preparatoryDescription' => $action['preparatoryDescription'],
                'states' => $states,
            ];
        }, $actions);
    }

    /**
     * @param  array{
     *     code:string,
     *     mirroredCodes?: list<string>
     * }  $action
     * @return list<string>
     */
    protected function actionPermissionKeys(array $action): array
    {
        return array_values(array_unique([
            $action['code'],
            ...($action['mirroredCodes'] ?? []),
        ]));
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

    /**
     * @param  list<string>  $permissionKeys
     * @param  array<string, array<string, bool>>  $databaseStates
     * @param  array<string, mixed>|null  $overrides
     * @return array{allowed:bool,label:string,tone:string,status:string,editable:bool,lockReason:?string}
     */
    protected function resolveActionState(string $role, array $permissionKeys, array $databaseStates, ?array $overrides): array
    {
        $states = array_map(
            fn (string $permissionKey): array => $this->resolveState($role, $permissionKey, $databaseStates, $overrides),
            $permissionKeys,
        );

        if (in_array('missing', array_column($states, 'status'), true)) {
            return [
                'allowed' => false,
                'label' => 'Нет записи',
                'tone' => 'warning',
                'status' => 'missing',
                'editable' => ! in_array(false, array_column($states, 'editable'), true),
                'lockReason' => collect(array_column($states, 'lockReason'))->filter()->first(),
            ];
        }

        $allowed = ! in_array(false, array_column($states, 'allowed'), true);

        return [
            'allowed' => $allowed,
            'label' => $allowed ? 'Включено' : 'Выключено',
            'tone' => $allowed ? 'success' : 'gray',
            'status' => $allowed ? 'enabled' : 'disabled',
            'editable' => ! in_array(false, array_column($states, 'editable'), true),
            'lockReason' => collect(array_column($states, 'lockReason'))->filter()->first(),
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
