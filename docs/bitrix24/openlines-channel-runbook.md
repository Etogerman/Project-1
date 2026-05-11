# Bitrix24 Open Lines channel runbook

## Назначение

Документ описывает безопасный порядок подключения локального канала Abrikosoff
Connector к существующей открытой линии Bitrix24.

Маршрут Open Lines всегда задаётся на уровне конкретного локального канала:

```text
Channel -> connector_code -> LINE_ID -> callback owner -> registry publish
```

## Текущий production baseline

Production Bitrix24:

```text
portal_domain = crm.alexlesley.biz
owner_profile_key = production
owner_callback_base_url = https://project2.abrikosoff.ru
```

Рабочие production routes:

```text
MAX #2                    -> abrikosoff_max:31
Telegram #1 Продакшен     -> abrikosoff_telegram:13
Telegram #3 Alex_Lesley_bot -> abrikosoff_telegram:32
```

`LINE_ID=13` раньше был старой/native `telegrambot` линией с ошибкой connector
status. 2026-05-11 он подключён к `abrikosoff_telegram` через registry route
`production:abrikosoff_telegram:13`; полный smoke входящего сообщения и ответа
оператора из Bitrix24 прошёл.

На production Bitrix24 был найден deployment drift: файл
`/home/bitrix/www/local/php_interface/include/abrikosoff_openlines/bootstrap.php`
не загружал `src/RouteRegistry.php`, поэтому web-компонент коннектора показывал
пустой Laravel callback URL при корректном registry. Файл обновлён с backup:

```text
/home/bitrix/.abrikosoff_openlines/backups/bootstrap-route-registry-fix-20260511-153040/bootstrap.php
```

При следующем deploy managed-code нужно убедиться, что production `bootstrap.php`
совпадает с версией из `bitrix24-managed-custom-code`.

## Что можно и нельзя делать

Можно:

1. Привязать Telegram bot channel к открытой линии, подключённой к
   `abrikosoff_telegram`.
2. Привязать MAX bot channel к открытой линии, подключённой к `abrikosoff_max`.
3. Хранить нерабочий черновик route со статусом `inactive` или `misconfigured`.
4. Публиковать routes в Bitrix registry только после проверки конфликтов.

Нельзя:

1. Использовать один и тот же `LINE_ID` для двух рабочих routes в одном
   Bitrix24 portal.
2. Считать любой существующий `LINE_ID` подходящим. Важно совпадение
   `connector_code + LINE_ID`.
3. Включать Telegram account route в Open Lines как Telegram bot route.
4. Оставлять production smoke только на Doctor. Нужен реальный входящий тест
   из Bitrix24 по каждой рабочей линии.

## Статусы route

Рабочие статусы:

```text
active
legacy
```

Нерабочие статусы:

```text
inactive
misconfigured
unsupported
```

Только `active` и `legacy` попадают в рабочий путь и в registry snapshot.

## Как подключить канал к Open Line

1. Убедиться, что канал в Laravel активен и имеет корректный тип подключения.
2. В Bitrix24 проверить, что нужная открытая линия существует.
3. Проверить, что линия подключена к правильному connector code:

   ```text
   Telegram bot -> abrikosoff_telegram
   MAX bot      -> abrikosoff_max
   ```

4. В Laravel admin открыть настройки Bitrix24 profile.
5. В route канала указать:

   ```text
   status = active или legacy
   connector_code = нужный Abrikosoff connector
   LINE_ID = ID подходящей открытой линии
   callback owner = production / staging / нужный owner
   ```

6. Сохранить route.
7. Нажать `Опубликовать` в блоке OpenLines registry.
8. Нажать `Проверить` и получить synced state.
9. Отправить реальное входящее сообщение через соответствующий внешний канал.
10. Проверить, что сообщение пришло в Laravel и операторский ответ из Bitrix24
    уходит обратно клиенту.

## Как понять, что линия не подходит

Линия не подходит для route, если выполняется любое условие:

1. `LINE_ID` уже занят другим route со статусом `active` или `legacy`.
2. В Bitrix24 линия подключена к другому connector code.
3. В Laravel route имеет статус `inactive`, `misconfigured` или `unsupported`.
4. Doctor показывает diff или failed.
5. Runtime registry содержит другой owner или другой callback base URL.
6. Smoke по реальному сообщению не проходит.

## Что делать с дублями каналов

Если есть два Telegram bot channel-а, но рабочая Open Line одна:

1. Не назначать обоим один `LINE_ID`.
2. Выбрать канонический канал, который будет рабочим.
3. Второй канал оставить `inactive` в Open Lines route или пометить как архивный
   на уровне названия/описания.
4. Если нужно перенести route на другой channel, делать это отдельной миграцией:
   сначала отключить старый route, затем включить новый, опубликовать registry и
   пройти smoke.

## Production smoke checklist

После изменения production route:

1. `Опубликовать` registry.
2. `Проверить` registry, ожидаемый результат: synced.
3. Отправить входящее сообщение в каждый изменённый channel.
4. Проверить, что callback пришёл в Laravel.
5. Ответить операторским сообщением из Bitrix24.
6. Проверить, что ответ дошёл клиенту.
7. Проверить, что в Bitrix runtime diagnostics нет:

   ```text
   route_registry_invalid
   route_registry_miss
   duplicate_line_id_ignored
   ```

## Связанные документы

- `Project-1-specs/streams/tz-bitrix24-openlines-channel-routing-v1.md`
- `Project-1-specs/streams/tz-bitrix24-openlines-dynamic-route-registry-v1.md`
- `Project-1/docs/bitrix24/setup-sheet.md`
- `Project-1/bitrix-box/abrikosoff-openlines/README.md`
