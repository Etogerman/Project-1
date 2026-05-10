# Bitrix24 Setup Sheet

Этот документ — frozen ops/setup шпаргалка по подтверждённым значениям и
проверкам Bitrix24 integration-контура.

Он:

1. не заменяет `config/bitrix24.php` и runtime-код;
2. не является полным ТЗ или status-doc по stream-ам;
3. используется как короткий reference перед staging/integration проверками и
   ручными setup-операциями.

Документ рассчитан на приватный ops-контекст. Если выдержки уходят во внешний
или публичный контур, реальные production/staging URL нужно предварительно
редактировать.

## Production handoff values

| Key | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_FAKER_LOCALE` | `ru_RU` |
| `BITRIX24_PORTAL_DOMAIN` | `crm.alexlesley.biz` |
| `BITRIX24_APP_NAME` | `Abrikosoff Connector` |
| `BITRIX24_CLIENT_ID` | required, from production Bitrix24 app |
| `BITRIX24_CLIENT_SECRET` | required, from production Bitrix24 app |
| `BITRIX24_APP_CODE` | required, from production Bitrix24 app |
| `BITRIX24_AUTH_SERVER_URL` | `https://oauth.bitrix.info` |
| `APP_URL` | `https://project2.abrikosoff.ru` |
| `BITRIX24_INSTALL_CALLBACK_URL` | `https://project2.abrikosoff.ru/callbacks/bitrix24/install` |
| `BITRIX24_EVENTS_CALLBACK_URL` | `https://project2.abrikosoff.ru/callbacks/bitrix24/events` |
| `BITRIX24_OPENLINES_CALLBACK_URL` | `https://project2.abrikosoff.ru/callbacks/bitrix24/openlines` |
| `BITRIX24_OPENLINES_RUNTIME_APPLICATION_TOKEN_HASH` | required, from current Bitrix box `application_token` |
| `BITRIX24_OPENLINES_RUNTIME_APPLICATION_TOKEN_HASHES` | optional multi-token replacement for the single hash |
| `BITRIX24_FAKE_HAPPY_PATH_ENABLED` | `false` |
| `BITRIX24_TIMELINE_HISTORY_IMPORT_ENABLED` | `false` |
| `BITRIX24_REVERSE_SYNC_ENABLED` | `false` |
| `BITRIX24_DUPLICATE_PHONE_DIAGNOSTIC_ENABLED` | `false` |
| `BITRIX24_DUPLICATE_PHONE_DIAGNOSTIC_DELAY_SECONDS` | `90` |
| Telegram LINE_ID | stored in the active Telegram channel route in admin |
| MAX LINE_ID | stored in the active MAX channel route in admin |
| Telegram SOURCE_ID | stored in Bitrix24 profile settings in admin; `BITRIX24_TELEGRAM_SOURCE_ID` is a temporary fallback |
| MAX SOURCE_ID | stored in Bitrix24 profile settings in admin; `BITRIX24_MAX_SOURCE_ID` is a temporary fallback |
| Telegram connector_code | stored in Bitrix24 profile settings in admin; route rows copy it per channel |
| MAX connector_code | stored in Bitrix24 profile settings in admin; route rows copy it per channel |
| Default assigned user ID | stored in Bitrix24 profile settings in admin; `BITRIX24_DEFAULT_ASSIGNED_USER_ID` is a temporary fallback |
| Default deal category ID | stored in Bitrix24 profile settings in admin; `BITRIX24_DEFAULT_DEAL_CATEGORY_ID` is a temporary fallback |
| Default deal stage ID | stored in Bitrix24 profile settings in admin; `BITRIX24_DEFAULT_DEAL_STAGE_ID` is a temporary fallback |
| Name source automatic ID | stored in Bitrix24 profile settings in admin; `BITRIX24_NAME_SOURCE_AUTOMATIC_ID` is a temporary fallback |
| Name source self reported ID | stored in Bitrix24 profile settings in admin; `BITRIX24_NAME_SOURCE_SELF_REPORTED_ID` is a temporary fallback |
| Name source training verified ID | stored in Bitrix24 profile settings in admin; `BITRIX24_NAME_SOURCE_TRAINING_VERIFIED_ID` is a temporary fallback |
| Gender male ID | stored in Bitrix24 profile settings in admin; `BITRIX24_GENDER_MALE_ID` is a temporary fallback |
| Gender female ID | stored in Bitrix24 profile settings in admin; `BITRIX24_GENDER_FEMALE_ID` is a temporary fallback |
| Gender unknown ID | stored in Bitrix24 profile settings in admin; `BITRIX24_GENDER_UNKNOWN_ID` is a temporary fallback |

