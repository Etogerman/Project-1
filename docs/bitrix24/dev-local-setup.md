# Bitrix24 Developer Local Setup

Этот документ описывает developer-путь для подключения своей локалки к общему
Bitrix24 staging-порталу через отдельный `dev-*` profile.

Он:

1. не заменяет [setup-sheet.md](/Users/abrikosov/Documents/Проект-1/docs/bitrix24/setup-sheet.md) для
   стабильного `staging`-профиля;
2. не описывает production setup;
3. фиксирует практический bootstrap-путь для разработчика.

## Роль локального Bitrix24-контура в проверках

Локальный AB Connector может быть подключён к Bitrix24 staging-порталу
через отдельный `dev-*` profile. Такой контур считается первым местом для
воспроизведения и проверки интеграционных ошибок до выхода в общий `staging`.

Для изменений в Open Lines, connector registration, callbacks, ручных ответах,
live export, contact/deal sync и других Bitrix24 runtime-path действует порядок:

1. сначала воспроизвести проблему локально на `dev-*` profile;
2. затем исправить код локально;
3. затем подтвердить локально, что проблема больше не воспроизводится;
4. только после этого идти в общий `staging` как в подтверждающий smoke-контур.

Если локальный контур не готов, не подключён к Bitrix24 или не может проверить
конкретный побочный эффект, это нужно явно назвать как blocker перед переходом
в `staging`.

Для мутирующих проверок, например отправки сообщений в Open Lines или проверки
методов, которые могут создать новую ОЛ, сначала используйте отдельный тестовый
контакт, тестовый диалог и test-only линию текущего `dev-*` profile. Не
используйте общий `staging`-контакт как первую попытку воспроизведения.

## Локальный preflight перед Bitrix24

Перед bootstrap, Doctor, publish registry или smoke через Bitrix24 сначала
закройте общий локальный preflight из
[local-bootstrap.md](/Users/abrikosov/Documents/Проект-1/docs/reference/local-bootstrap.md):

1. `APP_URL` или tunnel URL ведёт на ожидаемый локальный runtime;
2. runtime identity check показывает правильный контейнер или процесс;
3. смонтирован правильный worktree;
4. видимый в админке `rev` совпадает с commit проверяемой ветки;
5. подключена ожидаемая локальная база;
6. режим данных явно назван: clean / recovery / existing data / demo;
7. работают web-server, scheduler и очереди `bot-replies`, `bitrix-live`,
   `default`.

Если локальный контур является `recovery`, это не означает, что восстановлены
старые каналы, контакты, диалоги, сценарии или автоответчики. Перед Bitrix smoke
сначала подтвердите counts и ключевые экраны локальной админки.

Если нужно запускать тесты до Bitrix smoke, используйте только отдельную test
database. База runtime, recovery или база с локальными пользовательскими
данными не подходит для `php artisan test`.

## Что нужно заранее

Перед началом убедитесь, что у вас есть:

1. локально поднятый проект;
2. tunnel URL для вашей локалки;
3. доступ администратора к Bitrix24 staging-порталу;
4. отдельное Bitrix app именно под ваш `dev-*` profile.

По умолчанию staging-портал проекта:

- `portal_domain`: `stagecrm.fvds.ru`

## Важные правила

1. Для каждого `dev-*` profile используется отдельное Bitrix app.
2. `staging` profile этой инструкцией не трогаем.
3. Для Telegram и MAX нужны разные Open Lines.
4. `LINE_ID` хранится в маршруте конкретного канала в админке, а не в
   bootstrap-команде профиля.
5. Для одного и того же `profile_key` tunnel URL можно менять без пересоздания
   профиля.
6. Если tunnel URL сменился, нужно снова прогнать bootstrap для того же
   `profile_key`.
7. После смены tunnel URL, `LINE_ID`, `connector_code` или callback owner нужно
   заново опубликовать OpenLines registry и проверить Doctor до теста
   операторского ответа из Bitrix24.
8. `OpenLines route registry secret` хранится в Bitrix24 profile в админке.
   Bootstrap-проверка не заменяет сохранение этого секрета.
