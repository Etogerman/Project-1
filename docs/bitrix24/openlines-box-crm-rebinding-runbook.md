# Bitrix24 Open Lines CRM Rebinding Runbook

## Цель

Этот runbook нужен для поддержки box-side fallback `crm_rebinding`
в коробочном Bitrix24.

Слой `crm_rebinding` не меняет Laravel transport.
Он обеспечивает подтверждённый happy-path:

- existing-contact rebinding для Open Lines Telegram / MAX
- phone-based lookup existing CRM contact
- safe diagnostics для happy-path и ambiguous-path

## Базовые настройки

В box `config.php`:

```php
'crm_rebinding' => [
    'enabled' => true,
    'log_payload' => false,
    'log_file' => '',
],
```

Рабочие line ids:

- Telegram: `32`
- MAX: `31`

Рекомендуемый дефолт:

- `enabled = true` только на тестовой или контролируемой линии
- `log_payload = false` всегда, кроме короткого debug-сеанса
- `log_file = ''`, если устраивает дефолтный fallback-файл пакета

Если на web-runtime коробки не определён глобальный `LOG_FILENAME`,
package пишет логи в:

- `local/php_interface/include/abrikosoff_openlines/runtime.log`

## Что считается нормой

### Happy-path

Ожидаемые события в логе:

- `crm_rebind_attempted`
- `crm_rebind_succeeded`

В UI Bitrix ожидается:

- новая Open Lines сессия появляется у existing CRM contact
- ответ оператора уходит обратно в Telegram / MAX
- лишний лид в happy-path не создаётся

### Unknown-contact path

Ожидаемые события:

- `crm_rebind_attempted`
- `crm_rebind_contact_not_found`

Это нормальный сценарий для нового контакта.
Open Lines transport при этом не должен ломаться.

### Ambiguous-contact path

Ожидаемые события:

- `crm_rebind_attempted`
- `crm_rebind_ambiguous_match`

Это безопасный сценарий.
Package не должен делать auto-attach к случайному контакту.

## Что считать проблемой

Проблемой считать:

- `crm_rebind_transfer_error`
- повторяющиеся `crm_rebind_skipped` при ожидаемом happy-path
- отсутствие любых `crm_rebind_*` событий при включённом `crm_rebinding.enabled`

## Что проверить при проблеме

1. В box `config.php` включён ли:

```php
'crm_rebinding' => [
    'enabled' => true,
]
```

2. Очищен ли кеш Bitrix после изменения package/config.
3. Смотреть ли правильный лог:
   - `LOG_FILENAME`, если он реально используется для web-runtime
   - или `local/php_interface/include/abrikosoff_openlines/runtime.log`
4. Привязан ли правильный connector к правильной линии.
5. Есть ли у существующего CRM contact телефон, по которому должен находиться матч.
6. Нет ли нескольких контактов с одним и тем же телефоном.

## Debug-режим

Если happy-path не работает и нужно больше контекста:

```php
'crm_rebinding' => [
    'enabled' => true,
    'log_payload' => true,
    'log_file' => '',
]
```

После controlled debug-сеанса вернуть обратно:

```php
'crm_rebinding' => [
    'enabled' => true,
    'log_payload' => false,
]
```

## Cleanup checklist

После тестового прогона проверить и привести в порядок:

- line ids соответствуют рабочей конфигурации:
  - Telegram `32`
  - MAX `31`
- тестовые line names `Demo` при необходимости заменить на финальные:
  - `ABR Телеграм бот {имя бота}`
  - `ABR MAX бот {имя бота}`
- удалить или заархивировать тестовые лиды/сущности,
  если они были созданы в ходе smoke-check
- оставить только нужные box config values
- убедиться, что `log_payload` выключен

## Границы текущего слоя

Текущий box-side `crm_rebinding` слой:

- безопасно ищет existing contact по телефону
- подтверждённо работает в existing-contact happy-path для Telegram и MAX
- логирует phone-based candidates с маскировкой
- не выполняет unsafe auto-attach при ambiguous match

Unknown-contact и ambiguous-match сценарии остаются отдельным safety-check.