`owner_profile_key = staging` в box package остаётся текущим Laravel
`Bitrix24Profile.profile_key`; production определяется portal domain и callback
base URL, а не этим именем profile.

Перед production deploy эти значения нужно сверить в Laravel Cloud Production
и в коробочном `config.php`; одного наличия строк в репозитории недостаточно.

## Bitrix24 contact field-code settings

Эти значения хранятся в настройках профиля Bitrix24 в админке. Переменные
`BITRIX24_FIELD_*` остаются только временным fallback-ом для старых окружений.

### Existing Bitrix24 contact fields

| Purpose | Field code |
| --- | --- |
| Name source | `UF_CRM_64D7457E4DC07` |
| Exact age | `UF_CRM_1606901533` |
| Gender | `UF_CRM_5EEB7355C13B1` |

### Required contact fields

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
| Telegram SOURCE_ID | `ABC_TELEGRAM`, stored in Bitrix24 profile settings in admin |
| MAX SOURCE_ID | `ABC_MAX`, stored in Bitrix24 profile settings in admin |
| Telegram LINE_ID | stored in the active Telegram channel route in admin |
| MAX LINE_ID | stored in the active MAX channel route in admin |
| Telegram connector_code | `abrikosoff_telegram`, stored in Bitrix24 profile settings in admin |
| MAX connector_code | `abrikosoff_max`, stored in Bitrix24 profile settings in admin |
| `BITRIX24_TIMELINE_HISTORY_IMPORT_ENABLED` | `false` |
| `BITRIX24_REVERSE_SYNC_ENABLED` | `false` |
| `BITRIX24_DUPLICATE_PHONE_DIAGNOSTIC_ENABLED` | `false` |
| `BITRIX24_DUPLICATE_PHONE_DIAGNOSTIC_DELAY_SECONDS` | `90` |

## OpenLines route registry staging gate

Дата фиксации: `2026-05-11`.

Текущее рабочее состояние staging для OpenLines route registry:

- Laravel UI publish/doctor настроены.
- Bitrix-side registry secret хранится только в Bitrix local config и encrypted
  поле Laravel-профиля; secret не фиксируется в документации.
- Registry snapshot опубликован для официальных staging routes.
- Bitrix runtime registry на staging включён:
  `route_registry.enabled=true` в box-side
  `/home/bitrix/www/local/php_interface/include/abrikosoff_openlines/config.php`.
- Официальные staging-линии:
  - Telegram: `abrikosoff_telegram:2`
  - MAX: `abrikosoff_max:3`
- Smoke после включения registry пройден по линиям `2` и `3`: сообщения из
  Bitrix доставляются в Laravel и дальше клиенту.

Быстрая диагностика, если сообщения из Bitrix снова не уходят:

1. Проверить Bitrix runtime log:

   ```text
   /home/bitrix/www/local/php_interface/include/abrikosoff_openlines/runtime.log
   ```

2. Если есть `duplicate_line_id_ignored`, проверить, что для официальных
   staging-линий нет двух connector code:

   ```text
   line_id=2 -> только abrikosoff_telegram
   line_id=3 -> только abrikosoff_max
   ```

3. Проверить, что `route_registry.enabled=true` и snapshot содержит только
   ожидаемые active routes для официального staging.

4. После исправления отправить тестовые сообщения из Bitrix по линиям `2` и
   `3` и подтвердить доставку в Laravel admin/logs.

## Important rules

- Do not use the discovery probe route as a production callback.
- Production callback paths are fixed:
  - `/callbacks/bitrix24/install`
  - `/callbacks/bitrix24/events`
  - `/callbacks/bitrix24/openlines`
- The current stable staging host is `project-1-staging-r4mo1y.laravel.cloud`.
- Do not reuse the temporary `Abrikosoff Probe` source as a production `SOURCE_ID`.
- Do not use obsolete `fake-*` connector placeholders for real staging or
  production Open Lines. Current official staging connector codes are
  `abrikosoff_telegram` and `abrikosoff_max`; dev profile variants may still
  use the `abc_*` prefix until the legacy transition cleanup is complete.
- Telegram and MAX must use different `connector_code` values.
- Telegram and MAX must use different Open Lines.
- Open Lines LINE_ID values are configured per concrete channel route in admin, not in `.env`.
- `user_id = 1` is frozen as the default assignee for contacts, deals, and manual-review tasks.

## Readiness check

Run:

```bash
php artisan bitrix24:setup-report
```

The command must finish without missing required items before runtime acceptance
на выбранном integration environment.
