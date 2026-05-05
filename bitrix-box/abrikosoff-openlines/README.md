# Abrikosoff Open Lines Box Package

Минимальный box-side пакет для коробочного Bitrix24, который:

- регистрирует custom connector-ы `abrikosoff_telegram` и `abrikosoff_max`
- добавляет обязательный `OnImConnectorBuildList`
- добавляет минимальные `imconnector` handlers:
  - `OnInfoLine`
  - `OnDeleteLine`
  - `OnSendMessageCustom`
  - `OnUpdateMessageCustom`
  - `OnDeleteMessageCustom`
- проксирует message events в уже существующий Laravel callback path
- содержит box-side `crm_rebinding` слой для existing-contact Open Lines happy-path
- делает phone-based lookup existing CRM contact на lifecycle `imopenlines`
- привязывает новую Open Lines сессию к уже существующему CRM contact
  в подтверждённом happy-path для Telegram и MAX
- делает безопасный phone-based lookup с вариантами `+7` / `8`
  и не выполняет unsafe auto-attach при ambiguous match

## Что этот пакет не делает

- не меняет Laravel runtime
- не создаёт линии автоматически
- не включает `BITRIX24_OPENLINES_ENABLED` в Laravel Cloud
- не заменяет install callback local app
- не покрывает новые Bitrix24 сценарии вне подтверждённого Open Lines happy-path
- не закрывает все safety-cases автоматически:
  - `unknown contact`
  - `ambiguous match`
- не заменяет отдельный hardening-step, если нужен расширенный fallback

## Ожидаемый результат

После деплоя пакета в коробку:

- Bitrix перестаёт считать `abrikosoff_telegram` и `abrikosoff_max` неизвестными provider-ами
- коннекторы становятся доступными для привязки к Открытым линиям
- операторские message events можно прокидывать в Laravel callback path
- новая Open Lines сессия в happy-path появляется у existing CRM contact
- операторский ответ из Bitrix уходит обратно в Telegram / MAX
- лишний лид в happy-path не создаётся

## Naming convention

Для линий использовать:

- `ABR Телеграм бот {имя бота}`
- `ABR MAX бот {имя бота}`

## Структура пакета

Пакет повторяет структуру коробки:

- `local/php_interface/include/abrikosoff_openlines/bootstrap.php`
- `local/php_interface/include/abrikosoff_openlines/src/Runtime.php`
- `local/php_interface/include/abrikosoff_openlines/config.example.php`
- `local/components/abrikosoff/imconnector.telegram/*`
- `local/components/abrikosoff/imconnector.max/*`

## Деплой в коробку

1. Скопировать содержимое каталога `local/` в корень коробочного Bitrix.
2. Скопировать:

   - `local/php_interface/include/abrikosoff_openlines/config.example.php`

   в:

   - `local/php_interface/include/abrikosoff_openlines/config.php`

3. Заполнить `config.php`.
4. Добавить в существующий `local/php_interface/init.php` строку:

```php
require_once $_SERVER['DOCUMENT_ROOT'].'/local/php_interface/include/abrikosoff_openlines/bootstrap.php';
```

5. Очистить кеш Bitrix.
6. Открыть Контакт-центр / Открытые линии и подключить каналы:

   - `abrikosoff_telegram`
   - `abrikosoff_max`

## Что заполнить в config.php

Нужно перенести значения из production Laravel runtime:

- `member_id`
- `application_token`
- `portal_domain`
- `openlines_callback_url`

`openlines_callback_url` остаётся глобальным fallback URL. Если одна коробка
Bitrix обслуживает несколько Laravel-контуров, для каждой линии нужно добавить
entry в `connectors.*.lines` и заполнить `owner_callback_base_url`. Для такой
линии `OnInfoLine` и operator message callbacks будут отправляться на:

- `{owner_callback_base_url}/callbacks/bitrix24/openlines`

Текущий production handoff:

- `portal_domain = crm.alexlesley.biz`
- `openlines_callback_url = https://project2.abrikosoff.ru/callbacks/bitrix24/openlines`
- `owner_profile_key = staging`
- `owner_callback_base_url = https://project2.abrikosoff.ru`

`owner_profile_key = staging` — это существующий Laravel `Bitrix24Profile.profile_key`,
а не признак staging-окружения. Production-контур отделяется через
`portal_domain = crm.alexlesley.biz` и production callback base URL.

Перед production deploy Laravel runtime должен иметь hash этого
`application_token` в одном из env:

- `BITRIX24_OPENLINES_RUNTIME_APPLICATION_TOKEN_HASH`
- `BITRIX24_OPENLINES_RUNTIME_APPLICATION_TOKEN_HASHES`

И зафиксировать line metadata:

- `abrikosoff_telegram.line_id = 32`
- `abrikosoff_max.line_id = 31`
- line name `ABR Телеграм бот {имя бота}`
- line name `ABR MAX бот {имя бота}`

Для рабочей конфигурации коробки:

- `crm_rebinding.enabled = true`
- `crm_rebinding.log_payload = false`
- `crm_rebinding.log_file = ''`

Это включает `imopenlines` rebinding path и structured logs:

- `crm_rebind_attempted`
- `crm_rebind_skipped`
- `crm_rebind_contact_not_found`
- `crm_rebind_ambiguous_match`
- `crm_rebind_contact_found`
- `crm_rebind_tracker_preview_failed`
- `crm_rebind_transfer_error`

`crm_rebinding.log_payload` по умолчанию лучше держать `false`.
В этом режиме package:

- маскирует телефонные значения в логах
- не пишет `tracker_preview` и raw event payload
- не раскрывает `member_id` / `application_token`

Включать `crm_rebinding.log_payload = true` стоит только
на короткий controlled debug-сеанс.

Если глобальный `LOG_FILENAME` в коробке не определён на обычных веб-запросах,
package пишет fallback-лог в:

- `local/php_interface/include/abrikosoff_openlines/runtime.log`

Либо в путь из `crm_rebinding.log_file`, если он задан явно.

## Минимальная ручная проверка

1. Коннекторы появляются в Bitrix как доступные каналы.
2. Линии можно привязать к:
   - `abrikosoff_telegram`
   - `abrikosoff_max`
3. `abrikosoff_telegram` привязан к линии `32`.
4. `abrikosoff_max` привязан к линии `31`.
5. Laravel live export больше не падает на provider lookup error.
6. Новая Open Lines сессия появляется у existing CRM contact.
7. Ответ оператора из Bitrix уходит обратно в Telegram / MAX.
8. В логах `AbrikosoffOpenLines` появляются `crm_rebind_*` события.
   Если `LOG_FILENAME` не используется для web-runtime, смотреть:
   - `local/php_interface/include/abrikosoff_openlines/runtime.log`

## Runbook

Короткий operational runbook лежит во внешнем specs-репозитории:

- `/Users/abrikosov/Documents/Project-1-specs/reference/bitrix24/openlines-box-crm-rebinding-runbook.md`
