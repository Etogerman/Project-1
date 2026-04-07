<?php

namespace App\Support;

class RolePermissionCatalog
{
    /**
     * @return list<array{
     *     key:string,
     *     label:string,
     *     description:string,
     *     actions:list<array{
     *         code:string,
     *         label:string,
     *         description:string,
     *         isRuntimeActive: bool,
     *         isPreparatory: bool,
     *         preparatoryLabel: ?string,
     *         preparatoryDescription: ?string
     *     }>
     * }>
     */
    public function groups(): array
    {
        return [
            [
                'key' => 'contacts',
                'label' => 'Контакты',
                'description' => 'Крупные права для клиентской карточки и списка контактов.',
                'actions' => [
                    [
                        'code' => 'contacts.view',
                        'label' => 'Просмотр',
                        'description' => 'Список контактов и карточка клиента.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'contacts.edit',
                        'label' => 'Редактирование',
                        'description' => 'Крупное право для изменения данных и рабочих действий в карточке клиента.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'contacts.delete',
                        'label' => 'Удаление',
                        'description' => 'Полное удаление клиента и связанной истории.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                ],
            ],
            [
                'key' => 'dialogs',
                'label' => 'Диалоги',
                'description' => 'Крупные права для списка диалогов и рабочего места оператора.',
                'actions' => [
                    [
                        'code' => 'dialogs.view',
                        'label' => 'Просмотр',
                        'description' => 'Список диалогов, страница диалога и история сообщений.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'dialogs.edit',
                        'label' => 'Редактирование',
                        'description' => 'Крупное право для рабочих действий в диалоге. На текущем контуре напрямую соответствует ручному ответу.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                ],
            ],
            [
                'key' => 'tags',
                'label' => 'Теги',
                'description' => 'Крупные права для справочника тегов и их управления.',
                'actions' => [
                    [
                        'code' => 'tags.view',
                        'label' => 'Просмотр',
                        'description' => 'Доступ к разделу тегов и просмотру списка.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'tags.edit',
                        'label' => 'Редактирование',
                        'description' => 'Создание и изменение тегов в панели.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'tags.delete',
                        'label' => 'Удаление',
                        'description' => 'Удаление тегов, которые не используются контактами или правилами.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                ],
            ],
            [
                'key' => 'system',
                'label' => 'Системные разделы',
                'description' => 'Крупные права для административных разделов и настроек панели.',
                'actions' => [
                    [
                        'code' => 'users.view',
                        'label' => 'Просмотр',
                        'description' => 'Доступ к разделу сотрудников и просмотру команды.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'users.edit',
                        'label' => 'Редактирование',
                        'description' => 'Создание и изменение сотрудников в панели.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'channels.view',
                        'label' => 'Просмотр',
                        'description' => 'Просмотр списка каналов связи и их карточек.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'channels.edit',
                        'label' => 'Редактирование',
                        'description' => 'Создание и изменение каналов связи.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'auto_reply_rules.view',
                        'label' => 'Просмотр',
                        'description' => 'Просмотр правил автоматической обработки сообщений.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'auto_reply_rules.edit',
                        'label' => 'Редактирование',
                        'description' => 'Создание и изменение правил автоматической обработки сообщений.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'auto_reply_rules.delete',
                        'label' => 'Удаление',
                        'description' => 'Удаление правил автоматической обработки сообщений.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                    [
                        'code' => 'bitrix24.view',
                        'label' => 'Просмотр',
                        'description' => 'Диагностика и состояние подключения Bitrix24.',
                        'isRuntimeActive' => true,
                        'isPreparatory' => false,
                        'preparatoryLabel' => null,
                        'preparatoryDescription' => null,
                    ],
                ],
            ],
        ];
    }
}
