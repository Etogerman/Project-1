# Abrikosoff Connector

Abrikosoff Connector — операторская Laravel-платформа для работы с входящими
сообщениями из Telegram и MAX, с админкой на Filament, rule-based автоответами,
collector flow и Bitrix24 integration.

## Быстрый локальный старт

Полный локальный runbook описан в [docs/reference/local-bootstrap.md](docs/reference/local-bootstrap.md).
Короткий стартовый контур такой:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate

export ADMIN_USER_SEEDER_PASSWORD='replace-with-local-secret'
php artisan db:seed --class=AdminUserSeeder

composer dev
```

После старта:

1. открой `http://127.0.0.1:8000/admin/login`
2. войди под `admin@abrikosoff.local`
3. используй пароль из `ADMIN_USER_SEEDER_PASSWORD`

## Карта документации

- [AGENTS.md](AGENTS.md) — верхнеуровневые рамки, инварианты и правила работы агента
- [docs/reference/project-scope.md](docs/reference/project-scope.md) — проектовый контур и текущие жёсткие границы
- [docs/reference/local-bootstrap.md](docs/reference/local-bootstrap.md) — локальный bootstrap и ежедневный dev-runtime
- [docs/reference/specs-pointer.md](docs/reference/specs-pointer.md) — как устроен внешний `specs`-контур и как ссылаться на `Spec repo / Spec doc / Spec revision`
- [docs/reference/active-specs.md](docs/reference/active-specs.md) — какие существенные внешние ТЗ реально открыты сейчас в основном repo
- [docs/task-delivery-workflow.md](docs/task-delivery-workflow.md) — canonical delivery-playbook
- [docs/clean-stream-release-flow.md](docs/clean-stream-release-flow.md) — дополнительный appendix по clean-stream extraction и mixed-state cleanup
- [docs/staging-laravel-cloud.md](docs/staging-laravel-cloud.md) — staging, deploy baseline и real Bitrix integration flow
- [docs/post-deploy-smoke.md](docs/post-deploy-smoke.md) — post-deploy smoke и rev-check контур
- [docs/playwright.md](docs/playwright.md) — Playwright smoke, локальный и remote запуск
- [docs/backup.md](docs/backup.md) — backup и verify runbook
- [docs/bitrix24/setup-sheet.md](docs/bitrix24/setup-sheet.md) — frozen Bitrix24 ops/setup sheet с подтверждёнными integration values
- [docs/dialog-workspace.md](docs/dialog-workspace.md) — текущая модель `Contact overview / Dialog workspace`

## Внешние ТЗ

Существенные stream-ы используют внешний `specs`-repo. Практический указатель и
рабочая схема описаны в [docs/reference/specs-pointer.md](docs/reference/specs-pointer.md).
Локальный реестр того, что реально открыто сейчас в основном repo, находится в
[docs/reference/active-specs.md](docs/reference/active-specs.md).

Короткая формула:

1. активные существенные ТЗ живут во внешнем `Project-1-specs`
2. для code stream фиксируются `Spec repo`, `Spec doc`, `Spec revision`
3. локальный [docs/reference/active-specs.md](docs/reference/active-specs.md) показывает, какие из них реально открыты сейчас, либо явно фиксирует их отсутствие
4. локальный `docs/tz/` не хранит полные stream-ТЗ как source of truth

## Тесты и smoke

- полный suite: `php artisan test`
- CI-friendly shard suites:
  - `composer test:feature:ui`
  - `composer test:feature:bots`
  - `composer test:feature:bitrix`
  - `composer test:feature:collector`
  - `composer test:feature:domain`
- агрегирующий прогон для CI: `composer test:ci`
- локальный полный dev-runtime: `composer dev`

## Справка по framework

Проект остаётся Laravel 11 application, но источник истины по runtime,
workflow и integration-контрактам находится в локальных project docs, а не в
стоковом Laravel boilerplate.

Если нужна именно framework-справка:

- [Laravel documentation](https://laravel.com/docs)
