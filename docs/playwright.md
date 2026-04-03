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
