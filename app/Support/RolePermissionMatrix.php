<?php

namespace App\Support;

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
                    'actions' => $this->buildActions($group['actions'], $roles),
                ],
                $this->definitions(),
            ),
        ];
    }

    /**
     * @param  array<int, array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     resolver: \Closure(User): bool
     * }>  $actions
     * @param  array<string, array{key:string,label:string,tone:string,user:User}>  $roles
     * @return list<array{
     *     code:string,
     *     label:string,
     *     description:string,
     *     states: array<string, array{allowed:bool,label:string,tone:string}>
     * }>
     */
    protected function buildActions(array $actions, array $roles): array
    {
        return array_map(function (array $action) use ($roles): array {
            $resolver = $action['resolver'];
            $states = [];

            foreach ($roles as $role) {
                $allowed = (bool) $resolver($role['user']);

                $states[$role['key']] = [
                    'allowed' => $allowed,
                    'label' => $allowed ? 'Есть' : 'Нет',
                    'tone' => $allowed ? 'success' : 'gray',
                ];
            }

            return [
                'code' => $action['code'],
                'label' => $action['label'],
                'description' => $action['description'],
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
     * @return list<array{
     *     key:string,
     *     label:string,
     *     description:string,
     *     actions:list<array{
     *         code:string,
     *         label:string,
     *         description:string,
     *         resolver: \Closure(User): bool
     *     }>
     * }>
     */
    protected function definitions(): array
    {
        return [
            [
                'key' => 'workspace',
                'label' => 'Рабочий контур',
                'description' => 'Ежедневные действия оператора в контактах и диалогах.',
                'actions' => [
                    [
                        'code' => 'contacts.view',
                        'label' => 'Просматривать контакты',
                        'description' => 'Доступ к списку контактов и карточке клиента.',
                        'resolver' => fn (User $user): bool => app(ContactPolicy::class)->viewAny($user),
                    ],
                    [
                        'code' => 'dialogs.view',
                        'label' => 'Просматривать диалоги',
                        'description' => 'Доступ к списку диалогов и рабочему месту оператора.',
                        'resolver' => fn (User $user): bool => app(DialogPolicy::class)->viewAny($user),
                    ],
                    [
                        'code' => 'contacts.assignee.assign',
                        'label' => 'Назначать ответственного',
                        'description' => 'Можно выбрать любого активного сотрудника для контакта.',
                        'resolver' => fn (User $user): bool => $user->canManageContactOwnership(),
                    ],
                    [
                        'code' => 'contacts.assignee.clear',
                        'label' => 'Назначать «Свободен»',
                        'description' => 'Можно снять назначение и вернуть контакт в свободный пул.',
                        'resolver' => fn (User $user): bool => $user->canManageContactOwnership(),
                    ],
                    [
                        'code' => 'dialogs.reply',
                        'label' => 'Отвечать вручную в диалоге',
                        'description' => 'Ручной ответ оператором без изменения маршрута диалога.',
                        'resolver' => fn (User $user): bool => $user->canReplyInDialogs(),
                    ],
                ],
            ],
            [
                'key' => 'customer-data',
                'label' => 'Данные клиента',
                'description' => 'Поддержка операторских данных в карточке контакта.',
                'actions' => [
                    [
                        'code' => 'contacts.profile.update',
                        'label' => 'Редактировать профиль контакта',
                        'description' => 'Имя, пол, возраст и локация клиента.',
                        'resolver' => fn (User $user): bool => $user->canManageContactProfile(),
                    ],
                    [
                        'code' => 'contacts.phone.edit_existing',
                        'label' => 'Редактировать существующий телефон',
                        'description' => 'Исправление уже сохранённого номера телефона.',
                        'resolver' => fn (User $user): bool => $user->canEditExistingContactPhones(),
                    ],
                    [
                        'code' => 'contacts.phone.delete_existing',
                        'label' => 'Удалять существующий телефон',
                        'description' => 'Удаление номера и перерасчёт основного телефона по текущим правилам.',
                        'resolver' => fn (User $user): bool => $user->canManageContactWorkspaceMutations(),
                    ],
                ],
            ],
            [
                'key' => 'dangerous-actions',
                'label' => 'Опасные действия',
                'description' => 'Операции, которые влияют на автоматику и жизненный цикл контакта.',
                'actions' => [
                    [
                        'code' => 'contacts.auto_reply.manage',
                        'label' => 'Управлять автоответами контакта',
                        'description' => 'Включение и выключение автоматических ответов для клиента.',
                        'resolver' => fn (User $user): bool => $user->canManageContactWorkspaceMutations(),
                    ],
                    [
                        'code' => 'contacts.data_collection.resume',
                        'label' => 'Возобновлять анкету',
                        'description' => 'Ручной запуск следующего шага анкеты контакта.',
                        'resolver' => fn (User $user): bool => $user->canManageContactWorkspaceMutations(),
                    ],
                    [
                        'code' => 'contacts.delete',
                        'label' => 'Удалять контакт',
                        'description' => 'Полное удаление клиента и связанной истории.',
                        'resolver' => fn (User $user): bool => $user->canManageContactWorkspaceMutations(),
                    ],
                ],
            ],
            [
                'key' => 'system',
                'label' => 'Системные настройки',
                'description' => 'Административный контур панели и интеграций.',
                'actions' => [
                    [
                        'code' => 'users.manage',
                        'label' => 'Управлять сотрудниками',
                        'description' => 'Просмотр и изменение команды внутри панели.',
                        'resolver' => fn (User $user): bool => app(UserPolicy::class)->viewAny($user),
                    ],
                    [
                        'code' => 'channels.manage',
                        'label' => 'Управлять каналами связи',
                        'description' => 'Настройка подключённых мессенджеров и их параметров.',
                        'resolver' => fn (User $user): bool => app(ChannelPolicy::class)->viewAny($user),
                    ],
                    [
                        'code' => 'auto_reply_rules.manage',
                        'label' => 'Управлять правилами автоответа',
                        'description' => 'Создание и изменение правил автоматической обработки сообщений.',
                        'resolver' => fn (User $user): bool => app(AutoReplyRulePolicy::class)->viewAny($user),
                    ],
                    [
                        'code' => 'bitrix24.view',
                        'label' => 'Просматривать Bitrix24',
                        'description' => 'Доступ к диагностике и состоянию подключения Bitrix24.',
                        'resolver' => fn (User $user): bool => app(Bitrix24ConnectionPolicy::class)->viewAny($user),
                    ],
                ],
            ],
        ];
    }
}
