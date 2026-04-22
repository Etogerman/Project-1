# Abrikosoff Connector

Операторская Laravel-платформа: Telegram/MAX, Filament, rule-based автоответы, collector flow, Bitrix24.

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
docker compose up -d
```

Контейнер автоматически копирует `.env`, ставит зависимости и применяет миграции.

### 4. Запусти dev-сервер

**VS Code Dev Container** — в терминале контейнера:

```bash
composer dev
```

**Чистый Docker:**

```bash
docker compose exec dev bash
composer dev
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
composer test:ci          # CI-агрегированный прогон
```

## Документация

- [docs/reference/local-bootstrap.md](docs/reference/local-bootstrap.md) — локальный bootstrap и daily dev-runtime
- [AGENTS.md](AGENTS.md) — инварианты и правила агента
- [docs/task-delivery-workflow.md](docs/task-delivery-workflow.md) — delivery-playbook
- [docs/reference/project-scope.md](docs/reference/project-scope.md) — проектный контур
- [docs/reference/active-specs.md](docs/reference/active-specs.md) — открытые внешние ТЗ
