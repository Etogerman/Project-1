# Bitrix24 Box Open Lines Registration Spike

## Статус

Discovery завершён на уровне достаточном для следующего implementation step.

Текущее состояние по production smoke-check:

- `Contact Sync` работает
- `Deal Sync` работает
- `History Export` работает
- `Open Lines live export` доходит до `imconnector.send.messages`
- Bitrix24 box отвечает ошибкой `Не удалось найти подходящий провайдер для коннектора`

## Подтверждённые факты

### 1. Laravel-side live export реально стартует

Production symptom подтверждён на локальных моделях и логах:

- `bitrix24_message_exports.export_mode = live`
- `bitrix24_message_exports.export_status = failed`
- `failure_reason = "Не удалось найти подходящий провайдер для коннектора"`
- `dialogs.bitrix24_live_status = failed`
- `bitrix24_sync_logs.operation = openlines_live_export_failed`

Это исключает проблемы вида:

- feature flag не включён
- очередь не поставилась
- сообщение не попало в export path

### 2. Текущий install path не регистрирует custom connector

Текущий callback/install контур:

- принимает install callback
- сохраняет OAuth connection
- обновляет `Bitrix24Connection`

Он не делает ничего, что выглядело бы как box-side registration `imconnector` provider-а.

Опорные файлы:

- `/Users/abrikosov/Documents/Проект-1/app/Http/Controllers/Bitrix24CallbackController.php`
- `/Users/abrikosov/Documents/Проект-1/app/Services/Bitrix24/UpsertBitrix24ConnectionFromInstallAction.php`

### 3. Текущий live payload использует connector codes как уже существующие

Laravel runtime отправляет:

- `CONNECTOR = abrikosoff_telegram | abrikosoff_max`
- `LINE = 30 | 31`
- `imconnector.send.messages`

То есть код приложения исходит из предположения, что connector с таким ID уже зарегистрирован в Bitrix.

Опорные файлы:

- `/Users/abrikosov/Documents/Проект-1/config/bitrix24.php`
- `/Users/abrikosov/Documents/Проект-1/app/Services/Bitrix24/BuildBitrix24OpenLinesMessagePayloadAction.php`
- `/Users/abrikosov/Documents/Проект-1/app/Services/Bitrix24/ExportMessageToBitrix24OpenLinesAction.php`

## Что подтверждает официальная документация Bitrix

### 1. `sendMessages` требует connector ID, который уже зарегистрирован

В официальной docs для `CustomConnectors::sendMessages` параметр `connector` описан как ID коннектора, указанный при регистрации обработчика.

Источник:

- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/customconnectors/sendmessages.php

Следствие:

- если `abrikosoff_max` или `abrikosoff_telegram` нигде не зарегистрированы в box-side `imconnector`, Bitrix не сможет найти provider
- именно это и согласуется с production error

### 2. `imconnector` обработчики регистрируются через box-side `EventManager`

Официальные страницы `OnInfoLine`, `OnSendMessageCustom`, `OnUpdateMessageCustom` показывают регистрацию вида:

- `EventManager::getInstance()->addEventHandler("imconnector", ...)`

Источники:

- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/events/additional_events.php
- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/events/onsendmessagecustom.php
- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/events/onupdatemessagecustom.php
- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/events/ondeletemessagecustom.php

Следствие:

- обработка `imconnector` событий по docs предполагает наличие PHP layer внутри самой коробки
- один только внешний Laravel callback URL этого не заменяет

### 3. Box-specific подключение собственного коннектора вынесено в отдельный коробочный материал

Index-страница `imconnector` прямо отсылает к отдельному материалу по подключению собственного типа коннекторов для коробочной версии.

Источник:

- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/index.php

Следствие:

- Bitrix сам разделяет generic API docs и отдельный box-specific registration path
- это ещё одно подтверждение, что local app alone не закрывает registration

## Ответы на вопросы ТЗ-6.4.1

### Почему сейчас не находится provider

Потому что Laravel отправляет `imconnector.send.messages` с `connector = abrikosoff_max | abrikosoff_telegram`, а в коробочном Bitrix нет подтверждённого registered provider-а с таким ID.

### Где должен регистрироваться connector

По собранным фактам registration path должен существовать на стороне коробки Bitrix, а не только во внешнем Laravel app.

Точный механизм реализации ещё не внедрён, но docs уже достаточно однозначно указывают на box-side `imconnector` event handlers.

### Нужен ли box-side код

Текущая рабочая гипотеза после discovery: **да, нужен**.

Минимум, который выглядит обязательным:

- box-side registration custom connector ID
- box-side handler для `OnInfoLine`
- box-side handler для `OnSendMessageCustom`
- box-side handler для `OnUpdateMessageCustom`
- box-side handler для `OnDeleteMessageCustom`
- box-side handler для `OnDeleteLine`

### Должен ли коннектор появляться в Контакт-центре

По production inspection отсутствие плитки `Abrikosoff` согласуется с отсутствием registration.

Discovery не доказывает, что плитка в Контакт-центре является единственным источником истины, но в текущем кейсе отсутствие provider и отсутствие видимого канала указывают в одну сторону.

## Naming convention

Для линий в коробочном Bitrix24 зафиксирован стандарт:

- `ABR Телеграм бот {имя бота}`
- `ABR MAX бот {имя бота}`

Это naming rule использовать:

- в box-side registration doc
- в line mapping checklist
- в future implementation ТЗ

## Итог discovery

Текущий `Open Lines` gap не находится в:

- Laravel Cloud env
- OAuth install callback
- queue wiring
- `send.messages` runtime path

Текущий gap находится в отсутствующем **box-side registration layer для custom connector provider**.

## Рекомендованный следующий шаг

Следующий implementation step: `ТЗ-6.4.2 / Box-Side Connector Registration`

Ожидаемый scope:

- определить конкретный box-side artifact:
  - module
  - handler bootstrap
  - registration script
- зарегистрировать `abrikosoff_telegram`
- зарегистрировать `abrikosoff_max`
- связать их с линиями:
  - `ABR Телеграм бот {имя бота}`
  - `ABR MAX бот {имя бота}`
- после этого повторить Laravel smoke-check без изменений runtime payload

## Rollout recommendation

До завершения `ТЗ-6.4.2`:

- `BITRIX24_CONTACTS_SYNC_ENABLED=true`
- `BITRIX24_DEALS_SYNC_ENABLED=true`
- `BITRIX24_TIMELINE_HISTORY_IMPORT_ENABLED=true`
- `BITRIX24_OPENLINES_ENABLED=false`

`Open Lines` не стоит держать включённым, пока box-side registration отсутствует: новые сообщения будут стабильно уходить в `live failed`.
