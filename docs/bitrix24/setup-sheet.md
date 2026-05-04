# Bitrix24 Setup Sheet

Этот документ — frozen ops/setup шпаргалка по подтверждённым значениям и
проверкам Bitrix24 integration-контура.

Он:

1. не заменяет `config/bitrix24.php` и runtime-код;
2. не является полным ТЗ или status-doc по stream-ам;
3. используется как короткий reference перед staging/integration проверками и
   ручными setup-операциями.

## Production handoff values

| Key | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_FAKER_LOCALE` | `ru_RU` |
| `BITRIX24_PORTAL_DOMAIN` | `crm.alexlesley.biz` |
| `BITRIX24_APP_NAME` | `Abrikosoff Connector` |
| `APP_URL` | `https://project2.abrikosoff.ru` |
| `BITRIX24_INSTALL_CALLBACK_URL` | `https://project2.abrikosoff.ru/callbacks/bitrix24/install` |
| `BITRIX24_EVENTS_CALLBACK_URL` | `https://project2.abrikosoff.ru/callbacks/bitrix24/events` |
| `BITRIX24_OPENLINES_CALLBACK_URL` | `https://project2.abrikosoff.ru/callbacks/bitrix24/openlines` |
| `BITRIX24_OPENLINES_RUNTIME_APPLICATION_TOKEN_HASH` | required, from current Bitrix box `application_token` |
| `BITRIX24_OPENLINES_RUNTIME_APPLICATION_TOKEN_HASHES` | optional multi-token replacement for the single hash |
| `BITRIX24_TELEGRAM_LINE_ID` | `32` |
| `BITRIX24_MAX_LINE_ID` | `31` |
| `BITRIX24_TELEGRAM_CONNECTOR_CODE` | `abrikosoff_telegram` |
| `BITRIX24_MAX_CONNECTOR_CODE` | `abrikosoff_max` |
| `default_assigned_user_id` | `1` |
| `default_deal_category_id` | `22` |
| `default_deal_stage_id` | `C22:NEW` |

`owner_profile_key = staging` в box package остаётся текущим Laravel
`Bitrix24Profile.profile_key`; production определяется portal domain и callback
base URL, а не этим именем profile.

Перед production deploy эти значения нужно сверить в Laravel Cloud Production
и в коробочном `config.php`; одного наличия строк в репозитории недостаточно.

## Existing Bitrix24 contact fields

| Purpose | Field code |
| --- | --- |
| Name source | `UF_CRM_64D7457E4DC07` |
| Exact age | `UF_CRM_1606901533` |
| Gender | `UF_CRM_5EEB7355C13B1` |

## Required Abrikosoff contact fields

| Purpose | Recommended field code |
| --- | --- |
| Age range | `UF_CRM_ABRIKOSOFF_AGE_RANGE` |
| Local contact ID | `UF_CRM_ABRIKOSOFF_CONTACT_ID` |
| Channel ID | `UF_CRM_ABRIKOSOFF_CHANNEL_ID` |
| Channel name | `UF_CRM_ABRIKOSOFF_CHANNEL_NAME` |
| Platform | `UF_CRM_ABRIKOSOFF_PLATFORM` |
| Bot code | `UF_CRM_ABRIKOSOFF_BOT_CODE` |
| Bot name | `UF_CRM_ABRIKOSOFF_BOT_NAME` |
| Alternate first name | `UF_CRM_ABRIKOSOFF_ALT_FIRST_NAME` |
| Alternate last name | `UF_CRM_ABRIKOSOFF_ALT_LAST_NAME` |
| Name conflict flag | `UF_CRM_ABRIKOSOFF_NAME_CONFLICT` |

## Staging integration values

Заполните их в `.env` или deployment environment перед использованием этого
setup-runbook на staging или другом real integration target:

| Key | Value |
| --- | --- |
| `APP_ENV` | `staging` |
| `APP_DEBUG` | `false` |
| `APP_FAKER_LOCALE` | `ru_RU` |
| `BITRIX24_PORTAL_DOMAIN` | `stagecrm.fvds.ru` |
| `BITRIX24_APP_NAME` | `Abrikosoff Connector` |
| `BITRIX24_CLIENT_ID` | set in local `.env`, do not commit |
| `BITRIX24_CLIENT_SECRET` | set in local `.env`, do not commit |
| `BITRIX24_APP_CODE` | set in local `.env`, do not commit |
| `BITRIX24_AUTH_SERVER_URL` | `https://oauth.bitrix.info` |
| `BITRIX24_INSTALL_CALLBACK_URL` | `https://project-1-staging-r4mo1y.laravel.cloud/callbacks/bitrix24/install` |
| `BITRIX24_EVENTS_CALLBACK_URL` | `https://project-1-staging-r4mo1y.laravel.cloud/callbacks/bitrix24/events` |
| `BITRIX24_OPENLINES_CALLBACK_URL` | `https://project-1-staging-r4mo1y.laravel.cloud/callbacks/bitrix24/openlines` |
| `BITRIX24_TELEGRAM_SOURCE_ID` | `ABRIKOSOFF_TELEGRAM` |
| `BITRIX24_MAX_SOURCE_ID` | `ABRIKOSOFF_MAX` |
| `BITRIX24_TELEGRAM_LINE_ID` | `30` |
| `BITRIX24_MAX_LINE_ID` | `31` |
| `BITRIX24_TELEGRAM_CONNECTOR_CODE` | `abrikosoff_telegram` |
| `BITRIX24_MAX_CONNECTOR_CODE` | `abrikosoff_max` |

## Important rules

- Do not use the discovery probe route as a production callback.
- Production callback paths are fixed:
  - `/callbacks/bitrix24/install`
  - `/callbacks/bitrix24/events`
  - `/callbacks/bitrix24/openlines`
- The current stable staging host is `project-1-staging-r4mo1y.laravel.cloud`.
- Do not reuse the temporary `Abrikosoff Probe` source as a production `SOURCE_ID`.
- Do not use obsolete `fake-*` connector placeholders for real staging or
  production Open Lines. Current connector codes are `abrikosoff_telegram` and
  `abrikosoff_max`.
- Telegram and MAX must use different `connector_code` values.
- Telegram and MAX must use different Open Lines.
- Текущий подтверждённый Open Lines mapping фиксирован: Telegram `30`, MAX `31`.
- `user_id = 1` is frozen as the default assignee for contacts, deals, and manual-review tasks.

## Readiness check

Run:

```bash
php artisan bitrix24:setup-report
```

The command must finish without missing required items before runtime acceptance
на выбранном integration environment.
