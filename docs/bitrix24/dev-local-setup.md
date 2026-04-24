# Bitrix24 Developer Local Setup

Этот документ описывает developer-путь для подключения своей локалки к общему
Bitrix24 staging-порталу через отдельный `dev-*` profile.

Он:

1. не заменяет [setup-sheet.md](/Users/abrikosov/Documents/Проект-1/docs/bitrix24/setup-sheet.md) для
   стабильного `staging`-профиля;
2. не описывает production setup;
3. фиксирует практический bootstrap-путь для разработчика.

## Что нужно заранее

Перед началом убедитесь, что у вас есть:

1. локально поднятый проект;
2. tunnel URL для вашей локалки;
3. доступ администратора к Bitrix24 staging-порталу;
4. отдельное Bitrix app именно под ваш `dev-*` profile.

По умолчанию staging-портал проекта:

- `portal_domain`: `crm.alexlesley.biz`

## Важные правила

1. Для каждого `dev-*` profile используется отдельное Bitrix app.
2. `staging` profile этой инструкцией не трогаем.
3. Для Telegram и MAX нужны разные Open Lines.
4. Для одного и того же `profile_key` tunnel URL можно менять без пересоздания
   профиля.
5. Если tunnel URL сменился, нужно снова прогнать bootstrap для того же
   `profile_key`.

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
2. `application_code`

Не переиспользуйте приложение от `staging` или другого разработчика.

## Шаг 4. Первый bootstrap-запуск

Запустите команду без `LINE_ID`, чтобы создать или обновить профиль и получить
точные значения для Bitrix:

```bash
php artisan bitrix24:dev-profile-bootstrap dev-german-main https://german-main.trycloudflare.com \
  --client-id=ВАШ_CLIENT_ID \
  --application-code=ВАШ_APPLICATION_CODE
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

## Шаг 7. Проведите второй bootstrap-запуск

Теперь передайте оба `LINE_ID`:

```bash
php artisan bitrix24:dev-profile-bootstrap dev-german-main https://german-main.trycloudflare.com \
  --client-id=ВАШ_CLIENT_ID \
  --application-code=ВАШ_APPLICATION_CODE \
  --telegram-line-id=TELEGRAM_LINE_ID \
  --max-line-id=MAX_LINE_ID
```

Ожидаемые routing values для этого профиля:

1. Telegram `SOURCE_ID`: `ABC_TELEGRAM_DEV_GERMAN_MAIN`
2. MAX `SOURCE_ID`: `ABC_MAX_DEV_GERMAN_MAIN`
3. Telegram `connector_code`: `abc_telegram_dev_german_main`
4. MAX `connector_code`: `abc_max_dev_german_main`

## Шаг 8. Дошлите install callback на текущий tunnel URL

После настройки приложения и линий нужно, чтобы Bitrix действительно отправил
install callback на ваш текущий ingress.

Для этого:

1. пересохраните настройки приложения в Bitrix;
2. если нужно, переустановите приложение;
3. убедитесь, что callback идёт именно на текущий tunnel URL.

Это важно, потому что bootstrap не считает профиль готовым, пока не увидит
валидный install callback на текущем `callback_base_url`.

## Шаг 9. Повторно запустите bootstrap-проверку

Запустите ту же команду ещё раз с теми же параметрами.

Готовое состояние:

```text
Dev-profile готов к full_live handoff и verify-контуру.
```

Если команда пишет, что setup ещё не готов, смотрите таблицу `Status / Notes` в
её выводе. Это и есть точный список blocking items.

## Что делать при смене tunnel URL

Если tunnel URL поменялся:

1. оставьте тот же `profile_key`;
2. снова запустите bootstrap с новым URL;
3. обновите callback URL в Bitrix app;
4. снова добейтесь нового install callback на новый ingress;
5. повторно прогоните команду до зелёного результата.

Пример:

```bash
php artisan bitrix24:dev-profile-bootstrap dev-german-main https://new-german-main.trycloudflare.com \
  --client-id=ВАШ_CLIENT_ID \
  --application-code=ВАШ_APPLICATION_CODE \
  --telegram-line-id=TELEGRAM_LINE_ID \
  --max-line-id=MAX_LINE_ID
```

## Частые ошибки

1. Один и тот же `LINE_ID` указан и для Telegram, и для MAX.
2. Используется Bitrix app от другого профиля.
3. В Bitrix остались старые callback URL после смены tunnel URL.
4. Есть install callback, но он пришёл на старый ingress.
5. На текущий ingress пришёл только `failed` install callback, а не валидный.

## Что проверять, если не взлетело

1. Команда существует:
   - `php artisan bitrix24:dev-profile-bootstrap --help`
2. Tunnel URL живой и доступен извне.
3. В Bitrix app стоят именно текущие callback URL.
4. `client_id` и `application_code` записаны от правильного Bitrix app.
5. Telegram и MAX используют разные Open Lines.
6. После последних изменений в Bitrix был заново отправлен install callback.

## Связанные документы

1. [README.md](/Users/abrikosov/Documents/Проект-1/README.md)
2. [setup-sheet.md](/Users/abrikosov/Documents/Проект-1/docs/bitrix24/setup-sheet.md)
3. [staging-laravel-cloud.md](/Users/abrikosov/Documents/Проект-1/docs/staging-laravel-cloud.md)
