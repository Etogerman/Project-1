# Окружения и важные адреса

Этот файл — короткий индекс несекретных адресов и рабочих контуров AB Connector.

Файл рассчитан на внутренний проектный контур. При переносе выдержек во внешний
или публичный контур адреса нужно предварительно проверить и при необходимости
отредактировать.

Живые переменные окружения и секреты проверяются в соответствующем runtime-контуре:

1. Laravel Cloud — переменные приложения и секреты.
2. Bitrix24 — настройки приложения, портала, открытых линий и callback.
3. Локальный `.env` — локальный контур разработчика.

## Правило безопасности

В этом файле фиксируются только несекретные значения:

- публичные адреса приложений;
- домены порталов;
- callback paths;
- ссылки на панели управления;
- ссылки на профильные runbook-и.

Секреты, токены, пароли, `client_secret`, `application_code`, API-ключи,
registry secrets и значения, скрытые в Laravel Cloud как secret, остаются только
в защищённых runtime-настройках.

## Быстрый индекс

| Контур | Адрес / значение | Назначение | Подробности |
| --- | --- | --- | --- |
| Production app | `https://project2.abrikosoff.ru` | Боевой AB Connector | `docs/post-deploy-smoke.md`, `docs/bitrix24/setup-sheet.md` |
| Staging app | `https://project-1-staging-r4mo1y.laravel.cloud` | Общий staging AB Connector | `docs/staging-laravel-cloud.md` |
| Bitrix24 production portal | `crm.alexlesley.biz` | Боевой Bitrix24 | `docs/bitrix24/setup-sheet.md` |
| Bitrix24 staging portal | `stagecrm.fvds.ru` | Тестовый Bitrix24 | `docs/bitrix24/setup-sheet.md`, `docs/bitrix24/dev-local-setup.md` |
| Local app | `http://127.0.0.1:8000` | Локальная разработка без внешнего tunnel | `docs/reference/local-bootstrap.md` |
| Постоянный локальный tunnel | `https://abr-8000-local.abrikosov.biz` | Внешний вход в локальный контур | `docs/bitrix24/dev-local-setup.md` |
| Постоянный локальный admin | `https://abr-8000-local.abrikosov.biz/admin` | Админка локального контура через tunnel | `docs/bitrix24/dev-local-setup.md` |

## Панели управления

| Панель | Адрес | Назначение |
| --- | --- | --- |
| Laravel Cloud production | `https://cloud.laravel.com/german-abrikosov/project-1/main` | Production environment приложения |
| Laravel Cloud staging | `https://cloud.laravel.com/german-abrikosov/project-1/staging` | Staging environment приложения |
| Bitrix24 production admin | `https://crm.alexlesley.biz` | Production портал Bitrix24 |
| Bitrix24 staging admin | `https://stagecrm.fvds.ru` | Staging портал Bitrix24 |
| GitHub tracker окружений | `pending` | Текущее рабочее состояние окружений |

`pending` означает, что отдельная GitHub-задача или GitHub-доска для текущего
состояния окружений ещё не зафиксирована. До её появления актуальное состояние
проверяется по Laravel Cloud, Bitrix24 и профильным runbook-ам.

## Проверка актуальности

Перед действием, которое зависит от конкретного адреса, агент сверяет:

1. production и staging app — с Laravel Cloud;
2. Bitrix24 portal domain — с Bitrix24 admin и `docs/bitrix24/setup-sheet.md`;
3. локальный tunnel — с текущей локальной конфигурацией и доступностью URL;
4. callback URL — с настройками соответствующего Bitrix24 app.

## Bitrix24 callback paths

Стандартные Bitrix24 callback paths AB Connector:

| Path | Назначение |
| --- | --- |
| `/callbacks/bitrix24/install` | Установка приложения Bitrix24 |
| `/callbacks/bitrix24/events` | События Bitrix24 |
| `/callbacks/bitrix24/openlines` | События Open Lines |

Полные callback URL собираются от базового адреса приложения:

```text
<APP_URL>/callbacks/bitrix24/install
<APP_URL>/callbacks/bitrix24/events
<APP_URL>/callbacks/bitrix24/openlines
```

Пример для постоянного локального tunnel:

```text
https://abr-8000-local.abrikosov.biz/callbacks/bitrix24/install
https://abr-8000-local.abrikosov.biz/callbacks/bitrix24/events
https://abr-8000-local.abrikosov.biz/callbacks/bitrix24/openlines
```

## Staging

Текущий стабильный staging host:

```text
project-1-staging-r4mo1y.laravel.cloud
```

Перед проверкой staging открыть:

1. `docs/staging-laravel-cloud.md`
2. `docs/bitrix24/setup-sheet.md`
3. `docs/post-deploy-smoke.md`
4. `docs/task-delivery-workflow.md`

## Production

Текущий production app:

```text
https://project2.abrikosoff.ru
```

Перед production deploy или production smoke открыть:

1. `docs/post-deploy-smoke.md`
2. `docs/bitrix24/setup-sheet.md`
3. профильный runbook затронутой интеграции
4. `docs/task-delivery-workflow.md`

## Bitrix24

Текущие порталы:

| Контур | Portal domain |
| --- | --- |
| Production | `crm.alexlesley.biz` |
| Staging | `stagecrm.fvds.ru` |

Перед изменениями Bitrix24/Open Lines открыть:

1. `docs/bitrix24/setup-sheet.md`
2. `docs/bitrix24/dev-local-setup.md`, если используется локальный dev-профиль
3. `docs/bitrix24/openlines-channel-runbook.md`, если меняются Open Lines routes

## Проверка перед действием

Перед действиями со staging, production, Bitrix24, Open Lines, webhook или
callback агент проверяет маршрут работы:

1. контур и ожидаемый адрес;
2. живые секреты и переменные в runtime-настройках;
3. профильный runbook для действия.

## Связанные документы

- `docs/reference/local-bootstrap.md`
- `docs/staging-laravel-cloud.md`
- `docs/post-deploy-smoke.md`
- `docs/bitrix24/setup-sheet.md`
- `docs/bitrix24/dev-local-setup.md`
- `docs/bitrix24/openlines-channel-runbook.md`
- `.env.staging.example`
