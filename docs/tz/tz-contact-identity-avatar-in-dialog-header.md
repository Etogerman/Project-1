# ТЗ: аватарка клиента в `ContactIdentity` и header диалога

## 1. Контекст

Сейчас рабочее место оператора на странице диалога показывает имя клиента,
канал и route metadata, но не показывает аватарку клиента.

Текущее состояние проекта:
1. у `ContactIdentity` нет поля для хранения аватарки;
2. входящий DTO `IncomingBotMessage` не несёт avatar/photo;
3. Telegram webhook не даёт аватарку пользователя напрямую;
4. MAX может прислать `avatar_url` / `full_avatar_url` в `message.sender`,
   но текущий runtime это не использует;
5. header диалога сейчас не умеет рендерить аватарку и fallback-аватар.

Ценность slice:
1. оператор видит визуальный идентификатор клиента прямо в header диалога;
2. аватарка живёт на уровне канальной identity, а не размазывается по
   `Contact` / `Dialog`;
3. UI получает полезное улучшение без новой media-library и без расширения
   scope на списки контактов и диалогов.

## 2. Цель

Сделать так, чтобы:
1. у `ContactIdentity` можно было локально хранить аватарку клиента;
2. Telegram identity могла получить текущую аватарку через официальный Bot API;
3. MAX identity могла сохранить аватарку, если она пришла во входящем payload;
4. header страницы диалога показывал аватарку текущей `ContactIdentity`;
5. при отсутствии аватарки UI стабильно показывал fallback без ошибок.

## 3. Scope

Входит:
1. data-model для аватарки на уровне `contact_identities`;
2. локальное хранение аватарки на `public` disk;
3. загрузка и сохранение аватарки для Telegram private dialog через
   `getChat(chat_id)` + `getFile`;
4. загрузка и сохранение аватарки для MAX, если входящий `message.sender`
   содержит `avatar_url` или `full_avatar_url`;
5. показ аватарки в header страницы диалога;
6. fallback-рендер header без аватарки;
7. feature / UI tests под новый contract.

Не входит:
1. backfill старых `ContactIdentity`;
2. отдельная media-library или новая таблица файлов;
3. карточка контакта;
4. список контактов;
5. список диалогов;
6. фоновые cron / queue refresh policy для аватарок;
7. отдельный API MAX для догрузки аватарки, если в payload её нет;
8. production rollout, `merge`, `deploy`, dangerous ops.

## 4. Source tree

Source of truth для implementation этого slice:
1. `origin/staging`;
2. текущий Laravel runtime проекта;
3. официальные provider docs для Telegram Bot API и MAX user/message model.

Все implementation ветки и review для этого slice стартуют от `origin/staging`.

## 5. Архитектурная модель

### 5.1. Уровень хранения

Аватарка хранится на уровне `ContactIdentity`, а не на уровне `Contact`
и не на уровне `Dialog`.

Причина:
1. одна и та же персона может иметь разные identity по разным каналам;
2. у Telegram и MAX могут быть разные аватарки;
3. `Dialog` только показывает current identity, но не владеет её profile media.

### 5.2. Формат хранения

Для первого slice хранить:
1. `avatar_path` nullable;
2. `avatar_updated_at` nullable.

Хранение внешнего `avatar_url` как source-of-truth не использовать.

Причина:
1. внешние URL могут протухать;
2. provider может требовать повторную авторизацию;
3. UI должен опираться на локально стабильный asset.

### 5.3. Storage contract

Аватарки сохраняются локально на `public` disk.

Ожидаемый path namespace:
1. `contact-identities/{contact_identity_id}/avatar/{hash-or-filename}`

Implementation может выбрать точный filename pattern, но обязана:
1. не писать в корень диска;
2. не смешивать аватарки разных identity;
3. перезаписывать или заменять старый avatar asset контролируемо.

### 5.4. UI contract

Header страницы диалога показывает:
1. круглую аватарку текущей `ContactIdentity`, если она есть;
2. fallback-аватар, если `avatar_path` отсутствует или файл недоступен.

Fallback:
1. инициалы из display name, если их можно собрать;
2. иначе нейтральная иконка / placeholder.

## 6. Provider contract

### 6.1. Telegram

Telegram webhook сам по себе не даёт photo в `User`.

