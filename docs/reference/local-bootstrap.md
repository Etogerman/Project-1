# Локальный bootstrap

Этот документ — практический вход в локальный старт Abrikosoff Connector
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
2. `npm install`
3. генерирует `APP_KEY`
4. `php artisan migrate`
5. `php artisan db:seed --class=AdminUserSeeder` (если задан `ADMIN_USER_SEEDER_PASSWORD` в `.env`)

Після того як контейнер піднявся, запусти dev-runtime:

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

1. `PHP 8.2+`
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

Предпочтительный локальный контур запуска:

```bash
composer dev
```

Этот script поднимает:

1. `php artisan serve`
2. `php artisan queue:listen --tries=1`
3. `php artisan pail --timeout=0`
4. `npm run dev`

Если нужен ручной запуск по процессам:

```bash
php artisan serve
php artisan queue:listen --tries=1
php artisan pail --timeout=0
npm run dev
```

## Быстрый локальный smoke

После старта runtime:

1. открой `http://127.0.0.1:8000/admin/login`
2. войди под `admin@abrikosoff.local`
3. проверь, что открывается админка
4. при необходимости отдельно запусти:

```bash
php artisan test
```

Playwright smoke и remote smoke описаны в
[docs/playwright.md](/Users/abrikosov/Documents/Проект-1/docs/playwright.md).

## Когда нужен дополнительный setup

Если задача зависит от Bitrix24, staging или внешнего webhook-контура, дальше
смотри:

1. [docs/bitrix24/setup-sheet.md](/Users/abrikosov/Documents/Проект-1/docs/bitrix24/setup-sheet.md)
2. [docs/staging-laravel-cloud.md](/Users/abrikosov/Documents/Проект-1/docs/staging-laravel-cloud.md)
3. [docs/post-deploy-smoke.md](/Users/abrikosov/Documents/Проект-1/docs/post-deploy-smoke.md)
