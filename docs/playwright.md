# Playwright smoke tests

В проекте добавлен второй слой автопроверок поверх Laravel feature tests:

- `php artisan test` проверяет серверную логику и правила доступа.
- `Playwright` проверяет живую админку в реальном браузере.

## Что проверяется

- гость с `/` попадает на `/admin/login`
- страница входа Filament рендерится корректно
- администратор может войти
- администратор может открыть ресурс `Users`

Тесты намеренно не меняют продовые данные.

## Установка браузера

```bash
npx playwright install chromium
```

## Локальный запуск

Сначала подними приложение:

```bash
php artisan serve
```

Потом запусти smoke-тесты:

```bash
export PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000
export PLAYWRIGHT_ADMIN_EMAIL=admin@abrikosoff.local
export PLAYWRIGHT_ADMIN_PASSWORD=admin12345
npx playwright test
```

## Прогон по production

```bash
export PLAYWRIGHT_BASE_URL=https://project2.abrikosoff.ru
export PLAYWRIGHT_ADMIN_EMAIL=your-admin@example.com
export PLAYWRIGHT_ADMIN_PASSWORD=your-password
npx playwright test
```

## Полезные команды

```bash
npm run test:e2e
npm run test:e2e:headed
npm run test:e2e:report
```

## GitHub Actions post-deploy smoke

В репозитории есть отдельный workflow:

- `.github/workflows/post-deploy-smoke.yml`

Он запускается:

- после каждого `push` в `main`
- вручную через `workflow_dispatch`

Workflow сейчас автоматически проверяет:

- `production`

Сейчас workflow intentionally проверяет только `production`.
`staging` будет добавлен обратно отдельным шагом, когда появится
рабочее Bitrix24 staging-окружение.

Для GitHub Environment `production` нужно настроить secrets:

- `PLAYWRIGHT_BASE_URL`
- `PLAYWRIGHT_ADMIN_EMAIL`
- `PLAYWRIGHT_ADMIN_PASSWORD`

Smoke идёт по живому URL:

- ждёт доступности `/admin/login`
- запускает `public.smoke.spec.ts`
- запускает `admin.smoke.spec.ts`
- сохраняет Playwright artifacts

Важно:

- workflow должен отражать реальный release process, а не идеальную будущую схему
- пока staging реально не участвует в приёмке релиза, он не должен оставаться
  формально включённым в автоматический smoke
