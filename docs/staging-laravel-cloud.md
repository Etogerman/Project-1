# Staging on Laravel Cloud

## Текущий статус

Этот документ описывает staging как реальное интеграционное окружение.

При текущем контуре:

- staging деплоится из ветки `staging`
- staging используется для интеграционной приёмки после push или merge в `staging`
- production выкатывается отдельно, вручную
- для code/release stream следующий шаг release-flow после `merge` в `main` — ручной production deploy
- production smoke имеет смысл только после фактического production deploy

## Goal

Get a stable HTTPS staging domain for Abrikosoff Connector so Telegram and MAX webhooks can target a permanent URL instead of a temporary tunnel.

## Recommended path

Use Laravel Cloud for staging:

- it is designed for Laravel apps
- it provides managed PostgreSQL
- each environment gets an HTTPS `*.laravel.cloud` domain after the first successful deploy

## Repository

- GitHub repo: `Etogerman/Project-1`
- release branch for staging: `staging`

## Create the staging environment

1. Open Laravel Cloud and create a new application from the GitHub repository.
2. Create an environment named `staging`.
3. Attach a PostgreSQL database to that environment.
4. Bind the application to the `staging` branch.

## Environment variables

Используйте [.env.staging.example](/Users/abrikosov/Documents/Проект-1/.env.staging.example) только как deploy baseline.

Базовые deploy-значения для Laravel Cloud:

- `APP_NAME="Abrikosoff Connector"`
- `APP_ENV=staging`
- `APP_DEBUG=false`
- `APP_FAKER_LOCALE=ru_RU`
- `APP_URL=https://<generated-staging-domain>`
- `APP_KEY=<new random application key>`
- `DB_CONNECTION=pgsql`
- `SESSION_DRIVER=database`
- `CACHE_STORE=database`
- `QUEUE_CONNECTION=database`
- `BITRIX24_FAKE_HAPPY_PATH_ENABLED=false`

Database host, port, database, username, and password should come from the attached managed PostgreSQL service.

`.env.staging.example` фиксирует текущие не-секретные staging defaults для
real Bitrix24 integration flow. Секреты и portal-specific tokens всё равно
нужно задавать вручную в Laravel Cloud и сверять через readiness-check из
[BuildBitrix24SetupReportAction.php](/Users/abrikosov/Documents/Проект-1/app/Services/Bitrix24/BuildBitrix24SetupReportAction.php).

## Обязательные env для real Bitrix24 integration flow

Задайте эти значения в staging environment до того, как считать staging реальной
Bitrix24 integration target:

- `YANDEX_GEOCODER_API_KEY=<real key>`
- `BITRIX24_PORTAL_DOMAIN=stagecrm.fvds.ru`
- `BITRIX24_APP_NAME="Abrikosoff Connector"`
- `BITRIX24_CLIENT_ID=<real client id>`
- `BITRIX24_CLIENT_SECRET=<real client secret>`
- `BITRIX24_APP_CODE=<real Bitrix24 application code>`
- `BITRIX24_AUTH_SERVER_URL=https://oauth.bitrix.info`
- `BITRIX24_INSTALL_CALLBACK_URL=https://<generated-staging-domain>/callbacks/bitrix24/install`
- `BITRIX24_EVENTS_CALLBACK_URL=https://<generated-staging-domain>/callbacks/bitrix24/events`
- `BITRIX24_OPENLINES_CALLBACK_URL=https://<generated-staging-domain>/callbacks/bitrix24/openlines`
- `BITRIX24_CONTACTS_SYNC_ENABLED=true`
- `BITRIX24_DEALS_SYNC_ENABLED=true`
- `BITRIX24_OPENLINES_ENABLED=true`
- `BITRIX24_FAKE_HAPPY_PATH_ENABLED=false`

Bitrix24 profile values are configured in admin after deploy:

- Telegram SOURCE_ID: `ABC_TELEGRAM`
- MAX SOURCE_ID: `ABC_MAX`
- Telegram connector_code: `abc_telegram`
- MAX connector_code: `abc_max`
- Default assigned user ID: `1`
- Default deal category ID: `22`
- Default deal stage ID: `C22:NEW`

LINE_ID открытых линий не задаются в `.env`. Их нужно заполнить в админке на
конкретных маршрутах каналов:

- Telegram route LINE_ID
- MAX route LINE_ID

Перед тем как считать staging ready для real Bitrix24 acceptance, выполните:

```bash
php artisan bitrix24:setup-report
```

Отчёт должен завершиться без missing required values.

## First deploy checklist

After the first successful deploy:

1. copy the generated HTTPS environment domain
2. set `APP_URL` to that exact domain
3. redeploy if needed
4. run database migrations for the staging environment

The application needs the following tables in staging:

- `users`
- `cache`
- `jobs`
- `channels`

## After staging is live

1. Open `/admin/channels` on the staging domain.
2. For each active bot channel, click `Зарегистрировать webhook`.
3. Verify that the webhook is now registered against the staging HTTPS domain.
4. Send a test message to Telegram and MAX bots.

## Solo developer workflow

Если staging реально поднят и участвует в приёмке, solo workflow может быть
таким:

1. Реализовать change-set локально до согласованного локального MVP.
2. Прогнать локальные тесты и нужный локальный smoke перед публикацией.
3. Открыть или обновить `draft PR` в `staging` для уже локально проверенного diff.
4. Дождаться staging auto-deploy от `staging`.
5. Verify all webhook and bot behavior on staging.
6. Отдельно продвинуть тот же validated diff в `main`.
7. Отдельно решать, нужен ли production deploy для этого шага.

This keeps local development fast while avoiding multiple temporary bot setups.
Production smoke не должен использоваться как release confirmation, если
production deploy для нового merge фактически не выполнялся.

Use the staging environment as the main integration environment for:

- Telegram webhook checks
- MAX webhook checks
- token and channel configuration
- admin-panel sanity checks

## Fake Bitrix happy-path for staging

If staging is not connected to a live Bitrix24 portal but you still need to
verify delayed parameter auto replies after qualification, enable:

- `BITRIX24_CONTACTS_SYNC_ENABLED=true`
- `BITRIX24_OPENLINES_ENABLED=true`
- `BITRIX24_FAKE_HAPPY_PATH_ENABLED=true`

This mode is intended only for non-production environments.

What it does:

- fakes successful Bitrix24 contact sync
- fakes successful Open Lines live export
- preserves the existing orchestration order
  `sync -> retry/export -> delayed parameter auto reply`

What it does not prove:

- real OAuth / token refresh
- real Bitrix24 REST compatibility
- real Open Lines transport compatibility

Use it only for staging smoke when the goal is to validate the application
flow end-to-end without a live Bitrix24 connection.

Use local development mainly for:

- coding
- PHPUnit
- quick UI checks that do not depend on real webhooks

Recommended rule of thumb:

- local for implementation and first testable MVP
- staging for confirmatory external and environment-specific checks

If the staging environment is reused continuously, avoid creating extra bots unless the platform or product boundary really requires it.

## Notes

- Do not reuse the temporary Cloudflare tunnel URL as the permanent `APP_URL`.
- A dedicated staging database is safer than pointing staging to the local PostgreSQL instance.
- If you later add uploads or durable bot artifacts, switch those parts from local disk to a managed storage service.
