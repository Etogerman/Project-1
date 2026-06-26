# Окружения и важные адреса

Этот файл — короткий индекс несекретных адресов AB Connector.

Перед действием со staging, production, Bitrix24, Open Lines, webhook или
callback агент сверяет актуальность адреса в живой панели и профильной
инструкции.

## Безопасность

В этом файле фиксируются публичные адреса приложений, домены порталов,
callback paths и ссылки на панели управления.

Секреты, токены, пароли, `client_secret`, `application_code`, API-ключи,
registry secrets и значения, скрытые в Laravel Cloud как secret, остаются в
защищённых runtime-настройках.

## Быстрый индекс

| Контур | Адрес / значение | Где сверять актуальность |
| --- | --- | --- |
| Production app | `https://project2.abrikosoff.ru` | Laravel Cloud production, `docs/post-deploy-smoke.md` |
| Staging app | `https://project-1-staging-r4mo1y.laravel.cloud` | Laravel Cloud staging, `docs/staging-laravel-cloud.md` |
| Bitrix24 production portal | `crm.alexlesley.biz` | Bitrix24 production admin, `docs/bitrix24/setup-sheet.md` |
| Bitrix24 staging portal | `stagecrm.fvds.ru` | Bitrix24 staging admin, `docs/bitrix24/setup-sheet.md` |
| Local app | `http://127.0.0.1:8000` | `docs/reference/local-bootstrap.md` |
| Локальный tunnel, если активен | `https://abr-8000-local.abrikosov.biz` | текущий локальный runtime, `docs/bitrix24/dev-local-setup.md` |
| Локальная admin через tunnel | `https://abr-8000-local.abrikosov.biz/admin` | текущий локальный runtime, `docs/bitrix24/dev-local-setup.md` |

## Панели управления

| Панель | Адрес |
| --- | --- |
| Laravel Cloud production | `https://cloud.laravel.com/german-abrikosov/project-1/main` |
| Laravel Cloud staging | `https://cloud.laravel.com/german-abrikosov/project-1/staging` |
| Bitrix24 production admin | `https://crm.alexlesley.biz` |
| Bitrix24 staging admin | `https://stagecrm.fvds.ru` |

## Callback paths

Стандартные Bitrix24 callback paths:

| Path | Назначение |
| --- | --- |
| `/callbacks/bitrix24/install` | Установка приложения Bitrix24 |
| `/callbacks/bitrix24/events` | События Bitrix24 |
| `/callbacks/bitrix24/openlines` | События Open Lines |

Полный callback URL собирается от `APP_URL` выбранного контура:

```text
<APP_URL>/callbacks/bitrix24/install
<APP_URL>/callbacks/bitrix24/events
<APP_URL>/callbacks/bitrix24/openlines
```

## Что открыть перед действием

| Действие | Документы |
| --- | --- |
| Staging deploy, smoke или проверка | `docs/staging-laravel-cloud.md`, `docs/post-deploy-smoke.md`, `docs/bitrix24/setup-sheet.md` |
| Production deploy или smoke | `docs/post-deploy-smoke.md`, `docs/bitrix24/setup-sheet.md`, профильная инструкция затронутой интеграции |
| Bitrix24 или Open Lines | `docs/bitrix24/setup-sheet.md`, `docs/bitrix24/openlines-channel-runbook.md` |
| Локальная проверка с Bitrix24 | `docs/reference/local-bootstrap.md`, `docs/bitrix24/dev-local-setup.md` |

## Проверка перед действием

Перед действием агент определяет:

1. рабочий контур и ожидаемый адрес;
2. где живут актуальные секреты и переменные;
3. какая профильная инструкция задаёт порядок проверки.
