# ТЗ: Slice 3 — UI, badges, timeline и cleanup legacy name

## Контекст

`Slices 1-2` уже в `main`:
- `first_name_source` существует и используется
- `contact_identities.display_name` существует и обновляется в runtime
- dialog cards и dialog page уже показывают канальный label из identity
- inbox search уже умеет искать по `currentContactIdentity.display_name`
- `ApplyContactFirstNameAction` — единая точка записи `first_name`
- `ResolveContactDisplayNameAction` — единая точка чтения global display name

Этот документ описывает только незакрытый `Slice 3`.
Миграции, backfill, inbound chronology и repair-step сюда не входят.

## Цель шага

Дожать оставшиеся contact-level и operator-UI хвосты, чтобы:
- на contact page больше не было отдельного поля `Имя (мессенджер)`
- header contact page не читал `contacts.name` напрямую
- оператор видел источник текущего `first_name`
- timeline показывал события смены имени и merge-конфликта имени
- dialog inbox search не зависел от legacy `contacts.name`
- старый docs-контракт не противоречил уже реализованным `Slices 1-2`

## Scope

### Что меняется

#### 1. Contact page

Файл: [ViewContact.php](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Contacts/Pages/ViewContact.php)

Якоря текущего head:
- строка `Имя (мессенджер)` около [ViewContact.php#L294](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Contacts/Pages/ViewContact.php#L294)
- fallback на `$record->name` в `resolveHeadingLabel()` около [ViewContact.php#L561](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Contacts/Pages/ViewContact.php#L561)

Изменения:
- удалить строку `Имя (мессенджер)` из contact-level блока `Данные клиента`
- удалить прямой fallback на `$record->name` из `resolveHeadingLabel()`
- сохранить текущий порядок header `Фамилия Имя`, если заполнены profile-поля
- если `first_name` и `last_name` пусты, использовать `$record->display_name`
- если accessor вернул `Контакт #id`, оставить это значение как last resort

#### 2. Source badge на contact page

Файл: [ViewContact.php](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Contacts/Pages/ViewContact.php)

Рядом с полем `Имя` показывать badge источника:

| source | label | tone |
|---|---|---|
| `null` | не показывать | — |
| `auto` | `Авто` | `gray` |
| `contact_confirmed` | `Клиент назвал` | `info` |
| `manual` | `Оператор` | `success` |

Правила:
- badge не показывается, если `first_name` пустой
- использовать константы модели `Contact`
- mapping `source -> label/tone` должен быть единым для contact page,
  contact table и timeline

#### 3. Source indicator в таблице контактов

Файл: [ContactResource.php#L339](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Contacts/ContactResource.php#L339)

Колонка `display_name` уже использует правильный read-path.
Нужно добавить компактный индикатор source рядом с именем.

Допустимые варианты UI:
- compact pill
- или icon + tooltip

Но значения должны остаться теми же:
- `Авто`
- `Клиент назвал`
- `Оператор`

#### 4. Timeline событий имени

Файл: [BuildContactHistoryTimelineAction.php#L115](/Users/abrikosov/Documents/Проект-1/app/Services/Contacts/BuildContactHistoryTimelineAction.php#L115)

Сейчас фильтр пропускает только `EVENT_OPERATOR_COMMENT`.
Нужно пропускать и рендерить:
- `EVENT_FIRST_NAME_CHANGED`
- `EVENT_MERGE_NAME_CONFLICT`

Шаблоны:

`contact.first_name_changed`, если `new_value` не пуст:
- title: `Имя изменено`
- description: `«{previous_value_or_—}» → «{new_value}»`
- body: `Источник: {new_source_label}`

`contact.first_name_changed`, если `new_value` пуст:
- title: `Имя очищено`
- description: `Было: «{previous_value_or_—}»`
- body: `Источник: {previous_source_label}`

`contact.merge_name_conflict`:
- title: `Конфликт имени при объединении`
- description: `При объединении с контактом #{merged_contact_id} найдено другое имя: «{merged_first_name}»`
- body: `Источник: {merged_first_name_source_label}`

Поля payload:
- `first_name_changed`:
  - `previous_value`
  - `new_value`
  - `previous_source`
  - `new_source`
  - `reason`
- `merge_name_conflict`:
  - `merged_contact_id`
  - `merged_first_name`
  - `merged_first_name_source`

Source labels для timeline:
- `auto` → `Авто (из мессенджера)`
- `contact_confirmed` → `Клиент назвал`
- `manual` → `Оператор`

#### 5. Dialog inbox search без `contacts.name`

Файл: [DialogResource.php#L438](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Dialogs/DialogResource.php#L438)

Убрать `contact.name` из operator search path.

После изменения в dialog inbox search остаются:
- `contacts.first_name`
- `contacts.last_name`
- телефоны
- `currentContactIdentity.display_name`
- `currentContactIdentity.external_user_id`
- `currentContactIdentity.external_username`
- `external_chat_id`

Причина:
- для operator UX legacy `contacts.name` больше не должен быть active
  search source

#### 6. Синхронизация документации

Файл: [tz-new-contact-card.md](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md)

Обновить устаревшие места, где старый контракт ещё описывает
`Имя (мессенджер)` как часть актуального contact-level UI.

Якоря текущего head:
- [tz-new-contact-card.md#L115](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md#L115)
- [tz-new-contact-card.md#L158](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md#L158)
- [tz-new-contact-card.md#L786](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md#L786)
- [tz-new-contact-card.md#L805](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md#L805)

Документ надо привести в соответствие с уже принятым финальным
контрактом, а не создавать новый competing source of truth.

## Что не меняется

- миграции
- backfill / repair
- inbound chronology
- dialog cards
- dialog page
- Bitrix/source mapping
- gender guard
- write-path через `ApplyContactFirstNameAction`
- context-free `Contact::display_name` accessor, если gap-analysis не
  показывает новый подтверждённый баг

## Что не входит

- новая миграция
- новый backfill
- удаление `contacts.name` из схемы
- пересборка inbound logic
- новый generic profile-mapping
- housekeeping Git/PR

## Тестовая стратегия

### Обновить существующие тесты

- убрать ожидание `Имя (мессенджер)` из
  [FilamentContactsResourceTest.php#L192](/Users/abrikosov/Documents/Проект-1/tests/Feature/FilamentContactsResourceTest.php#L192)

### Добавить покрытие

- contact page:
  - строки `Имя (мессенджер)` больше нет
  - header не падает в `contacts.name`
  - fallback идёт в `$record->display_name`
- source badge:
  - `auto`
  - `contact_confirmed`
  - `manual`
  - `null`
- contact table:
  - compact source indicator рядом с именем
- timeline:
  - `contact.first_name_changed`
  - `contact.merge_name_conflict`
- dialog inbox search:
  - поиск больше не опирается на `contacts.name`

### Ручной smoke

1. `source=auto` → badge `Авто`
2. `source=contact_confirmed` → badge `Клиент назвал`
3. `source=manual` → badge `Оператор`
4. пустой `first_name` → badge нет, header показывает `display_name` или `Контакт #id`
5. смена имени → timeline показывает событие
6. merge с другим именем → timeline показывает конфликт
7. поиск inbox работает через `first_name` и `display_name`, без зависимости от legacy `name`

## Критерии приёмки

- на contact page нет отдельного поля `Имя (мессенджер)`
- header contact page не использует прямой fallback в `contacts.name`
- оператор видит source текущего `first_name`
- timeline показывает события смены имени и merge-конфликта имени
- dialog inbox search не использует `contacts.name`
- старый docs-контракт синхронизирован с уже доставленными `Slices 1-2`
- тесты зелёные

## Известные компромиссы

- `contacts.name` может временно оставаться last-resort fallback внутри
  глобального resolver до отдельного cleanup-stream
- `Contact::display_name` остаётся context-free accessor, пока нет нового
  подтверждённого бага
