# Telegram Local Bot API

Этот runbook описывает транспорт Telegram Bot для ручной загрузки входящих
файлов крупнее облачного лимита Telegram Bot API.

Поддерживаются два способа чтения файла:

- `filesystem` — PHP и Local Bot API используют общий каталог; только локальный
  Docker Compose;
- `http_bridge` — защищённый gateway проксирует методы Local Bot API и потоково
  отдаёт файл PHP; этот режим не требует общего filesystem с Laravel Cloud.

## Границы безопасности

- Используй отдельного локального или тестового бота.
- Не включай этот транспорт для staging/production-токена без согласованного
  identity gate и окна переключения.
- Один токен не должен одновременно обслуживаться cloud Bot API и Local Bot API.
- Не коммить `.env.telegram-bot-api`, токены, `TELEGRAM_API_ID` и
  `TELEGRAM_API_HASH`, а также пароль file bridge.
- Запуск контейнера сам по себе не переключает приложение на Local Bot API.
- Gateway и Local Bot API не публикуются в открытый интернет. Для удалённого
  runtime нужен защищённый внутренний маршрут либо HTTPS reverse proxy с
  сетевым ограничением. Laravel Cloud обращается к gateway с Basic Auth, а
  исходный Local Bot API остаётся доступен только внутри companion-контура.

Перед использованием токена, который мог применяться в другой среде, оператор
обязан проверить его текущий webhook через cloud `getWebhookInfo` и сверить
окружение. Несовпадение с ожидаемым локальным URL означает стоп. `logOut` и
переключение общего токена не выполняются в рамках обычного локального запуска.

## Первый запуск

1. Создай локальный файл секретов:

   ```bash
   cp .env.telegram-bot-api.example .env.telegram-bot-api
   cp .env.telegram-bot-api-file-bridge.example .env.telegram-bot-api-file-bridge
   ```

2. Заполни `TELEGRAM_API_ID` и `TELEGRAM_API_HASH`, полученные на
   `my.telegram.org`.

3. Подготовь общий каталог файлов:

   ```bash
   mkdir -p storage/app/telegram-bot-api/tmp
   ```

4. Заполни случайный пароль в `.env.telegram-bot-api-file-bridge`.

5. Добавь в основной `.env` для Cloud-подобного HTTP transport:

   ```dotenv
   TELEGRAM_LOCAL_BOT_API_MEDIA_DOWNLOAD_ENABLED=true
   TELEGRAM_LOCAL_BOT_API_BASE_URL=http://telegram-bot-api-file-bridge:8082/api
   TELEGRAM_LOCAL_BOT_API_TRUSTED_HOSTS=telegram-bot-api-file-bridge
   TELEGRAM_LOCAL_BOT_API_USERNAME=media-reader
   TELEGRAM_LOCAL_BOT_API_PASSWORD=<тот же случайный пароль>
   TELEGRAM_LOCAL_BOT_API_ALLOW_INSECURE_HTTP=true
   TELEGRAM_LOCAL_BOT_API_FILE_TRANSPORT=http_bridge
   TELEGRAM_LOCAL_BOT_API_FILES_ROOT=/var/www/html/storage/app/telegram-bot-api
   TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_BASE_URL=http://telegram-bot-api-file-bridge:8082/files
   TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_TRUSTED_HOSTS=telegram-bot-api-file-bridge
   TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_USERNAME=media-reader
   TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_PASSWORD=<тот же случайный пароль>
   TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_MAX_BYTES=67108864
   ```

   Для прежнего локального shared-volume режима задай
   `TELEGRAM_LOCAL_BOT_API_BASE_URL=http://telegram-bot-api:8081` и
   `TELEGRAM_LOCAL_BOT_API_FILE_TRANSPORT=filesystem`; gateway-переменные и
   логин/пароль Local API тогда не требуются.

   `FILE_BRIDGE_MAX_BYTES` ограничивает размер с учётом временного диска PHP.
   Значение по умолчанию — 64 МБ, поэтому файл 51 МБ поддерживается с запасом
   для параллельных загрузок. Для
   большего лимита сначала проверь свободный ephemeral disk Laravel Cloud.
   Лимит в гигабайтах требует отдельной прямой multipart-загрузки в Object
   Storage без промежуточного файла PHP.

   Перед увеличением лимита учти суммарный временный объём всех активных
   загрузок. Пока нет отдельной reservation для ephemeral disk, на Cloud оставь
   `INBOUND_MEDIA_GLOBAL_MAX_ACTIVE=1` и
   `INBOUND_MEDIA_CHANNEL_MAX_ACTIVE=1` либо докажи запас диска под выбранную
   параллельность.

   `ALLOW_INSECURE_HTTP=true` допустим только внутри локальной Docker-сети. Для
   Laravel Cloud оставь `false`, опубликуй gateway через HTTPS и укажи HTTPS URL
   для `/api` и `/files`. На внешнем reverse proxy отключи access logs либо
   редактируй пути: Bot API содержит токен бота в URL.

