# Bitrix24 Setup Sheet

Этот файл фиксирует внешний setup-контракт для ТЗ-1. Он не заменяет `config/bitrix24.php`, а служит короткой операционной шпаргалкой перед Этапом 2.

## Frozen constants

| Key | Value |
| --- | --- |
| `portal_domain` | `crm.alexlesley.biz` |
| `default_assigned_user_id` | `1` |
| `default_deal_category_id` | `22` |
| `default_deal_stage_id` | `C22:NEW` |

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

## Required production values

Fill these in `.env` or the deployment environment before starting the integration foundation stage:

| Key | Value |
| --- | --- |
| `BITRIX24_CLIENT_ID` | set in local `.env`, do not commit |
| `BITRIX24_CLIENT_SECRET` | set in local `.env`, do not commit |
| `BITRIX24_INSTALL_CALLBACK_URL` | `https://project-1-staging-r4mo1y.laravel.cloud/callbacks/bitrix24/install` |
| `BITRIX24_EVENTS_CALLBACK_URL` | `https://project-1-staging-r4mo1y.laravel.cloud/callbacks/bitrix24/events` |
| `BITRIX24_OPENLINES_CALLBACK_URL` | `https://project-1-staging-r4mo1y.laravel.cloud/callbacks/bitrix24/openlines` |
| `BITRIX24_TELEGRAM_SOURCE_ID` | `ABRIKOSOFF_TELEGRAM` |
| `BITRIX24_MAX_SOURCE_ID` | `ABRIKOSOFF_MAX` |
| `BITRIX24_TELEGRAM_LINE_ID` | `30` |
| `BITRIX24_MAX_LINE_ID` | `31` |
| `BITRIX24_OPENLINES_SERVICE_USER_ID` | positive Bitrix user id that can post operator replies into the target Open Lines chat |
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
- Telegram and MAX must use different `connector_code` values.
- Telegram and MAX must use different Open Lines.
- `user_id = 1` is frozen as the default assignee for contacts, deals, and manual-review tasks.

## Readiness check

Run:

```bash
php artisan bitrix24:setup-report
```

The command must finish without missing required items before Этап 2 starts.
If `BITRIX24_OPENLINES_ENABLED=true` and `BITRIX24_FAKE_HAPPY_PATH_ENABLED=false`, missing `BITRIX24_OPENLINES_SERVICE_USER_ID` is a blocking setup issue.
