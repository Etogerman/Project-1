<!-- ABRIKOSOFF CONNECTOR PROJECT START -->

# Abrikosoff Connector

## Критичные правила для агента

Термины:
- `ТЗ` — согласованный контракт текущего stream.
- `Внешнее ТЗ` — versioned ТЗ, которое хранится вне основного репозитория проекта в отдельном spec-repo.
- `Spec revision` — конкретный commit/hash внешнего ТЗ, на который опирается implementation stream.
- `Активный slice` — единственный slice, который агент может реализовывать прямо сейчас.
- `Хвост` — незавершённые обязательства текущего или предыдущего stream: локальный diff, незапушенные изменения, открытый PR, незавершённый CI, незавершённый `staging`/`main` path, cleanup или другой follow-up.
- `Dangerous ops` — `rebase`, `force-push`, любые `migrate`, `production deploy`.

Базовые правила:
1. Без явной команды пользователя на реализацию и без согласованного ТЗ агент не меняет код, конфиги, тесты, документацию, git-состояние и окружения.
2. Если пользователь просит анализ, review, критику, сравнение вариантов, план или подготовку ТЗ, агент работает только в read-only режиме.
3. По умолчанию одновременно допускается только один активный кодовый шаг.
4. Перед новым кодовым шагом агент обязан проверить хвост предыдущего implementation stream.
5. Если хвост не закрыт, новый code stream по умолчанию не начинается. Исключение возможно только по отдельному явному решению пользователя после перечисления хвоста и связанных рисков.
6. Если пользователь явно ограничил шаг локальной работой или явно запретил публикацию, действует локальный режим.
7. Если ТЗ согласовано, пользователь дал команду на реализацию и явно не запретил публикацию, для code stream по умолчанию действует уровень `PR в staging`.
8. Уровень `PR в staging` разрешает в рамках текущего scope: author self-review, helper-review при доступности, `commit`, `push`, draft PR в `staging`, `CI` и исправления в рамках того же scope.
9. Уровень `PR в staging` не включает `merge` в `staging`, staging smoke, PR в `main`, `merge` в `main` и dangerous ops.
10. Уровень `через staging` требует отдельной явной делегации и разрешает `merge` в ветку `staging`, staging deploy-check и staging smoke.
11. Уровень `до merge в main` требует отдельной явной делегации и разрешает после успешного прохождения через `staging` создать PR в `main`, пройти `CI` и review-контур и довести validated diff до `merge` в `main`.
12. Dangerous ops всегда требуют отдельного явного разрешения.
13. Локальные проверки выполняются только там, где они дают честный сигнал. Для UI-only изменений локальная проверка может быть основной. Для логики процессов основной средой проверки считается `staging`, но эта проверка начинается только после `merge` в ветку `staging`.
14. Author self-review обязателен всегда. Для code stream diff дополнительно проходит review двумя вспомогательными агентами, если среда и разрешения это поддерживают.
15. Если по ходу работы появляется двусмысленность, blocker, scope drift или нехватка данных, агент обязан остановиться и запросить следующий шаг.
16. Если агент предлагает варианты следующих действий, варианты должны быть пронумерованы. Формат рекомендации: `Рекомендация: 1. ...` и отдельной строкой `Почему: ...`.
17. Подробный delivery-playbook, уровни делегации и procedural flow описаны в `docs/task-delivery-workflow.md`; `AGENTS.md` фиксирует только верхнеуровневые рамки, инварианты и ограничения.
18. Существенный stream по умолчанию требует versioned ТЗ во внешнем spec-repo до продолжения code stream.
19. Полные stream-ТЗ не хранятся в основном репозитории как source of truth.
20. Перед продолжением реализации для существенного stream-а должны быть явно зафиксированы ссылка на spec-doc и `Spec revision`.
21. В основном репозитории допускаются только process-docs, policy-docs и короткие reference/index документы по внешним ТЗ.
22. Если код, локальные документы и внешнее ТЗ расходятся, агент сначала показывает расхождение пользователю и не придумывает «правильную» версию молча.

## Проект и текущий scope

Abrikosoff Connector — операторская платформа для работы
с входящими сообщениями из мессенджеров.

Текущий продуктовый контур:
- приём входящих сообщений из Telegram и MAX
- сохранение в единую историю переписки
- rule-based автоответы с `exact_keyword` и `any_inbound`
- условия правил по наличию телефона контакта
- phone capture flow для Telegram и MAX
- AI profile collector после получения телефона
- просмотр контактов, диалогов и истории в админке
- overview-only карточка контакта с профилем, ownership и списком диалогов
- отдельная страница диалога как рабочее место оператора
- ручной ответ со страницы диалога
- CRUD телефонов из карточки контакта
- ownership контакта
- редактирование профиля, ручное возобновление анкеты и удаление контакта

