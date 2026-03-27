# Staging on Laravel Cloud

## Goal

Get a stable HTTPS staging domain for Abrikosoff Connector so Telegram and MAX webhooks can target a permanent URL instead of a temporary tunnel.

## Recommended path

Use Laravel Cloud for staging:

- it is designed for Laravel apps
- it provides managed PostgreSQL
- each environment gets an HTTPS `*.laravel.cloud` domain after the first successful deploy

## Repository

- GitHub repo: `Etogerman/Project-1`
- current branch: `main`

## Create the staging environment

1. Open Laravel Cloud and create a new application from the GitHub repository.
2. Create an environment named `staging`.
3. Attach a PostgreSQL database to that environment.
4. Deploy the `main` branch first, or create a dedicated `staging` branch if you want isolated releases.

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

## Notes

- Do not reuse the temporary Cloudflare tunnel URL as the permanent `APP_URL`.
- A dedicated staging database is safer than pointing staging to the local PostgreSQL instance.
- If you later add uploads or durable bot artifacts, switch those parts from local disk to a managed storage service.
