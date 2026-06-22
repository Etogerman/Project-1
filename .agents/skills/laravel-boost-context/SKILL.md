---
name: laravel-boost-context
description: Используй Laravel Boost MCP для контекста AB Connector по Laravel/Filament/Livewire. Срабатывает перед изменениями кода Laravel-экосистемы, работой с базой данных, моделями, миграциями, Filament actions/tables/forms, диагностикой routes/config/logs, поиском документации по версиям Laravel-пакетов или локальными runtime-проверками, если Boost доступен.
---

# Контекст Laravel Boost

Используй Laravel Boost как первый источник фактов о Laravel-экосистеме в этом репозитории. По умолчанию работай read-only и сохраняй все правила доставки AB Connector из `AGENTS.md`.

## Рабочий порядок

1. Подтверждай факты проекта через Boost до выводов по памяти.
   - Используй `application-info` в начале Laravel/Filament/Livewire-задачи, чтобы зафиксировать версии PHP, Laravel, пакетов и базы данных.
   - Если Boost MCP недоступен, явно скажи об этом и переходи к локальным файлам и обычным командам.

2. Используй точечные инструменты Boost под задачу.
   - Структура базы данных: `database-schema`, сначала в режиме `summary`, затем с узким `filter`.
   - Просмотр данных: `database-query` только с read-only SQL (`select`, `show`, `explain`, `describe`).
   - Runtime-проблемы: `last-error`, `read-log-entries` и `browser-logs` до широкого поиска по логам.
   - URL и маршруты: `get-absolute-url` до догадок о сгенерированных URL.
   - Документация: `search-docs` по пакетам Laravel-экосистемы до поиска в интернете или утверждений об API по памяти.

3. Держи рамки безопасными.
   - Не используй Boost, чтобы обходить тесты, этапы ревью, правила Spec repo, PR checkpoints, staging-first rollout, пользовательские merge-правила, deploy gates или smoke checks.
   - Не запускай через Boost mutating SQL, изменяющий Tinker-код, миграции, seeders, действия queue/scheduler, вызовы внешних интеграций или изменения config/env без явной делегации пользователя именно на эту операцию.
   - Bitrix24, Open Lines, Telegram, MAX, queues, scheduler и production/staging runtime checks считай integration/runtime-работой по `AGENTS.md`.

4. Явно сообщай факты, полученные через Boost.
   - Указывай, какой инструмент Boost дал факт, если он влияет на реализацию или рекомендацию.
   - Отделяй проверенные факты от выводов.
   - Если поиск Boost docs даёт шумные результаты, уточняй запрос, а не считай первый результат авторитетным.

## Заметки по локальному runtime

Эта ветка настраивает Boost через `.mcp.json` и `.codex/config.toml` командой:

```bash
docker compose exec -T dev php artisan boost:mcp
```

Локальный контейнер `dev` должен монтировать текущий worktree в `/var/www/html`. Если Docker показывает несовпадение path/mount, сначала исправь local runtime identity, и только потом используй результаты Boost для операторской приёмки.

## Передача результата

При передаче результата укажи:
- использовался ли Boost;
- какие инструменты использовались;
- все ли Boost-операции были read-only;
- были ли найдены несовпадение локального runtime или проблемы зависимостей при работе с Boost.
