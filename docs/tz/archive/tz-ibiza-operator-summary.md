# ТЗ: Ibiza — operator-visible summary в истории

## Цель шага

После завершения сценария `VIP Ibiza` оператор должен видеть итог прохождения
сценария не только по тегу контакта, но и в явном operator-visible виде
в истории того `dialog`, где этот сценарий завершился.

Итоговый результат шага:
- по завершению `VIP Ibiza` в истории соответствующего `dialog`
  появляется системная запись с итогом ветки `strong / borderline / weak`
- оператор видит ключевые ответы сценария без открытия runtime state вручную
- текущий контракт `run.*` остаётся источником данных для самого сценария

## Границы

### Что меняется

- для `VIP Ibiza` добавляется отдельная operator-visible запись в истории
  диалога после завершения сценария
- carrier этой записи в рамках шага — отдельная строка в `messages`
  того же `dialog`, а не синтетический feed item вне таблицы сообщений
- запись показывает:
  - код сценария
  - итоговую ветку `strong / borderline / weak`
  - итоговый outcome-tag
  - ключевые ответы из `run.*`, которые реально полезны оператору
  - признак, был ли успешно получен телефон
  - Instagram handle для `strong`, если он был получен
- запись должна быть читаемой в существующем timeline/history UI
- запись создаётся в том же `dialog`, в котором завершился scenario run;
  contact-global summary вне конкретного диалога в этом шаге не вводится
- для этого шага summary фиксируется как outbound system message
  с отдельным dedicated `message_kind` для scenario summary; reuse
  существующих collector/auto-reply kinds не допускается

### Что не меняется

- схема `Ibiza MVP`
- engine-level runtime блоков `message / question / condition / phone_capture`
- запись ответов в `run.*`
- существующий phone capture flow
- теги как основной runtime outcome marker

### Что не входит

- generic summary engine для всех DB-сценариев
- новый отдельный summary-виджет на карточке контакта
- редактирование summary оператором
- перенос всех ответов сценария в профиль контакта
- новая analytics/reporting витрина

## Контракт данных

Сводка строится как immutable snapshot в момент завершения run, а не
подчитывается позже из текущего состояния контакта.

Snapshot summary строится только из уже известных runtime-данных сценария:
- `dialog_id`, в котором завершился run
- `scenario_code`
- `run.state_payload.run.*`
- итоговый `exit_outcome`
- итоговый outcome-tag, вычисленный при завершении run и записанный в summary
  как snapshot-значение
- факт успешного phone capture

Для `Ibiza` в summary допустимо показывать:
- `first_name`
- `dates_response`
- `primary_goal`
- `commitment`
- `budget_tier`
- `departure_city`
- `instagram_handle`, если ветка `strong`

## UX-решение для этого шага

Минимальный operator-visible путь:
- summary фиксируется как системная запись в существующей истории того
  `dialog`, где завершился `VIP Ibiza`
- источником для показа в timeline остаётся обычная `Message`-запись,
  а не отдельная временная projection-структура в builder-е

Это сознательно уже, чем новый summary-блок в карточке:
- история уже существует как операторский timeline
- не нужно открывать новый UI-контур
- оператор получает факт прохождения сценария в хронологическом контексте
  именно того диалога, где шёл сценарий

## Тестовая стратегия

- feature-тест на создание системной history-записи после завершения `VIP Ibiza`
- отдельная проверка для трёх веток:
  - `strong`
  - `borderline`
  - `weak`
- проверка, что summary не создаётся для незавершённого run
- проверка, что summary создаётся в том же `dialog`, где завершился run
- проверка, что summary сохраняется именно как `Message` с dedicated
  scenario-summary kind и `sent_by_type=system`
- проверка, что summary читает данные из `run.*`, а не из профиля контакта
- проверка, что summary не пересчитывает outcome из текущих mutable тегов
  контакта после создания записи

## Критерии приёмки

- после завершения `VIP Ibiza` оператор видит системную summary-запись
  в истории того же `dialog`
- summary хранится в `messages`, а не в отдельной ad-hoc projection-сущности
- summary правильно отражает ветку `strong / borderline / weak`
- summary не врёт о наличии телефона и Instagram
- без завершения сценария summary не появляется
- уже созданная summary-запись не меняется ретроспективно из-за последующих
  изменений тегов контакта
- текущий `Ibiza MVP` runtime и теги продолжают работать как раньше

## Известные компромиссы

- это решение пока только для `VIP Ibiza`, а не generic summary layer
- summary остаётся read-only
- основной runtime source of truth всё ещё в `run.*`; history-запись является
  operator-facing snapshot-проекцией, а не новым источником истины
