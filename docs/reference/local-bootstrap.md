# Локальный bootstrap

Этот документ — практический вход в локальный старт AB Connector
без чтения `composer.json`, исходников и тестов.

## Рекомендованный путь — VS Code Dev Container

### Что нужно заранее

1. [Docker Desktop](https://www.docker.com/products/docker-desktop/)
2. VS Code с расширением [Dev Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers)

### Запуск

Перед стартом контейнера подготовь Laravel `.env` вручную:

```bash
cp .env.example .env
```

```
Ctrl+Shift+P → Dev Containers: Reopen in Container
```

После старта `entrypoint.sh` выполняет:

1. `composer install`
2. проверяет и при необходимости обновляет Node-зависимости
3. генерирует `APP_KEY`
4. `php artisan migrate`
5. `php artisan db:seed --class=AdminUserSeeder` (если задан `ADMIN_USER_SEEDER_PASSWORD` в `.env`)

После того как контейнер поднялся, запусти dev-runtime:

```bash
composer dev
```

### Чистый Docker (без VS Code)

Требует только Docker Desktop. Перед запуском так же подготовь Laravel `.env` вручную:

```bash
cp .env.example .env
```

```bash
docker compose up -d
```

Если нужен `ngrok`, создай отдельный compose-only файл `.env.ngrok` рядом с `docker-compose.yml`:

```bash
cp .env.ngrok.example .env.ngrok
```

И заполни в нём:

```bash
NGROK_AUTHTOKEN=your_token
```

Затем войди в контейнер и запусти dev-runtime:

```bash
docker compose exec dev bash
composer dev
```

### Сервисы в контейнере

| Сервис             | Адрес                 | Примечание                 |
| ------------------ | --------------------- | -------------------------- |
| Laravel Dev Server | http://127.0.0.1:8000 | `php artisan serve`        |
| Vite Dev Server    | http://127.0.0.1:5173 | `npm run dev`              |
| PostgreSQL 16      | 127.0.0.1:5432        | DB: `abrikosoff_connector` |
| Redis 7.4          | 127.0.0.1:6379        |                            |
| Mailpit UI         | http://127.0.0.1:8025 | перехват email             |
| Adminer            | http://127.0.0.1:8080 | веб-интерфейс для DB       |

VS Code расширения (PHP Intelephense, Laravel, GitLens и др.) устанавливаются
автоматически при первом запуске контейнера.

---

## Ручной старт (без Docker)

### Что нужно заранее

Нужны:

1. `PHP 8.3+`
2. `Composer`
3. `Node.js` и `npm`
4. `PostgreSQL`

## Первый запуск

### 1. Установить зависимости

```bash
composer install
npm install
```

### 2. Подготовить `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Базовая локальная конфигурация уже есть в [.env.example](/Users/abrikosov/Documents/Проект-1/.env.example).

Обрати внимание:

1. локальный `DB_CONNECTION` по умолчанию — `pgsql`
2. локальный `QUEUE_CONNECTION` по умолчанию — `database`
3. локальный `APP_URL` по умолчанию — `http://127.0.0.1:8000`

### 3. Подготовить базу данных

Создай локальную PostgreSQL database `abrikosoff_connector` или измени
`DB_*`-значения в `.env` под свой локальный контур.

Потом выполни:

```bash
php artisan migrate
```

### 4. Создать локального администратора

```bash
export ADMIN_USER_SEEDER_PASSWORD='replace-with-local-secret'
php artisan db:seed --class=AdminUserSeeder
```

Что важно:

1. `AdminUserSeeder` не вызывается через обычный `php artisan db:seed`
2. логин локального администратора: `admin@abrikosoff.local`
3. пароль задаётся через `ADMIN_USER_SEEDER_PASSWORD`

## Ежедневный локальный runtime

Предпочтительный локальный контур запуска без Docker:

```bash
composer dev
```

Этот script поднимает:

1. проверку актуальности Node-зависимостей
2. `php artisan serve --host=0.0.0.0`
3. `php artisan schedule:work`
4. queue workers для `bot-replies`, `bitrix-live` и `default`
5. `php artisan pail --timeout=0`
6. `npm run build -- --watch`

Предпочтительный Docker-запуск:

```bash
composer docker:up
```

Он запускает тот же полный dev-runtime внутри сервиса `dev`. Для просмотра логов в
foreground используй:

```bash
composer docker:dev
```

Нельзя считать локальный runtime готовым, если запущен только `php artisan serve`.
Для интеграционных сценариев с каналами связи обязательно должны работать:

1. web-server;
2. `php artisan schedule:work`;
3. queue workers для `bot-replies`, `bitrix-live` и `default`.

Если scheduler не запущен, `channels:check-connections` не выполняется каждую
минуту, и bot-каналы со статусом `connected/installed` переходят в
`Проверка устарела` после истечения TTL проверки.

## Обязательный runtime identity check

Перед локальным smoke, восстановлением данных, подключением каналов или
переходом к внешним интеграциям нужно доказать, что проверяется именно тот
runtime, который ожидается.

Минимальная сверка:

1. пользовательский URL, например `APP_URL` или tunnel URL;
2. процесс или контейнер, который обслуживает этот URL;
3. смонтированный каталог проекта или worktree;
4. активная ветка и commit в этом каталоге;
5. видимый в админке `rev`;
6. имя подключенной базы данных;
7. полный набор runtime-процессов: web-server, scheduler, очереди и сборка.

Команды для быстрой сверки в обычном локальном контуре:

```bash
php artisan about --only=environment
php artisan tinker --execute='echo config("app.url").PHP_EOL.config("database.default").PHP_EOL.config("database.connections.pgsql.database");'
git rev-parse --short HEAD
```

Если URL обслуживается Docker-контейнером, дополнительно проверь compose-project
и mount:

```bash
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}} {{index .Config.Labels "com.docker.compose.service"}}' <container>
docker inspect -f '{{range .Mounts}}{{println .Source "->" .Destination}}{{end}}' <container>
```

Если URL ведёт на другой worktree, другой compose-project, другой commit или
другую базу, smoke текущей задачи не считается валидным. Сначала переключи URL
на правильный runtime или явно зафиксируй, что проверяется отдельный recovery /
demo / legacy-контур.

## Режим данных

Перед настройкой системы зафиксируй режим локальной базы:

1. `clean install` — пустая база после миграций и минимального seed;
2. `recovery` — контур восстановления или ручной shell-seed;
3. `existing data` — сохранённые реальные локальные данные;
4. `demo/test` — демонстрационные или тестовые записи.

Recovery seed не является восстановлением пользовательских данных. Если после
seed видны локальные каналы, один контакт или тестовый диалог, это только
минимальный рабочий shell-контур. Нельзя говорить, что восстановлены прежние
диалоги, контакты, каналы, сценарии или автоответчики, пока counts и ключевые
экраны не подтверждают это явно.

Для test database действует отдельный runbook:
[docs/runbooks/test-env.md](../runbooks/test-env.md).

Если нужен ручной запуск по процессам:

```bash
sh scripts/ensure-node-deps.sh
php artisan serve --host=0.0.0.0
php artisan schedule:work
sh scripts/dev-queue-worker.sh bot-replies 0
sh scripts/dev-queue-worker.sh bitrix-live 1
sh scripts/dev-queue-worker.sh default 1
php artisan pail --timeout=0
npm run build -- --watch
```

## Быстрый локальный smoke

После старта runtime локальный контур должен позволять доводить задачу до первого локально тестируемого MVP.

Минимальный локальный verification kit:

1. выполни `runtime identity check`;
2. зафиксируй режим данных: clean / recovery / existing data / demo;
3. открой `APP_URL/admin/login`, например `http://127.0.0.1:8000/admin/login`;
4. войди под `admin@abrikosoff.local`;
5. проверь, что открывается админка и основной UI-сценарий текущей задачи реально воспроизводим;
6. если нужны тесты, сначала подготовь безопасный test-контур, затем запусти:

```bash
php artisan test
```

7. если задача затрагивает живой UI-flow, при необходимости добавь локальный Playwright smoke.

Playwright smoke и remote smoke описаны в
[docs/playwright.md](/Users/abrikosov/Documents/Проект-1/docs/playwright.md).

## Безопасный test-контур

Канонический runbook: [docs/runbooks/test-env.md](../runbooks/test-env.md).

Перед запуском `php artisan test` проверь, что effective `DB_DATABASE` указывает
на отдельную test database. `tests/bootstrap.php` блокирует запуск, если база не
похожа на test/testing database или совпадает с известным runtime-именем.

Текущий PHPUnit baseline:

1. `APP_ENV=testing`;
2. `DB_DATABASE=abrikosoff_connector_test`;
3. `QUEUE_CONNECTION=sync`;
4. `SESSION_DRIVER=array`;
5. `CACHE_STORE=array`.

`QUEUE_CONNECTION`, `SESSION_DRIVER` и `CACHE_STORE` в `phpunit.xml` сейчас
заданы без `force="true"`, поэтому при странном поведении тестов проверь
effective env внутри конкретного shell/container.

Если bootstrap пишет `Refusing to run tests against non-test database`, защита
сработала правильно. Исправь test database config, а не отключай guard.

Важно: `.devcontainer/init.sql` создаёт `laravel_testing`, а `phpunit.xml`
использует `abrikosoff_connector_test`. Это нужно выровнять отдельным
code/config follow-up, если тесты запускаются через devcontainer.

## Когда нужен дополнительный setup

Если задача зависит от Bitrix24, staging или внешнего webhook-контура, дальше
смотри:

1. [docs/bitrix24/setup-sheet.md](/Users/abrikosov/Documents/Проект-1/docs/bitrix24/setup-sheet.md)
2. [docs/staging-laravel-cloud.md](/Users/abrikosov/Documents/Проект-1/docs/staging-laravel-cloud.md)
3. [docs/post-deploy-smoke.md](/Users/abrikosov/Documents/Проект-1/docs/post-deploy-smoke.md)
