# ТЗ: Telegram unsubscribe system events -> Bitrix24 Open Lines

## Цель

Показывать в `Bitrix24 Open Lines` системные события Telegram:

- `Клиент заблокировал бота`
- `Клиент разблокировал бота`

чтобы оператор видел изменение доступности канала не только в Abrikosoff,
но и в Bitrix24 live chat.

## Проблема

Сейчас `unsubscribe` сохраняется у нас как `Message::KIND_INBOUND_SYSTEM_EVENT`,
но не попадает:

- ни в live export `Bitrix24 Open Lines`;
- ни в history export `Bitrix24`.

Из-за этого оператор в Bitrix не видит, почему канал перестал быть sendable
или снова стал доступен.

## Текущий runtime и место изменения

Точка изменения находится в live export `messenger -> Bitrix24 Open Lines`:

- `app/Services/Bitrix24/IsMessageReadyForBitrix24LiveExportAction.php`
- `app/Services/Bitrix24/BuildBitrix24OpenLinesMessagePayloadAction.php`
- `app/Services/Bitrix24/ExportMessageToBitrix24OpenLinesAction.php`

Источник событий и их канонические коды:

- `app/Models/Message.php`
- `app/Services/Bots/StoreInboundMessageAction.php`

Тестовые точки входа:

- `tests/Feature/Bitrix24OpenLinesLiveExportTest.php`
- при необходимости `tests/Feature/BotWebhookAutoReplyTest.php`

## Границы

### Что меняется

- live export в `Bitrix24 Open Lines` для узкого подмножества
  `inbound_system_event`;
- formatter payload для этих системных событий;
- feature tests для live export blocked/unblocked system events.

### Что остаётся как есть

- текущий live export happy-path для обычных inbound/outbound сообщений;
- текущий UI Abrikosoff для system events;
- `Telegram unsubscribe` хранение и route-status contract;
- history export в CRM timeline и deal timeline;
- `Bitrix24 Open Lines blocked dialog` follow-up;
- любые replay/backfill для уже сохранённых system events.

### Вне scope

- добавление `unsubscribe` system events в history export;
- новый Bitrix24 transport path;
- redesign formatter-а для всех системных событий;
- docs/workflow/process fixes;
- `merge`, `rebase`, `deploy`, публикация изменений.

## Архитектурные решения

- `inbound_system_event` становится exportable только для узкого allowlist:
  - `Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER`
  - `Message::SYSTEM_EVENT_CODE_BOT_UNBLOCKED_BY_USER`
- остальные system events по умолчанию не экспортируются.
- В Bitrix событие должно выглядеть как системное сообщение,
  а не как обычная реплика клиента.
- Formatter payload не должен брать raw `message->text` как пользовательский inbound
  без явной маркировки системной природы события.

## Требуемое поведение

### Если у диалога активен Open Lines live bridge

- `bot_blocked_by_user` отправляется в Bitrix как системное сообщение:
  - `Система: Клиент заблокировал бота`
- `bot_unblocked_by_user` отправляется в Bitrix как системное сообщение:
  - `Система: Клиент разблокировал бота`

### Если live bridge не активен

- событие остаётся только в истории Abrikosoff;
- новых side effects в Bitrix не возникает.

### Для остальных system events

- они не начинают экспортироваться случайно;
- поведение остаётся закрытым allowlist-ом.

## Формат сообщения

Рекомендованный текст первой версии:

- `Система: Клиент заблокировал бота`
- `Система: Клиент разблокировал бота`

Нежелательное поведение:

- отправлять просто `Клиент заблокировал бота` как inbound-реплику клиента;
- терять системную природу события при построении payload.

## Тестовая стратегия

Нужны таргетные feature-тесты:

- blocked system event при active live bridge:
  - ставится в live export;
  - HTTP уходит в Bitrix;
  - payload содержит системный текст;
- unblocked system event:
  - тот же контракт;
- `inbound_system_event` с неизвестным `system_event_code`:
  - не экспортируется;
- regression:
  - обычные exportable messages продолжают экспортироваться как раньше.

## Критерии приёмки

- blocked/unblocked Telegram system events уходят в `Bitrix Open Lines`;
- в Bitrix они выглядят как системные, а не как реплики клиента;
- остальные system events не начинают экспортироваться случайно;
- текущий live export happy-path не деградирует.

## Известные компромиссы

- первая версия не добавляет эти события в history export;
- старые уже сохранённые system events не replay-ятся автоматически;
- шаг не решает follow-up про `Bitrix24 Open Lines blocked dialog`;
- шаг не расширяет scope `Telegram unsubscribe` beyond live visibility в Bitrix.

## Рекомендуемое разбиение на slices

### Slice 1

- разрешить export только для allowlist `unsubscribe` system events.

### Slice 2

- сформировать системный текст payload для `Bitrix24 Open Lines`.

### Slice 3

- добавить feature tests для blocked/unblocked export и guard на unknown system event.
