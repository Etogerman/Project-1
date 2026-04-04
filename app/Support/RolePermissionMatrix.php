<?php

namespace App\Support;

use App\Models\AutoReplyRule;
use App\Models\User;
use App\Policies\AutoReplyRulePolicy;
use App\Policies\Bitrix24ConnectionPolicy;
use App\Policies\ChannelPolicy;
use App\Policies\ContactPolicy;
use App\Policies\DialogPolicy;
use App\Policies\UserPolicy;

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
     *             states: array<string, array{allowed:bool,label:string,tone:string}>
     *         }>
     *     }>
     * }
     */
    public function build(): array
    {
        $roles = $this->roles();

        return [
            'roles' => array_map(
                fn (array $role): array => [
                    'key' => $role['key'],
                    'label' => $role['label'],
                    'tone' => $role['tone'],
                ],
                array_values($roles),
            ),
            'groups' => array_map(
                fn (array $group): array => [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'description' => $group['description'],
                    'actions' => $this->buildActions($group['actions'], $roles, $this->runtimeResolvers()),
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
     * @param  array<string, array{key:string,label:string,tone:string,user:User}>  $roles
     * @param  array<string, \Closure(User): bool>  $runtimeResolvers
     * @return list<array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     isPreparatory: bool,
     *     preparatoryLabel: ?string,
     *     preparatoryDescription: ?string,
     *     states: array<string, array{allowed:bool,label:string,tone:string}>
     * }>
     */
    protected function buildActions(array $actions, array $roles, array $runtimeResolvers): array
    {
        return array_map(function (array $action) use ($roles, $runtimeResolvers): array {
            $resolver = $runtimeResolvers[$action['code']] ?? null;
            $isPreparatory = $action['isPreparatory'];
            $states = [];

            foreach ($roles as $role) {
                $allowed = $resolver instanceof \Closure
                    ? (bool) $resolver($role['user'])
                    : false;

                $states[$role['key']] = [
                    'allowed' => $allowed,
                    'label' => $isPreparatory
                        ? 'Не применяется'
                        : ($allowed ? 'Есть' : 'Нет'),
                    'tone' => $isPreparatory
                        ? 'gray'
                        : ($allowed ? 'success' : 'gray'),
                ];
            }

            return [
                'code' => $action['code'],
                'label' => $action['label'],
                'description' => $action['description'],
                'isPreparatory' => $isPreparatory,
                'preparatoryLabel' => $action['preparatoryLabel'],
                'preparatoryDescription' => $action['preparatoryDescription'],
                'states' => $states,
            ];
        }, $actions);
    }

    /**
     * @return array<string, array{key:string,label:string,tone:string,user:User}>
     */
    protected function roles(): array
    {
        return [
            User::ROLE_ADMIN => [
                'key' => User::ROLE_ADMIN,
                'label' => 'Администратор',
                'tone' => 'warning',
                'user' => $this->makeRoleUser(User::ROLE_ADMIN),
            ],
            User::ROLE_EMPLOYEE => [
                'key' => User::ROLE_EMPLOYEE,
                'label' => 'Сотрудник',
                'tone' => 'info',
                'user' => $this->makeRoleUser(User::ROLE_EMPLOYEE),
            ],
        ];
    }

    protected function makeRoleUser(string $role): User
    {
        $isAdmin = $role === User::ROLE_ADMIN;

        return new User([
            'name' => $isAdmin ? 'Администратор' : 'Сотрудник',
            'email' => $isAdmin ? 'admin@example.com' : 'employee@example.com',
            'is_active' => true,
            'is_admin' => $isAdmin,
            'role' => $role,
        ]);
    }

    /**
     * @return array<string, \Closure(User): bool>
     */
    protected function runtimeResolvers(): array
    {
        return [
            'contacts.view' => fn (User $user): bool => app(ContactPolicy::class)->viewAny($user),
            'contacts.delete' => fn (User $user): bool => $user->canManageContactWorkspaceMutations(),
            'dialogs.view' => fn (User $user): bool => app(DialogPolicy::class)->viewAny($user),
            'dialogs.edit' => fn (User $user): bool => $user->canReplyInDialogs(),
            'users.view' => fn (User $user): bool => app(UserPolicy::class)->viewAny($user),
            'users.edit' => fn (User $user): bool => app(UserPolicy::class)->create($user),
            'channels.view' => fn (User $user): bool => app(ChannelPolicy::class)->viewAny($user),
            'channels.edit' => fn (User $user): bool => app(ChannelPolicy::class)->create($user),
            'auto_reply_rules.view' => fn (User $user): bool => app(AutoReplyRulePolicy::class)->viewAny($user),
            'auto_reply_rules.edit' => fn (User $user): bool => app(AutoReplyRulePolicy::class)->create($user),
            'auto_reply_rules.delete' => fn (User $user): bool => app(AutoReplyRulePolicy::class)->delete($user, new AutoReplyRule()),
            'bitrix24.view' => fn (User $user): bool => app(Bitrix24ConnectionPolicy::class)->viewAny($user),
        ];
    }
}
