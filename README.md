# Abrikosoff Connector

Abrikosoff Connector — операторская Laravel-платформа для работы с входящими
сообщениями из Telegram и MAX, с админкой на Filament, rule-based автоответами,
collector flow и Bitrix24 integration.

## Быстрый локальный старт

## Composer команды

Основные shortcuts из `composer.json`, которые заменяют типовые ручные вызовы:

```bash
composer dev                     # локальный dev-runtime вне Docker
composer docker:build           # собрать docker-образы
composer docker:up              # поднять контейнеры в фоне
composer docker:dev             # запустить composer dev внутри контейнера dev
composer docker:down            # остановить контейнеры
composer docker:remove          # остановить контейнеры и удалить volumes
composer test:feature:ui        # UI feature shard
composer test:feature:bots      # bots/webhook shard
composer test:feature:bitrix    # Bitrix24 shard
composer test:feature:collector # collector shard
composer test:feature:domain    # domain shard
composer test:ci                # агрегированный CI-friendly прогон
```

## Старт

### 1. Скопируй `.env.example`

```bash
cp .env.example .env
```

### 2. Настрой ngrok (до запуска контейнеров)

Без ngrok Telegram/MAX не смогут доставлять webhook-сообщения на локальный сервер.

1. Зарегистрируйся на [ngrok.com](https://ngrok.com)
2. Получи [Authtoken](https://dashboard.ngrok.com/get-started/your-authtoken) и [Static Domain](https://dashboard.ngrok.com/endpoints)
3. Заполни в `.env`:

```
NGROK_AUTHTOKEN=your_token
APP_URL=https://your-name.ngrok-free.app
```

### 3. Запусти контейнер

**VS Code Dev Container** — нужны [Docker Desktop](https://www.docker.com/products/docker-desktop/) и расширение [Dev Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers):

```
Ctrl+Shift+P → Dev Containers: Reopen in Container
```

**Чистый Docker:**

```bash
composer docker:up
```

Прямой эквивалент: `docker compose up -d`.

Контейнер автоматически копирует `.env`, ставит зависимости и применяет миграции.

### 4. Запусти dev-сервер

**VS Code Dev Container** — в терминале контейнера:

```bash
composer dev
```

**Чистый Docker:**

Удобнее сначала войти в контейнер и работать внутри него:

```bash
docker compose exec dev bash
```

Затем в той же сессии:

```bash
composer dev
```

Или одной командой без входа в контейнер:

```bash
composer docker:dev
```

| Сервис   | Адрес                       |
| -------- | --------------------------- |
| Admin UI | http://localhost:8000/admin |
| Mailpit  | http://localhost:8025       |
| Adminer  | http://localhost:8080       |
| ngrok    | http://localhost:4040       |

Логин: `admin@abrikosoff.local`, пароль из `ADMIN_USER_SEEDER_PASSWORD`.

## Тесты

```bash
php artisan test          # полный suite
composer test:feature:ui  # UI shard
composer test:feature:bots
composer test:feature:bitrix
composer test:feature:collector
composer test:feature:domain
composer test:ci          # CI-агрегированный прогон
```

## Карта документации

- [AGENTS.md](AGENTS.md) — верхнеуровневые рамки, инварианты и правила работы агента
- [docs/reference/project-scope.md](docs/reference/project-scope.md) — проектовый контур и текущие жёсткие границы
- [docs/reference/local-bootstrap.md](docs/reference/local-bootstrap.md) — локальный bootstrap и ежедневный dev-runtime
- [docs/task-delivery-workflow.md](docs/task-delivery-workflow.md) — canonical delivery-playbook; согласованный локальный MVP ведёт к операторской приёмке, а выход в `staging` начинается после явного решения о выкладке
- [docs/clean-stream-release-flow.md](docs/clean-stream-release-flow.md) — дополнительный appendix по clean-stream extraction и mixed-state cleanup
- [docs/staging-laravel-cloud.md](docs/staging-laravel-cloud.md) — staging, deploy baseline и real Bitrix integration flow
- [docs/post-deploy-smoke.md](docs/post-deploy-smoke.md) — post-deploy smoke и rev-check контур
- [docs/playwright.md](docs/playwright.md) — Playwright smoke, локальный и remote запуск
- [docs/backup.md](docs/backup.md) — backup и verify runbook
- [docs/bitrix24/setup-sheet.md](docs/bitrix24/setup-sheet.md) — frozen Bitrix24 ops/setup sheet с подтверждёнными integration values
- [docs/bitrix24/dev-local-setup.md](docs/bitrix24/dev-local-setup.md) — как разработчику подключить свою локалку к общему Bitrix24 staging через `dev-*` profile
- [docs/dialog-workspace.md](docs/dialog-workspace.md) — текущая модель `Contact overview / Dialog workspace`

## Внешние ТЗ

Полные опубликованные ТЗ, статусы ТЗ, реестр активных работ и архив живут во внешнем репозитории документации: `Etogerman/Project-1-specs`.

`Project-1-specs` считается внешним контуром, а не локальным местом для ранних черновиков. До операторского решения о выкладке существенный stream может идти по ТЗ из чата или явно указанного локального черновика вне `Project-1` и вне `Project-1-specs`.

Основной проект не хранит живые указатели на версии ТЗ и не хранит реестр активных работ. Для конкретной работы актуальные `Spec repo`, `Spec doc` и `Spec revision` фиксируются во внешнем репозитории документации, в описании задачи или в PR перед первым внешним code/runtime действием.

## Тесты и smoke

- полный suite: `php artisan test`
- CI-friendly shard suites:
  - `composer test:feature:ui`
  - `composer test:feature:bots`
  - `composer test:feature:bitrix`
  - `composer test:feature:collector`
  - `composer test:feature:domain`
- текущий GitHub Actions Bitrix-only shard: `composer test:feature:bitrix`
  - это не заменяет полный локальный `composer test:ci`
- агрегирующий локальный прогон: `composer test:ci`
- локальный полный dev-runtime: `composer dev`

## Справка по framework

Проект остаётся Laravel 11 application, но источник истины по runtime,
workflow и integration-контрактам находится в локальных project docs, а не в
стоковом Laravel boilerplate.

Если нужна именно framework-справка:

- [Laravel documentation](https://laravel.com/docs)
