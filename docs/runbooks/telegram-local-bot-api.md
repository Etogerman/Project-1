# Telegram Local Bot API

Этот runbook описывает локальный транспорт Telegram Bot для ручной загрузки
входящих файлов крупнее облачного лимита Telegram Bot API.

## Границы безопасности

- Используй отдельного локального или тестового бота.
- Не включай этот транспорт для staging/production-токена без согласованного
  identity gate и окна переключения.
- Один токен не должен одновременно обслуживаться cloud Bot API и Local Bot API.
- Не коммить `.env.telegram-bot-api`, токены, `TELEGRAM_API_ID` и
  `TELEGRAM_API_HASH`.
- Запуск контейнера сам по себе не переключает приложение на Local Bot API.

Перед использованием токена, который мог применяться в другой среде, оператор
обязан проверить его текущий webhook через cloud `getWebhookInfo` и сверить
окружение. Несовпадение с ожидаемым локальным URL означает стоп. `logOut` и
переключение общего токена не выполняются в рамках обычного локального запуска.

## Первый запуск

1. Создай локальный файл секретов:

   ```bash
   cp .env.telegram-bot-api.example .env.telegram-bot-api
   ```

2. Заполни `TELEGRAM_API_ID` и `TELEGRAM_API_HASH`, полученные на
   `my.telegram.org`.

3. Подготовь общий каталог файлов:

   ```bash
   mkdir -p storage/app/telegram-bot-api/tmp
   ```

4. Добавь в основной `.env`:

   ```dotenv
   TELEGRAM_LOCAL_BOT_API_MEDIA_DOWNLOAD_ENABLED=true
   TELEGRAM_LOCAL_BOT_API_BASE_URL=http://telegram-bot-api:8081
   TELEGRAM_LOCAL_BOT_API_TRUSTED_HOSTS=telegram-bot-api
   TELEGRAM_LOCAL_BOT_API_FILES_ROOT=/var/www/html/storage/app/telegram-bot-api
   ```

5. Запусти сервис в том же Docker Compose project, где работает `dev`:

   ```bash
   docker compose --profile telegram-local-bot-api up -d --build telegram-bot-api
   docker compose restart dev
   ```

Если основной runtime запущен с `docker compose -p <project>`, добавь тот же
`-p <project>` в обе команды. Иначе появится второй изолированный compose-контур,
который приложение не увидит.

## Проверка

```bash
docker compose --profile telegram-local-bot-api ps telegram-bot-api
docker compose --profile telegram-local-bot-api logs --tail=100 telegram-bot-api
docker compose exec -T dev php artisan config:show bots.telegram
```

Ожидаемый результат:

- контейнер `telegram-bot-api` имеет статус `healthy`;
- `local_api_media_download_enabled` равно `true`;
- base URL равен `http://telegram-bot-api:8081`;
- files root совпадает с каталогом, смонтированным в `dev` и
  `telegram-bot-api`.

Затем отправь локальному боту файл крупнее автоматического лимита. В диалоге
должно появиться действие `Скачать вручную`; после нажатия файл переходит в
загрузку и отображается без обновления страницы.

## Остановка

```bash
docker compose --profile telegram-local-bot-api stop telegram-bot-api
```

После остановки верни в `.env`:

```dotenv
TELEGRAM_LOCAL_BOT_API_MEDIA_DOWNLOAD_ENABLED=false
```

и перезапусти `dev`.

## Диагностика

- `Доступно для загрузки` без последующей загрузки: проверь, что контейнер
  `healthy`, а приложение использует тот же compose project.
- Ошибка облачного лимита Telegram: Local Bot API выключен, недоступен или токен
  не обслуживается локальным транспортом.
- Ошибка доверенного host/path: проверь `TELEGRAM_LOCAL_BOT_API_TRUSTED_HOSTS` и
  `TELEGRAM_LOCAL_BOT_API_FILES_ROOT`; не расширяй список внешними host-ами.
- После изменения `.env` выполни `docker compose restart dev`, чтобы workers и
  web-процесс получили одинаковую конфигурацию.
