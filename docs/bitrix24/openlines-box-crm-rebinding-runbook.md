# Bitrix24 Open Lines CRM Rebinding Runbook

## Цель

Этот runbook нужен для поддержки box-side fallback `crm_rebinding`
в коробочном Bitrix24.

Слой `crm_rebinding` не меняет Laravel transport.
Он делает только:

- inspection `imopenlines` session lifecycle
- phone-based lookup existing CRM contact
- safe diagnostics для happy-path и ambiguous-path

## Базовые настройки

В box `config.php`:

```php
'crm_rebinding' => [
    'enabled' => true,
    'log_payload' => false,
],
```

Рекомендуемый дефолт:

- `enabled = true` только на тестовой или контролируемой линии
- `log_payload = false` всегда, кроме короткого debug-сеанса

## Что считается нормой

### Happy-path

Ожидаемые события в логе:

- `crm_rebind_attempted`
- `crm_rebind_contact_found`

Если attach/reconcile на стороне коробки ещё не реализован полностью,
`crm_rebind_contact_found` всё равно полезен: он доказывает,
что existing CRM contact по телефону найден box-side слоем.

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
3. Привязан ли правильный connector к правильной линии.
4. Есть ли у существующего CRM contact телефон, по которому должен находиться матч.
5. Нет ли нескольких контактов с одним и тем же телефоном.

## Debug-режим

Если happy-path не работает и нужно больше контекста:

```php
'crm_rebinding' => [
    'enabled' => true,
    'log_payload' => true,
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

- тестовые line names `Demo` заменить на финальные:
  - `ABR Телеграм бот {имя бота}`
  - `ABR MAX бот {имя бота}`
- удалить или заархивировать тестовые лиды/сущности,
  если они были созданы в ходе smoke-check
- оставить только нужные box config values
- убедиться, что `log_payload` выключен

## Границы текущего слоя

Текущий box-side `crm_rebinding` слой:

- безопасно ищет existing contact по телефону
- логирует phone-based candidates с маскировкой
- не выполняет unsafe auto-attach при ambiguous match

Этот слой сам по себе не гарантирует production-ready CRM attach,
если коробка Bitrix требует отдельный internal API path для session rebinding.
