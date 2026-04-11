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

Сначала создай локального администратора с явным паролем:

```bash
export ADMIN_USER_SEEDER_PASSWORD='replace-with-local-secret'
php artisan db:seed --class=AdminUserSeeder
```

`AdminUserSeeder` больше не вызывается через дефолтный `php artisan db:seed`,
и пароль администратора не хранится в репозитории.

Потом подними приложение:

```bash
php artisan serve
```

Потом запусти smoke-тесты:

```bash
export PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000
export PLAYWRIGHT_ADMIN_EMAIL=admin@abrikosoff.local
export PLAYWRIGHT_ADMIN_PASSWORD="$ADMIN_USER_SEEDER_PASSWORD"
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

В репозитории теперь два workflow:

- `.github/workflows/post-deploy-smoke.yml`
- `.github/workflows/production-post-deploy-smoke.yml`

Staging smoke запускается:

- после каждого `push` в `staging`
- вручную через `workflow_dispatch`

Production smoke запускается:

- только вручную через `workflow_dispatch`
- только после реального production deploy

Для каждого environment нужны свои secrets:

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
- автоматический smoke должен смотреть туда, куда реально ушёл новый merge
- production smoke имеет смысл только после фактического production deploy