9. Если Doctor возвращает `route_registry_secret_missing`, Bitrix local setup
   не готов. Нельзя переходить к операторскому smoke через Bitrix24, пока
   секрет не сохранён и Doctor не показывает `synced` без diff-ов.

## Шаг 1. Выберите имя профиля

Используйте канонический формат:

- `dev-ivan-main`
- `dev-ivan-ui`
- `dev-german-test`

Пример ниже использует:

- `profile_key`: `dev-german-main`

## Шаг 2. Получите tunnel URL

Пример:

- `https://german-main.trycloudflare.com`

Этот URL и будет вашим текущим `callback_base_url`.

## Шаг 3. Создайте отдельное Bitrix app

В Bitrix24 staging создайте отдельное приложение только для этого профиля и
сохраните:

1. `client_id`
2. `client_secret`
3. `application_code`

Не переиспользуйте приложение от `staging` или другого разработчика.

## Шаг 4. Первый bootstrap-запуск

Запустите команду без `LINE_ID`, чтобы создать или обновить профиль и получить
точные значения для Bitrix:

```bash
php artisan bitrix24:dev-profile-bootstrap dev-german-main https://german-main.trycloudflare.com \
  --client-id=ВАШ_CLIENT_ID \
  --application-code=ВАШ_APPLICATION_CODE
```

В локальном `.env` для OAuth также должен быть задан:

```env
BITRIX24_CLIENT_SECRET=ВАШ_CLIENT_SECRET
BITRIX24_AUTH_SERVER_URL=https://oauth.bitrix.info
```

Команда напечатает:

1. данные профиля;
2. callback URL;
3. expected `SOURCE_ID` и `connector_code`;
4. список проверок;
5. блок `Что сделать в Bitrix`.

Если команда отсутствует в локальном runtime, сначала выполните:

```bash
composer dump-autoload
```

## Шаг 5. Пропишите callback URL в Bitrix app

Используйте callback URL из вывода команды.

Для примера выше это будут:

1. `https://german-main.trycloudflare.com/callbacks/bitrix24/install`
2. `https://german-main.trycloudflare.com/callbacks/bitrix24/events`
3. `https://german-main.trycloudflare.com/callbacks/bitrix24/openlines`

## Шаг 6. Создайте Open Lines

Создайте две отдельные Open Lines:

1. одну для Telegram
2. одну для MAX

Рекомендуемые имена:

1. `ABC Telegram dev-german-main`
2. `ABC MAX dev-german-main`

После создания сохраните:

1. `Telegram LINE_ID`
2. `MAX LINE_ID`

## Шаг 7. Повторите bootstrap без LINE_ID

Повторите bootstrap с тем же `profile_key` и текущим tunnel URL. Флаги
`--telegram-line-id` и `--max-line-id` существуют только для legacy-сценариев и
не являются основным способом настройки маршрутов.

```bash
php artisan bitrix24:dev-profile-bootstrap dev-german-main https://german-main.trycloudflare.com \
  --client-id=ВАШ_CLIENT_ID \
  --application-code=ВАШ_APPLICATION_CODE
```

Ожидаемые routing values для этого профиля:

1. Telegram `SOURCE_ID`: `ABC_TELEGRAM_DEV_GERMAN_MAIN`
2. MAX `SOURCE_ID`: `ABC_MAX_DEV_GERMAN_MAIN`
3. Telegram `connector_code`: `abc_telegram_dev_german_main`
4. MAX `connector_code`: `abc_max_dev_german_main`

## Шаг 8. Привяжите LINE_ID к маршрутам каналов в админке

После создания Open Lines сохраните `LINE_ID` в маршрутах конкретных локальных
каналов:

1. откройте Laravel admin;
2. откройте настройки Bitrix24 profile;
3. в блоке маршрутов Open Lines выберите нужный канал;
4. укажите `connector_code` и соответствующий `LINE_ID`;
5. сохраните маршрут.

Telegram и MAX должны использовать разные `connector_code` и разные Open Lines.
Один и тот же `LINE_ID` нельзя назначать двум active/legacy routes.

## Шаг 9. Настройте OpenLines route registry secret

В настройках Bitrix24 profile в Laravel admin сохраните secret для OpenLines
route registry. Это тот же секрет, который ожидает managed-code endpoint
Bitrix24 для route registry.