Для Telegram private dialog система должна:
1. определить `chat_id` текущей identity;
2. запросить `getChat(chat_id)`;
3. если `chat.photo` отсутствует — не считать это ошибкой;
4. если `chat.photo` есть — взять актуальный file reference;
5. получить file path через `getFile`;
6. скачать файл и сохранить локально на `public` disk;
7. обновить `avatar_path` и `avatar_updated_at`.

Если любой из шагов Telegram avatar fetch падает:
1. это не должно ломать ingest входящего сообщения;
2. identity и dialog продолжают жить без аватарки;
3. runtime пишет best-effort log / diagnostic event, но не роняет dialog.

### 6.2. MAX

Для MAX в этом slice используется только то, что уже приходит во входящем
message payload.

Если `message.sender` содержит:
1. `full_avatar_url` — использовать его как приоритетный источник;
2. иначе `avatar_url` — использовать его как fallback-source.

Далее система должна:
1. скачивать файл только по `https` URL с trusted host внутри домена `max.ru`;
2. не следовать redirect chain при загрузке MAX avatar;
3. определять расширение локального файла только по allowlist image `Content-Type`, а не по URL;
4. сохранить файл локально на `public` disk;
5. обновить `avatar_path` и `avatar_updated_at`.

Если во входящем MAX payload нет avatar fields:
1. дополнительных provider API call в этом slice не делать;
2. identity остаётся без аватарки;
3. UI показывает fallback.

Если скачивание MAX avatar падает:
1. ingest входящего сообщения не должен ломаться;
2. dialog и identity продолжают жить без аватарки;
3. это считается best-effort failure, а не message processing failure.

## 7. Точки изменения

Основные точки для implementation:
1. миграция для `contact_identities`;
2. `app/Models/ContactIdentity.php`;
3. DTO / normalizer path для MAX avatar fields;
4. Telegram profile/avatar fetch action;
5. сервис сохранения avatar asset в storage;
6. wiring в `StoreInboundMessageAction` или соседнем identity-update path;
7. `app/Filament/Resources/Dialogs/Pages/ViewDialog.php`;
8. `resources/views/filament/dialogs/pages/view-dialog.blade.php`.

## 8. Поведение обновления

### 8.1. Когда обновлять аватарку

Обновление допускается:
1. при создании новой `ContactIdentity`;
2. при обработке нового входящего сообщения, если identity уже существует;
3. для Telegram — best-effort fetch текущей аватарки при inbound activity;
4. для MAX — при наличии avatar fields в новом payload.

### 8.2. Когда не обновлять

В этом slice не делать:
1. периодический refresh без входящих сообщений;
2. массовый backfill существующих identity;
3. manual avatar upload из админки.

## 9. Что нельзя сломать

1. ingest входящих сообщений Telegram и MAX;
2. идемпотентность message processing;
3. current dialog header layout без аватарки;
4. active collector и manual reply workflow;
5. существующие profile/name updates в `ContactIdentity`.

## 10. Тестовая стратегия

Нужны таргетные tests на:
1. миграцию и nullable data model;
2. Telegram path:
   - `getChat` вернул photo -> файл сохранён, `avatar_path` обновлён;
   - photo отсутствует -> message ingest проходит, fallback остаётся;
   - fetch/download failure -> message ingest не падает;
3. MAX path:
   - `full_avatar_url` пришёл -> файл сохранён;
   - только `avatar_url` пришёл -> файл сохранён;
   - avatar field отсутствует -> ingest не падает, fallback остаётся;
4. UI:
   - header показывает avatar image, если `avatar_path` есть;
   - header показывает fallback, если `avatar_path` нет;
   - смена `current_contact_identity_id` меняет avatar в header.

## 11. Acceptance criteria

Slice считается успешным на `staging`, если подтверждено:
1. у `ContactIdentity` есть локально сохраняемая аватарка;
2. Telegram private dialog может получить и показать текущую аватарку клиента;
3. MAX dialog может сохранить и показать аватарку, если она пришла в payload;
4. отсутствие аватарки не ломает runtime и не роняет UI;
5. header диалога показывает либо аватарку, либо стабильный fallback;
6. feature / UI tests закрепляют новый contract.

## 12. Известные компромиссы

1. старые identity без новых входящих останутся без аватарки;
2. MAX path в этом slice не пытается добывать avatar вне payload;
3. не вводится общая файловая подсистема для всех медиа проекта;
4. списки контактов и диалогов сознательно не получают аватарки в этом шаге.
