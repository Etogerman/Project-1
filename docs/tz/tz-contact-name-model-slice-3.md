# ТЗ: Slice 3 — завершение единой модели имени контакта

## Контекст

Slices 1-2 уже в `main`:
- `first_name_source` существует
- `contact_identities.display_name` существует
- inbound path больше не пишет `contacts.name` как active truth
- dialog cards и dialog page уже показывают `Имя из мессенджера`
- inbox search уже ищет по `currentContactIdentity.display_name`

Задача этого шага — не переизобрести модель имени, а дожать оставшиеся
contact-level и docs/UI хвосты.

Этот документ является status-aware implementation ТЗ только для
оставшегося `Slice 3` и не повторяет уже доставленные migrations,
ingest-изменения и repair-step из предыдущих slices.

## Цель шага

Завершить переход на единую модель имени контакта так, чтобы:
- `Имя (мессенджер)` исчезло из contact-level карточки
- header карточки не читал `contacts.name` напрямую
- оператор видел source текущего `first_name`
- timeline умел показывать события изменения имени
- docs больше не описывали старый контракт

## Границы

### Что меняется

1. Contact-level UI
- убрать строку `Имя (мессенджер)` из
  [ViewContact.php:294](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Contacts/Pages/ViewContact.php#L294)
- убрать header fallback на `$record->name` из
  [ViewContact.php:561-562](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Contacts/Pages/ViewContact.php#L561)
- header должен строиться через согласованный global label contract,
  а не через прямой special-case `contacts.name`
- конкретное правило для header:
  - если заполнены `last_name` / `first_name`, сохранить текущий порядок
    `Фамилия Имя`
  - если оба пусты, использовать `$record->display_name`
  - прямой fallback на `$record->name` удалить

2. Source badge
- рядом с `first_name` в contact-level UI показать источник:
  - `auto`
  - `contact_confirmed`
  - `manual`
- badge не нужен там, где имя пустое
- visual spec для contact page:
  - `auto` → текст `Авто`, `data-tone="gray"`
  - `contact_confirmed` → текст `Клиент назвал`, `data-tone="info"`
  - `manual` → текст `Оператор`, `data-tone="success"`
- visual spec для таблицы контактов в
  [ContactResource.php:339](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Contacts/ContactResource.php#L339):
  - рядом с именем компактный indicator с теми же тремя состояниями
  - допустим компактный pill или icon+tooltip, но тексты состояний должны
    оставаться теми же: `Авто`, `Клиент назвал`, `Оператор`

3. Timeline
- [BuildContactHistoryTimelineAction.php:115](/Users/abrikosov/Documents/Проект-1/app/Services/Contacts/BuildContactHistoryTimelineAction.php#L115)
  сейчас пропускает всё, кроме `EVENT_OPERATOR_COMMENT`
- `BuildContactHistoryTimelineAction` должен рендерить:
  - `contact.first_name_changed`
  - `contact.merge_name_conflict`
- шаблоны текста:
  - `contact.first_name_changed`
    - если `new_value` не пуст:
      - title: `Имя изменено`
      - description: `«{previous_value_or_—}» → «{new_value}»`
      - body: `Источник: {new_source_label}`
    - если `new_value` пуст:
      - title: `Имя очищено`
      - description: `Было: «{previous_value_or_—}»`
      - body: `Источник: {previous_source_label}`
  - `contact.merge_name_conflict`
    - title: `Конфликт имени при объединении`
    - description: `При объединении с контактом #{merged_contact_id} найдено другое имя: «{merged_first_name}»`
    - body: `Источник: {merged_first_name_source_label}`

4. Документация
- синхронизировать
  [tz-new-contact-card.md](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md),
  где ещё жив старый контракт `Имя (мессенджер)` на contact-level:
  - [строка 115](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md#L115)
  - [строка 158](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md#L158)
  - [строка 786](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md#L786)
  - [строка 805](/Users/abrikosov/Documents/Проект-1/docs/tz/tz-new-contact-card.md#L805)
- синхронизировать этот старый документ с уже принятым master-contract
  по единой модели имени и не вводить новый competing contract

5. Поиск без legacy `contacts.name`
- в [DialogResource.php:438](/Users/abrikosov/Documents/Проект-1/app/Filament/Resources/Dialogs/DialogResource.php#L438)
  убрать поиск по `contact.name`
- для dialog inbox search остаются:
  - `contact.first_name`
  - `contact.last_name`
  - телефоны
  - `currentContactIdentity.display_name`
  - `external_user_id`
  - `external_username`

### Что остаётся как есть

- migrations
- inbound chronology
- dialog cards
- dialog page
- Bitrix/source mapping
- gender guard
- repair/backfill по dialog identity
- основной write-path через `ApplyContactFirstNameAction`

### Что не входит

- новая миграция
- новый backfill
- пересборка логики inbound sync
- generic profile-mapping
- cleanup удаления `contacts.name` из схемы
- housekeeping вроде закрытия старых PR и удаления веток

## Открытый вопрос

`Contact::display_name` accessor остаётся global/context-free.

Решение по умолчанию для этого шага:
- accessor не трогаем
- dialog surfaces продолжают использовать explicit dialogContext
- отдельный пересмотр accessor — только если gap-analysis подтвердит
  новый реальный баг

## Тестовая стратегия

- feature/UI test:
  - в contact page больше нет строки `Имя (мессенджер)`
  - header больше не падает в `contacts.name`
- feature/UI test:
  - source badge показывается корректно для `auto / contact_confirmed / manual`
- feature/UI test:
  - таблица контактов показывает compact source indicator рядом с именем
- feature test:
  - timeline рендерит `contact.first_name_changed`
  - timeline рендерит `contact.merge_name_conflict`
- feature/UI test:
  - dialog inbox больше не использует `contacts.name` в search path
- docs-review:
  - в актуальных ТЗ нет старого описания contact-level
    `Имя (мессенджер)` как текущего контракта

## Критерии приёмки

- на contact page нет отдельного поля `Имя (мессенджер)`
- header карточки не использует прямой fallback в `contacts.name`
- источник `first_name` виден оператору
- timeline показывает события смены имени и конфликта при merge
- docs не противоречат уже реализованным Slices 1-2

## Известные компромиссы

- `contacts.name` может временно оставаться legacy fallback внутри
  глобального resolver до отдельного cleanup-stream
- context-free accessor `Contact::display_name` пока не пересматривается,
  если нет нового подтверждённого бага
