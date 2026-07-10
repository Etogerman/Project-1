# Безопасный запуск KPI SQL в production

## Назначение

Безопасно получить агрегированные KPI по production-данным AB Connector без
выгрузки персональных данных, текстов сообщений, телефонов, raw payload,
error_message и per-record строк.

Основной SQL-файл:

- `docs/reference/analytics-kpi-production-safe.sql`

## Перед запуском

1. Использовать только read-only DB role с правом `SELECT` и правом создавать
   временные объекты в сессии.
2. Не использовать production superuser/admin credential.
3. Выбрать короткий период для первого запуска: 7 или 30 дней.
4. Указать абсолютные даты и момент снимка в часовом поясе `Europe/Moscow`, а
   не относительные формулировки.
5. Не включать подробные connection/debug логи в общий вывод.

Под `read-only DB role` здесь понимается отдельная роль с правами `SELECT` на
нужные таблицы и `TEMP` на database, но без `INSERT`, `UPDATE`, `DELETE` и прав
создания объектов в постоянных схемах. Режим
`default_transaction_read_only=on` с текущей версией SQL несовместим: PostgreSQL
блокирует в нём создание и удаление даже временных views. Если такой режим
обязателен политикой доступа, запуск нужно остановить, а SQL предварительно
переписать без временных объектов.

## Команда запуска

Пример, если запуск выполняется 2026-07-07 в 23:59:59 по Москве:

```bash
psql "$DATABASE_URL" \
  --no-psqlrc \
  --set ON_ERROR_STOP=1 \
  -v period_start="'2026-07-01 00:00:00'" \
  -v period_end="'2026-07-07 23:59:59'" \
  -v snapshot_at="'2026-07-07 23:59:59'" \
  -f docs/reference/analytics-kpi-production-safe.sql
```

`DATABASE_URL` должен указывать на production DB через read-only credential.
Само значение `DATABASE_URL` не копировать в чат, issue, PR или отчёт.
SQL устанавливает для текущей сессии часовой пояс `Europe/Moscow` и вычисляет
SLA-границу как `snapshot_at - 1 hour`. Все snapshot-метрики используют один и
тот же `snapshot_at`. Значение должно соответствовать фактическому времени
запуска: SQL читает текущие стадии, назначения и runtime-статусы и не
восстанавливает их историческое состояние на произвольную дату.

## Что можно передать обратно

Можно передать только результат таблиц, которые напечатал SQL-файл:

- `run_context`;
- `primary KPI summary`;
- `funnel aggregate`;
- `funnel by platform and connection type`;
- `operator backlog aggregate`;
- `operator backlog by age bucket`;
- `Bitrix24 status distribution`;
- `Bitrix24 failed operations by operation and error code`;
- `Open Lines binding/live status by platform and route status`;
- `channel runtime health aggregate`;
- `AI/collector guardrails`.

Перед отправкой проверить, что в выводе нет:

- connection string, host, user, password, token;
- `dialog_id`, `contact_id`, `channel_id`, `message_id`;
- телефона, имени, email, текста сообщения;
- `raw_payload`, `request_payload`, `response_payload`;
- `error_message` или stack trace.

## Стоп-условия

Остановиться и не продолжать запуск, если:

1. доступ не read-only;
2. команда просит пароль от admin/superuser;
3. SQL возвращает per-record строки или персональные данные;
4. возникает ошибка schema mismatch вроде `column does not exist`;
5. запрос упирается в timeout на коротком периоде;
6. терминал показывает connection string или секреты в ошибке;
7. политика доступа требует `default_transaction_read_only=on`.

В этих случаях передать обратно только короткое описание проблемы без секретов.

## Если timeout

1. Сначала сократить период до 1-7 дней.
2. Не поднимать `statement_timeout` на первом production-запуске.
3. Если короткий период тоже падает по timeout, остановиться и разобрать
   конкретный блок запроса локально по схеме.

## После запуска

1. Скопировать только агрегированный вывод.
2. Удалить локальный файл вывода, если он содержит production totals и не нужен
   для дальнейшего анализа.
3. Передать агрегаты для интерпретации KPI и уточнения следующих запросов.

Временные views из SQL-файла живут только в текущей DB-сессии и не меняют
production-схему.
