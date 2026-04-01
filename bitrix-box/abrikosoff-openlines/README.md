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
- содержит экспериментальный `crm_rebinding` hook для box-side inspection
  existing contact по телефону на lifecycle `imopenlines`
- делает безопасный phone-based lookup с вариантами `+7` / `8`
  и не выполняет unsafe auto-attach при ambiguous match

## Что этот пакет не делает

- не меняет Laravel runtime
- не создаёт линии автоматически
- не включает `BITRIX24_OPENLINES_ENABLED` в Laravel Cloud
- не заменяет install callback local app
- не содержит documented Bitrix API для прямого attach current incoming chat
  к existing CRM contact; текущий `crm_rebinding` слой даёт inspection,
  exact phone lookup, tracker preview и structured diagnostics
  для box-side prototype

## Ожидаемый результат

После деплоя пакета в коробку:

- Bitrix перестаёт считать `abrikosoff_telegram` и `abrikosoff_max` неизвестными provider-ами
- коннекторы становятся доступными для привязки к Открытым линиям
- операторские message events можно прокидывать в Laravel callback path

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

И зафиксировать line metadata:

- `line_id`
- line name `ABR Телеграм бот {имя бота}`
- line name `ABR MAX бот {имя бота}`

Опционально для box-side prototype:

- `crm_rebinding.enabled = true`
- `crm_rebinding.log_payload = false`

Это включает `imopenlines` inspection hook и structured logs:

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

## Минимальная ручная проверка

1. Коннекторы появляются в Bitrix как доступные каналы.
2. Линии можно привязать к:
   - `abrikosoff_telegram`
   - `abrikosoff_max`
3. Laravel live export больше не падает на provider lookup error.
4. В логах `AbrikosoffOpenLines` появляются `crm_rebind_*` события.

## Runbook

Короткий operational runbook лежит в:

- `/Users/abrikosov/Documents/Проект-1/docs/bitrix24/openlines-box-crm-rebinding-runbook.md`
