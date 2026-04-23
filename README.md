# Abrikosoff Connector

Операторская Laravel-платформа: Telegram/MAX, Filament, rule-based автоответы, collector flow, Bitrix24.

## Старт

### 1. Подготовь Laravel `.env`

```bash
cp .env.example .env
```

### 2. Запусти контейнер

**VS Code Dev Container** — нужны [Docker Desktop](https://www.docker.com/products/docker-desktop/) и расширение [Dev Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers):

```
Ctrl+Shift+P → Dev Containers: Reopen in Container
```

**Чистый Docker:**

```bash
docker compose up -d
```

### 3. Настрой ngrok отдельно от Laravel `.env`

Без ngrok Telegram/MAX не смогут доставлять webhook-сообщения на локальный сервер.

1. Зарегистрируйся на [ngrok.com](https://ngrok.com)
2. Получи [Authtoken](https://dashboard.ngrok.com/get-started/your-authtoken) и [Static Domain](https://dashboard.ngrok.com/endpoints)
3. Скопируй `.env.ngrok.example` в `.env.ngrok` и заполни токен:

```bash
cp .env.ngrok.example .env.ngrok
```

```
NGROK_AUTHTOKEN=your_token
```

4. Укажи public URL приложения в Laravel `.env`:

```
APP_URL=https://your-name.ngrok-free.app
```

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
docker compose exec dev composer dev
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