6. Запусти Local Bot API и bridge в том же Docker Compose project, где работает
   `dev`:

   ```bash
   docker compose --profile telegram-local-bot-api up -d --build telegram-bot-api telegram-bot-api-file-bridge
   docker compose restart dev
   ```

Если основной runtime запущен с `docker compose -p <project>`, добавь тот же
`-p <project>` в обе команды. Иначе появится второй изолированный compose-контур,
который приложение не увидит.

## Проверка

```bash
docker compose --profile telegram-local-bot-api ps telegram-bot-api
docker compose --profile telegram-local-bot-api ps telegram-bot-api-file-bridge
docker compose --profile telegram-local-bot-api logs --tail=100 telegram-bot-api
docker compose exec -T dev php artisan tinker --execute="dump([
    'enabled' => (bool) config('bots.telegram.local_api_media_download_enabled'),
    'transport' => config('bots.telegram.local_api_file_transport'),
    'api_auth_configured' => filled(config('bots.telegram.local_api_username'))
        && filled(config('bots.telegram.local_api_password')),
    'file_auth_configured' => filled(config('bots.telegram.local_api_file_bridge_username'))
        && filled(config('bots.telegram.local_api_file_bridge_password')),
]);"
```

Ожидаемый результат:

- контейнер `telegram-bot-api` имеет статус `healthy`;
- контейнер `telegram-bot-api-file-bridge` имеет статус `healthy` для режима
  `http_bridge`;
- `local_api_media_download_enabled` равно `true`;
- в режиме `http_bridge` base URL равен
  `http://telegram-bot-api-file-bridge:8082/api`, а API-вызовы защищены Basic
  Auth;
- files root совпадает с каталогом, смонтированным в `dev` и
  `telegram-bot-api`.

Затем отправь локальному боту файл крупнее автоматического лимита. В диалоге
должно появиться действие `Скачать вручную`; после нажатия файл переходит в
загрузку и отображается без обновления страницы.

## Остановка

```bash
docker compose --profile telegram-local-bot-api stop telegram-bot-api-file-bridge telegram-bot-api
```

После остановки верни в `.env`:

```dotenv
TELEGRAM_LOCAL_BOT_API_MEDIA_DOWNLOAD_ENABLED=false
```

и перезапусти `dev`.

## Перед удалённым companion и staging

Локальный Compose не создаёт сервис, доступный Laravel Cloud. До staging нужен
отдельный persistent companion-контур с Local Bot API и gateway:

- HTTPS DNS, доступный Cloud workers; исходный порт Local Bot API остаётся
  приватным;
- Basic Auth, сетевое ограничение и отключённые либо редактирующие URI access,
  error и APM logs;
- upstream/response/read timeout не меньше
  `INBOUND_MEDIA_ATTEMPT_DEADLINE_SECONDS` (по умолчанию 21600 секунд), а для
  `/files` отключены response buffering и request buffering;
- отдельный persistent volume с quota, алертами high-watermark и проверенной
  cache rotation. Retention Object Storage не очищает кэш Local Bot API;
- до очистки кэша остановлены Local Bot API и gateway, а нужные файлы уже
  подтверждены в Object Storage. Команду очистки согласовать отдельно под
  фактическую схему каталога и backup policy.

Включение `TELEGRAM_LOCAL_BOT_API_MEDIA_DOWNLOAD_ENABLED` переключает на Local
Bot API все методы бота, не только скачивание. Для общего staging-токена нужен
отдельный cutover: cloud `getWebhookInfo` → согласованный cloud `logOut` → local
`setWebhook` → проверка входящих и исходящих сообщений → готовый rollback. Не
выполняй этот cutover одним изменением env.

## Диагностика

- `Доступно для загрузки` без последующей загрузки: проверь, что контейнер
  `healthy`, а приложение использует тот же compose project.
- Ошибка облачного лимита Telegram: Local Bot API выключен, недоступен или токен
  не обслуживается локальным транспортом.
- Ошибка доверенного host/path: проверь `TELEGRAM_LOCAL_BOT_API_TRUSTED_HOSTS` и
  `TELEGRAM_LOCAL_BOT_API_FILES_ROOT`; для bridge также проверь его base URL,
  trusted hosts и Basic Auth. Не расширяй списки внешними host-ами.
- HTTP `401` от gateway: пароль приложения и companion-контейнера различается;
  проверь обе пары API и file bridge переменных.
- HTTP `404` от bridge: Local Bot API вернул путь вне общего read-only volume
  либо bridge смонтировал другой каталог.
- После изменения `.env` выполни `docker compose restart dev`, чтобы workers и
  web-процесс получили одинаковую конфигурацию.
