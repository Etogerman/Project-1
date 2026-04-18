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

Use values from [.env.staging.example](/Users/abrikosov/Documents/Проект-1/.env.staging.example) as the baseline.

Required values to set in Laravel Cloud:

- `APP_NAME="Abrikosoff Connector"`
- `APP_ENV=staging`
- `APP_DEBUG=false`
- `APP_URL=https://<generated-staging-domain>`
- `APP_KEY=<new random application key>`
- `DB_CONNECTION=pgsql`
- `SESSION_DRIVER=database`
- `CACHE_STORE=database`
- `QUEUE_CONNECTION=database`
- `BITRIX24_FAKE_HAPPY_PATH_ENABLED=false`

Database host, port, database, username, and password should come from the attached managed PostgreSQL service.

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

1. Write code locally.
2. Run PHPUnit locally before pushing.
3. Push or merge the validated change-set into `staging`.
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

- local for implementation
- staging for anything external

If the staging environment is reused continuously, avoid creating extra bots unless the platform or product boundary really requires it.

## Notes

- Do not reuse the temporary Cloudflare tunnel URL as the permanent `APP_URL`.
- A dedicated staging database is safer than pointing staging to the local PostgreSQL instance.
- If you later add uploads or durable bot artifacts, switch those parts from local disk to a managed storage service.
