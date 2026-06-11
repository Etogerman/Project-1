# Dialog Workspace

## Коротко

- `Contact` — overview карточка клиента с профилем, ownership, сбором данных, телефонами и списком диалогов.
- `Dialog` — основное рабочее место оператора для одного канала переписки.
- Manual reply отправляется со страницы диалога через точный `dialog_id`.
- Ownership по-прежнему живёт на `Contact`, а не на `Dialog`.

## Текущая модель

В текущем runtime контакт и диалог разделены намеренно.

- `Contact` отвечает на вопрос: кто это, в каком состоянии профиль, кто ответственный и какие у клиента есть диалоги.
- `Dialog` отвечает на вопрос: что происходило в конкретном канале и что оператор должен отправить дальше.

Это означает:

- contact modal больше не является chat workspace;
- dialog page является основной точкой работы с перепиской;
- ownership-семантика не переехала на уровень диалога.

## Страница контакта

Текущая страница контакта — overview-only.

Она содержит:

- профиль контакта;
- дедупликацию;
- сбор данных;
- ownership controls;
- телефоны;
- список диалогов;
- подробности.

На странице контакта намеренно отсутствуют:

- full chat history;
- рабочий reply composer;
- старый блок `Последнее сообщение`.

Карточка диалога на странице контакта:

- показывает route/channel metadata;
- показывает preview последнего сообщения;
- показывает sender badge preview-сообщения;
- ведёт на страницу диалога.

Reference points:

- `app/Filament/Resources/Contacts/ContactResource.php`
- `resources/views/filament/contacts/partials/contact-dialogs.blade.php`
- `app/Services/Dialogs/LoadContactDialogsOverviewAction.php`

## Страница диалога

Страница диалога открывается по URL:

- `/admin/dialogs/{dialog}`

Она содержит:

- шапку диалога с channel/route metadata;
- компактный блок контакта;
- историю сообщений только этого канала;
- блок `Ответ`.

Reference points:

- `app/Filament/Resources/Dialogs/DialogResource.php`
- `app/Filament/Resources/Dialogs/Pages/ViewDialog.php`
- `resources/views/filament/dialogs/pages/view-dialog.blade.php`

## История сообщений

История на странице диалога работает только по `dialog_id`.

Текущий контракт:

- initial load — последние `50` сообщений;
- вверху чата есть кнопка `Загрузить более ранние сообщения`;
- более ранняя история грузится порциями по `50`;
- older batches prepend-ятся вверх;
- когда история закончилась, кнопка скрывается;
- пустой диалог показывает standard empty state.

Технически страница не должна использовать full contact history query. История и overview строятся через dialog-scoped loaders.

Reference points:

- `app/Services/Dialogs/LoadDialogMessagesPageAction.php`
- `app/Services/Dialogs/BuildConversationFeedViewDataAction.php`
- `resources/views/filament/contacts/partials/conversation-chat.blade.php`

## Manual Reply

Manual reply идёт со страницы диалога, а не со страницы контакта.

Инварианты:

- reply отправляется через exact dialog route;
- `reply_to_message_id` берётся из последнего inbound этого же диалога;
- ownership проверяется на уровне контакта;
- unassigned contact может auto-claim-иться при первом ручном reply;
- foreign assignee блокирует reply;
- отдельный dialog assignee не введён.

Reference point:

- `app/Services/Bots/SendManualDialogReplyAction.php`

## Merge-совместимость

Dialog workflow совместим с текущим merge runtime.

- после merge диалог живёт как часть effective root contact;
- страница диалога показывает текущий `dialog->contact`;
- contact overview показывает текущие dialogs root-контакта;
- отдельная merge-страница для переписки не нужна.

## Технические инварианты для разработки

- Не возвращать full history и reply composer на страницу контакта без отдельного ТЗ.
- Не вводить dialog-level ownership без отдельного ТЗ.
- Не отправлять reply со страницы диалога через “любой подходящий dialog”.
- Не строить preview карточки диалога через full contact history query.
- Dialog page остаётся primary chat workspace.

## Что пока не входит в модель

- dialogs inbox;
- unread counters;
- archive/close dialog;
- отдельный assignee у dialog;
- attachment-specific preview categories вне текущей message-модели.

## Smoke-check после изменений

После любых правок, затрагивающих contacts/dialogs UI, быстро проверить:

1. contact modal остаётся overview-only;
2. карточка диалога ведёт на `/admin/dialogs/{dialog}`;
3. dialog page показывает только сообщения этого канала;
4. manual reply уходит со страницы диалога;
5. contact page не вернула full history, reply composer или блок `Последнее сообщение`.
