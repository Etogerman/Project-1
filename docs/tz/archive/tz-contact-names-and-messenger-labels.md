# ТЗ: Имена контакта и канальные messenger labels

## Цель шага

Убрать из contact-level карточки один глобальный `Имя (мессенджер)` и
перевести messenger label на dialog-scoped контракт.

Итоговый результат шага:
- `contacts.name` перестаёт быть active truth для операторского UI
- channel-specific label хранится в `contact_identities.display_name`
- messenger label показывается только там, где он имеет канальный смысл:
  в карточках диалогов и на странице конкретного диалога

## Границы

### Что меняется

- contact-level поле `Имя (мессенджер)` убирается из вкладки `Общее`
- card/list/dialog UI используют `contact_identities.display_name`
  как основной источник канального имени
- inbound path обновляет `contact_identities.display_name`, а не
  поддерживает `contacts.name` как основную правду
- поиск по inbox должен находить диалог по тому messenger label,
  который сам же показывает оператору

### Что сохраняется

- `first_name` и `last_name` остаются операторскими profile fields контакта
- `Contact.display_name` остаётся global label для contact-level UI
- fallback на `@username` или `external_user_id` допустим только там,
  где у конкретной identity реально нет human-readable label

### Что не входит

- возврат одного общего messenger-name поля на уровень `Contact`
- generic sync одного label во все identity контакта без явного контракта
- изменение Bitrix24 perimeter только ради этого шага
- новая generic profile-mapping система

## Источники истины

### Contact-level label

Для global contact label используется существующий resolver:
- сначала операторские `first_name` / `last_name`
- затем dialog-relevant identity label
- затем relevant identity label
- затем last-resort legacy fallback

Отдельное поле `Имя (мессенджер)` на contact-level UI не является
источником истины и не должно возвращаться в операторскую карточку как
самостоятельное поле.

Шапка карточки контакта использует тот же global resolver.
Она не должна иметь отдельного special-case fallback через самостоятельное
поле `Имя (мессенджер)`.

### Dialog-level label

Для dialog-scoped UI используется:
- `currentContactIdentity.display_name`
- если оно пусто, fallback в `@external_username`
- затем fallback в `external_user_id`

### Runtime sync

Входящие сообщения обновляют `contact_identities.display_name`
на уровне конкретной identity.

`contacts.name` может оставаться legacy/backfill полем, но не должен
считаться активной правдой для канального UX.

## UI-контракт

### Вкладка `Общее`

- поля `Имя`, `Фамилия`, `Пол` и остальной profile UI остаются
- отдельного поля `Имя (мессенджер)` больше нет

### Шапка карточки

- заголовок карточки строится через тот же global contact resolver
- если resolver приходит к identity-based label, это считается допустимым
  global fallback
- отдельного contact-level поля `Имя (мессенджер)` в шапке нет

### Вкладка `Диалоги`

- каждая карточка диалога показывает собственное `Имя из мессенджера`
- это имя относится к текущему `current_contact_identity_id`
- multi-channel контакт может показывать разные labels в разных карточках

### Страница диалога

- в техническом блоке видно `Имя из мессенджера`
- значение должно совпадать с карточкой того же диалога
- MAX-диалог не должен показывать Telegram label по инерции

### Поиск

- inbox search должен находить диалог по `currentContactIdentity.display_name`
- видимый label и searchable label должны совпадать

## Правила безопасности

- уже заполненный `display_name` не должен перетираться случайным
  unrelated channel label
- cross-identity repair допустим только по отдельному явному контракту
- fallback на `@username` лучше, чем запись заведомо чужого label
- UI не должен притворяться, что глобальный contact label равен имени
  конкретного канала

## Тестовая стратегия

- feature-тесты на inbound path:
  - identity `display_name` обновляется на конкретной identity
  - confirmed `first_name` не перетирается auto path
- feature-тесты на dialog resolver:
  - dialog-context побеждает global fallback
  - multi-channel case показывает разные labels
- UI tests:
  - вкладка `Диалоги` показывает `Имя из мессенджера` у каждой карточки
  - page диалога показывает то же имя в техническом блоке
  - inbox search находит диалог по видимому messenger label
- repair/backfill tests:
  - existing `display_name` не перетирается
  - unrelated identity не получают чужой label

## Критерии приёмки

- на contact-level вкладке `Общее` больше нет поля `Имя (мессенджер)`
- channel label хранится и читается из `contact_identities.display_name`
- на вкладке `Диалоги` и на странице диалога label канально-специфичен
- поиск по inbox находит диалог по видимому messenger label
- `contacts.name` не является primary source для этого UX и допустим только
  как last-resort legacy fallback до полного выведения старого контракта

## Известные компромиссы

- `contacts.name` может временно оставаться legacy fallback в отдельных
  read-path до полного выведения старого контракта
- для старых данных может потребоваться отдельный repair/backfill шаг,
  если часть dialog identity не имеет `display_name`