Подтверждённый scope и жёсткие границы:
- active collector имеет приоритет над обычным auto-reply
- collector flow: `first_name -> residence_city -> [country] -> [russian_region_confirm] -> age_range -> completion`
- для российских ambiguous-city кейсов используется deterministic Russian shortcut: сначала пытаемся определить `region` или exact candidate set, и только потом задаём fallback-вопрос про страну
- максимум 2 попытки на поле, затем мягкий skip на следующий безопасный шаг
- после точного определения российской локации асинхронно считается `distance_to_moscow_km`; special-case `Москва -> 0`, остальные города РФ идут через `Yandex Geocoder + Haversine`
- ambiguous-city кейсы считают расстояние только после подтверждения `region` и deterministic geocode query
- подтверждённый локальный Bitrix24 happy-path со стороны приложения: `contact sync -> deal sync -> history export`
- Bitrix24 Open Lines happy-path уже подтверждён через box-side пакет; зафиксированная рабочая конфигурация: Telegram line id `32`, MAX line id `31`
- existing-contact rebinding happy-path подтверждён для Telegram и MAX
- любое новое расширение Bitrix24 вне подтверждённого happy-path требует отдельного ТЗ
- возможно подключение дополнительных мессенджеров, но не через преждевременные абстракции

## Стек

- PHP 8.2+, Laravel 11, PostgreSQL
- Filament 5
- Tailwind 3, Vite
- PHPUnit 11
- Playwright
- текущий queue driver — `database`; долгие side-эффекты предпочтительно уводить из HTTP-запроса в очередь

## Источники истины

Приоритет источников истины:
1. Для описания текущего поведения и архитектуры приоритет у активного Laravel runtime.
2. Каталог `legacy/` и второстепенные артефакты используются только как справка, если реально влияют на текущую систему.
3. Для целевого поведения приоритет у явного запроса пользователя и согласованного ТЗ.
4. Если stream ведётся по внешнему spec-repo, source of truth для целевого поведения — внешний spec-doc на согласованной `Spec revision`.
5. Если код и документы расходятся, агент сначала показывает расхождение пользователю и не придумывает «правильную» версию молча.

## Архитектурные и доменные инварианты

Архитектура:
- проект — прагматичный Laravel-монолит
- transport-логика остаётся локализованной в сервисных классах
- критичная бизнес-логика живёт вне контроллеров и UI
- ветвление по провайдерам делается через `match($channel->platform)` в сервисах
- общий интерфейс провайдера — преждевременная абстракция, пока не появится третий провайдер или реальная проблема с дублированием

Рабочий UI-принцип:
- `Contact` — обзорная карточка клиента и ownership-сущность
- `Dialog` — канальный thread и основное рабочее место оператора
- ownership остаётся на `Contact`, не на `Dialog`

Стабильные доменные инварианты:
- `Dialog` уникален на пару `[contact_id, channel_id]`
- `Dialog` хранит route context канала и используется как точка точного manual reply route
- `Message.provider_event_key` участвует в идемпотентности сообщений
- active collector имеет приоритет над обычным auto-reply
- collector progression идёт по зафиксированному flow, с максимум двумя попытками на поле и мягким skip после исчерпания попыток
- `region` — канонический российский business-region для фильтров, а не обязательно административный субъект
- `distance_to_moscow_*` — best-effort квалификационное поле только для России; считается асинхронно, не управляет progression collector-а и для ambiguous-city кейсов запускается только после подтверждения `region`
- live bridge Bitrix24 идёт через `Dialog` / `Message`; missed inbound recovery допустим только внутри подтверждённого Open Lines happy-path

## Критичные интеграционные и конфигурационные инварианты

- `config/bots.php` — источник платформенных настроек, текстов phone capture / collector и Gemini settings
- `config/bitrix24.php` — источник callback URL, интеграционных настроек Bitrix24 и rate-limit правил публичных callback endpoints
- `config/russian_region_cities.php` — deterministic source of truth для `российский город -> exact candidate regions` и geocode hints
- `config/services.php` хранит настройки `yandex_geocoder` и reference point для Москвы
- `BITRIX24_AUTH_SERVER_URL` критичен для token refresh; его отсутствие может вывести `Bitrix24Connection` из рабочего состояния и остановить contact/deal/history/Open Lines sync
- `YANDEX_GEOCODER_API_KEY` обязателен для расчёта `distance_to_moscow` вне special-case `Москва = 0`
- токены ботов и webhook-секреты хранятся в `Channel.credentials`, а не в `.env`

