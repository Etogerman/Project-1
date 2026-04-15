# ТЗ: Ibiza — заполнение пустых полей контакта

## Цель шага

После прохождения сценария `VIP Ibiza` безопасно дозаполнять пустые поля
контакта из ответов сценария, если соответствие между ответом и полем контакта
однозначно и не создаёт риск порчи данных.

Итоговый результат шага:
- сценарий может обогатить пустой профиль контакта
- уже заполненные данные контакта не перетираются
- запись в профиль остаётся узкой и контролируемой

## Границы

### Что меняется

- после прохождения `VIP Ibiza` разрешается заполнение только заранее
  согласованных пустых полей контакта
- первый безопасный mapping для этого шага:
  - нормализованное имя, полученное из ответа `run.first_name` через уже
    существующий extraction/validation path имени
    (`ExtractFirstNameAction: decision=accept + first_name`),
    -> `contacts.first_name`, если `contacts.first_name` пусто

### Что сохраняется

- ответы сценария продолжают жить в `run.*`
- outcome определяется через теги контакта
- phone capture остаётся существующим отдельным path сохранения телефона

### Что не входит

- overwrite уже заполненных полей контакта
- generic `set_contact_field` по произвольным полям
- запись `run.departure_city` в `contacts.city`
- запись `budget_tier`, `commitment`, `primary_goal` в профиль контакта
- перенос outcome-тегов в отдельные поля контакта
- summary/history UI для результатов Ibiza

## Правила маппинга

### Разрешённый mapping в этом шаге

- нормализованное значение first name, извлечённое из ответа `run.first_name`
  через существующий extractor имени с контрактом
  `decision=accept + first_name`, -> `contacts.first_name`

### Явно запрещённый mapping в этом шаге

- `run.departure_city -> contacts.city`
  - город вылета не равен городу проживания
- `run.dates_response -> contacts.*`
- `run.commitment -> contacts.*`
- `run.budget_tier -> contacts.*`
- `run.primary_goal -> contacts.*`
- `run.instagram_handle -> contacts.*`

## Правила безопасности

- запись разрешена только в пустое поле контакта
- непустое `contacts.first_name` не изменяется
- пустое значение из сценария не должно записываться в контакт
- сырой free-text ответ из `run.first_name` не должен записываться в
  `contacts.first_name` напрямую
- если существующий extraction/validation path вернул `decision=retry`
  или `first_name=null`, запись в `contacts.first_name` не выполняется
- сценарий не должен создавать новый generic слой записи в контакт для любых
  DB-сценариев без отдельного ТЗ

## Тестовая стратегия

- если `contacts.first_name` пусто и extractor вернул
  `decision=accept + first_name`, после прохождения `VIP Ibiza`
  `contacts.first_name` заполняется
- если `run.first_name` содержит невалидный или шумный free-text,
  `contacts.first_name` не заполняется
- если `contacts.first_name` уже заполнено, значение не меняется
- поведение одинаково не ломает ветки:
  - `strong`
  - `borderline`
  - `weak`
- existing phone capture и outcome tags остаются зелёными

## Критерии приёмки

- `VIP Ibiza` может безопасно дозаполнить пустой `first_name` только после
  успешного результата `decision=accept + first_name`
  существующего extraction path
- existing `first_name` не перезаписывается
- `city`, `country`, `age_range` и другие несогласованные поля не меняются
- текущий runtime-контракт `run.*` остаётся основным источником сценарных ответов

## Известные компромиссы

- шаг сознательно очень узкий и решает только safe enrichment
- более широкий перенос сценарных данных в профиль контакта потребует
  отдельного контракта по полям, источникам и правилам приоритета