После сохранения секрета можно выполнить предварительную проверку:

1. опубликуйте OpenLines registry;
2. запустите Doctor;
3. убедитесь, что ошибка `route_registry_secret_missing` отсутствует.

Эта проверка не является финальной зелёной проверкой, пока Bitrix не отправил
install callback на текущий ingress. Без сохранённого секрета локальный
Bitrix-контур считается неполным, даже если каналы созданы и `LINE_ID`
заполнены.

## Шаг 10. Дошлите install callback на текущий tunnel URL

После настройки приложения и линий нужно, чтобы Bitrix действительно отправил
install callback на ваш текущий ingress.

Для этого:

1. пересохраните настройки приложения в Bitrix;
2. если нужно, переустановите приложение;
3. убедитесь, что callback идёт именно на текущий tunnel URL.

Это важно, потому что bootstrap не считает профиль готовым, пока не увидит
валидный install callback на текущем `callback_base_url`.

## Шаг 11. Повторно запустите bootstrap-проверку

Запустите ту же команду ещё раз с теми же параметрами, без LINE_ID-флагов.

Готовое состояние:

```text
Dev-profile готов к full_live handoff и verify-контуру.
```

Если команда пишет, что setup ещё не готов, смотрите таблицу `Status / Notes` в
её выводе. Это и есть точный список blocking items.

После зелёного bootstrap:

1. заново опубликуйте OpenLines registry;
2. запустите Doctor;
3. убедитесь, что результат `synced`, `Diffs 0` и нет
   `route_registry_secret_missing`.

Только после этого отправляйте новое операторское smoke-сообщение из Bitrix24.

## Что делать при смене tunnel URL

Если tunnel URL поменялся:

1. оставьте тот же `profile_key`;
2. снова запустите bootstrap с новым URL;
3. обновите callback URL в Bitrix app;
4. снова добейтесь нового install callback на новый ingress;
5. опубликуйте OpenLines registry;
6. запустите Doctor и получите `synced` без diff-ов;
7. повторно прогоните команду до зелёного результата;
8. отправьте новое операторское smoke-сообщение из Bitrix24 и проверьте, что оно
   дошло клиенту.

Пример:

```bash
php artisan bitrix24:dev-profile-bootstrap dev-german-main https://new-german-main.trycloudflare.com \
  --client-id=ВАШ_CLIENT_ID \
  --application-code=ВАШ_APPLICATION_CODE
```

## Частые ошибки

1. Один и тот же `LINE_ID` указан и для Telegram, и для MAX.
2. Используется Bitrix app от другого профиля.
3. В Bitrix остались старые callback URL после смены tunnel URL.
4. Есть install callback, но он пришёл на старый ingress.
5. На текущий ingress пришёл только `failed` install callback, а не валидный.
6. `LINE_ID` пытаются настроить через bootstrap вместо маршрута канала в админке.
7. Не сохранён OpenLines route registry secret, поэтому Doctor падает с
   `route_registry_secret_missing`.

## Что проверять, если не взлетело

1. Команда существует:
   - `php artisan bitrix24:dev-profile-bootstrap --help`
2. Tunnel URL живой и доступен извне.
3. В Bitrix app стоят именно текущие callback URL.
4. `client_id` и `application_code` записаны от правильного Bitrix app.
5. Telegram и MAX используют разные Open Lines.
6. После последних изменений в Bitrix был заново отправлен install callback.
7. OpenLines registry заново опубликован после последней смены tunnel URL,
   `LINE_ID`, `connector_code` или callback owner.
8. В Bitrix24 profile сохранён OpenLines route registry secret.
9. Doctor показывает `synced` и `Diffs 0`.

## Связанные документы

1. [README.md](/Users/abrikosov/Documents/Проект-1/README.md)
2. [setup-sheet.md](/Users/abrikosov/Documents/Проект-1/docs/bitrix24/setup-sheet.md)
3. [staging-laravel-cloud.md](/Users/abrikosov/Documents/Проект-1/docs/staging-laravel-cloud.md)
4. [openlines-channel-runbook.md](/Users/abrikosov/Documents/Проект-1/docs/bitrix24/openlines-channel-runbook.md)