## High-Risk Zones

- webhook ingestion и идемпотентность сообщений
- phone capture flow и follow-up orchestration
- collector progression, retry-логика, soft skip и manual resume
- dialog routing и точность manual reply через `dialog_id`
- Bitrix24 contact/deal/history sync и live export/import happy-path
- российская location-логика, deterministic region resolution и `distance_to_moscow` pipeline

## Start Here

Полный code map при необходимости можно вынести в отдельный reference doc. Для старта почти в любой задаче достаточно этих входных точек:
- `app/Http/Controllers/BotWebhookController.php`
- `app/Models/Contact.php`
- `app/Services/Bots/StoreInboundMessageAction.php`
- `app/Services/Bots/BotAutoReplyService.php`
- `app/Jobs/ProcessAutoReplyJob.php`
- `app/Jobs/ProcessDataCollectionResponseJob.php`
- `app/Services/Contacts/UpdateContactProfileAction.php`
- `app/Services/Bitrix24/SyncContactToBitrix24Action.php`
- `app/Filament/Resources/Dialogs/Pages/ViewDialog.php`
- `resources/views/filament/dialogs/pages/view-dialog.blade.php`

## Принципы разработки

1. Маленькие, изолированные, проверяемые шаги.
2. Расширять существующие пути, прежде чем добавлять новые абстракции.
3. Read-only улучшения предпочтительнее тяжёлых рефакторингов.
4. Использовать существующие Filament Resource / ViewAction modal.
5. Для рабочего chat workflow приоритет у уже существующей dialog page, а не у расширения contact modal.
6. Новая таблица или сущность — только после обоснования, почему текущие модели недостаточны.
7. Формулировать через утверждение желаемого поведения; избегать конструкций с отрицанием, если есть утвердительная альтернатива.

## Соглашения по коду

- код приложения и комментарии в коде: английский язык
- UI-тексты и операторский интерфейс: русский язык
- документация проекта, включая `AGENTS.md`, ТЗ и `docs/`: русский язык
- для сценариев предпочтительны Action-классы с `handle()`
- для переиспользуемой логики допустимы сервисные классы
- DTO в `app/Data/` — по возможности readonly, следовать текущему стилю проекта
- предпочитать явные константы классов, а не магические строки

## Соглашения по языку и git-именованию

- общение агента с пользователем: русский язык, если пользователь явно не попросил иное
- сообщения коммитов: русский язык
- заголовки PR, описания PR и GitHub-комментарии по изменениям: русский язык
- названия веток: русский смысл в `ASCII`-транслитерации, без кириллицы, с префиксом `codex/`
- английские технические термины допустимы только там, где перевод ухудшает точность или создаёт двусмысленность

## Соглашения по тестам

- feature-тесты в `tests/Feature/`, с `RefreshDatabase`
- следовать существующему стилю: factories, `Http::fake`, `Livewire::test`
- тестировать тот слой, который реально меняется
- покрывать идемпотентность и edge cases
- UI wiring тестировать через Livewire structural assertions
- уже покрытые webhook, dialog reply и ownership сценарии повторно не дублировать
- при сомнениях в доменной модели сначала смотреть миграции, модели и текущие feature tests

## Что не делать без отдельного ТЗ

Не вводить до явной необходимости:
- абстрактный интерфейс / адаптер провайдера
- микросервисы
- SLA-движок или таймеры
- round-robin назначение и assignment history
- новую Conversation / thread сущность поверх уже существующего `Dialog`
- generic config-driven collector engine
- auto re-entry / cooldown policy
- Interactions API для Gemini
- phone-country inference для location
- generic geocoder / routing engine вне текущего узкого кейса `distance_to_moscow`
- routing API / Distance Matrix для точного расстояния по дорогам
- distance buckets / qualification categories
- generic `location_confirm` / non-Russian ambiguous-city engine
- новый код интеграции с Bitrix24 вне подтверждённого Open Lines happy-path

## Подробные документы

- `docs/task-delivery-workflow.md` — подробный implementation и delivery workflow
- дополнительные reference docs по domain/config/code-map допустимы отдельным `docs-only` шагом, если текущего верхнеуровневого контекста станет недостаточно
- существенные ТЗ живут во внешнем spec-repo; локальный `docs/` хранит только policy/process/reference

## Поддержка файла

`AGENTS.md` описывает стабильные решения, а не временные эксперименты.
`AGENTS.md` обновлять отдельным коротким шагом после подтверждённых изменений.
Файл — живой справочник, а не журнал изменений.

<!-- ABRIKOSOFF CONNECTOR PROJECT END -->
