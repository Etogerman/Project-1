<!-- VERCEL BEST PRACTICES START -->
## Best practices for developing on Vercel

These defaults are optimized for AI coding agents (and humans) working on apps that deploy to Vercel.

- Treat Vercel Functions as stateless + ephemeral (no durable RAM/FS, no background daemons), use Blob or marketplace integrations for preserving state
- Edge Functions (standalone) are deprecated; prefer Vercel Functions
- Don't start new projects on Vercel KV/Postgres (both discontinued); use Marketplace Redis/Postgres instead
- Store secrets in Vercel Env Variables; not in git or `NEXT_PUBLIC_*`
- Provision Marketplace native integrations with `vercel integration add` (CI/agent-friendly)
- Sync env + project settings with `vercel env pull` / `vercel pull` when you need local/offline parity
- Use `waitUntil` for post-response work; avoid the deprecated Function `context` parameter
- Set Function regions near your primary data source; avoid cross-region DB/service roundtrips
- Tune Fluid Compute knobs (e.g., `maxDuration`, memory/CPU) for long I/O-heavy calls (LLMs, APIs)
- Use Runtime Cache for fast **regional** caching + tag invalidation (don't treat it as global KV)
- Use Cron Jobs for schedules; cron runs in UTC and triggers your production URL via HTTP GET
- Use Vercel Blob for uploads/media; Use Edge Config for small, globally-read config
- If Enable Deployment Protection is enabled, use a bypass secret to directly access them
- Add OpenTelemetry via `@vercel/otel` on Node; don't expect OTEL support on the Edge runtime
- Enable Web Analytics + Speed Insights early
- Use AI Gateway for model routing, set AI_GATEWAY_API_KEY, using a model string (e.g. 'anthropic/claude-sonnet-4.6'), Gateway is already default in AI SDK
  needed. Always curl https://ai-gateway.vercel.sh/v1/models first; never trust model IDs from memory
- For durable agent loops or untrusted code: use Workflow (pause/resume/state) + Sandbox; use Vercel MCP for secure infra access
<!-- VERCEL BEST PRACTICES END -->

<!-- ABRIKOSOFF CONNECTOR PROJECT START -->

# Abrikosoff Connector

## Проект

Abrikosoff Connector — операторская платформа для работы
с входящими сообщениями из мессенджеров.

Текущий продуктовый контур:
- приём входящих сообщений из Telegram и MAX
- сохранение в единую историю переписки
- rule-based автоответы с `exact_keyword` и `any_inbound`
- условия правил по наличию телефона контакта
- phone capture flow для Telegram и MAX
- AI profile collector после получения телефона
  - active collector имеет приоритет над обычным auto-reply
  - flow: `first_name -> residence_city -> [country] -> [russian_region_confirm] -> age_range -> completion`
  - для российских ambiguous-city кейсов используется deterministic Russian shortcut:
    сначала пытаемся определить `region` или exact candidate set,
    и только потом задаём fallback-вопрос про страну
  - max 2 попытки на поле, затем мягкий skip на следующий безопасный шаг
- для российских контактов после точного определения локации
  асинхронно считается `distance_to_moscow_km`
  - `Москва -> 0`
  - остальные города РФ: `Yandex Geocoder + Haversine`
  - ambiguous-city кейсы считают расстояние только после
    подтверждения `region` и deterministic geocode query
- просмотр контактов, диалогов и истории в админке
- overview-only карточка контакта с профилем, ownership и списком диалогов
- отдельная страница диалога как рабочее место оператора
- телефоны в списке контактов: колонка, фильтры, поиск
- ручной ответ со страницы диалога
- CRUD телефонов из карточки контакта
- ownership контакта (назначение ответственного оператора)
- редактирование профиля контакта из карточки
- ручное возобновление анкеты из карточки
- удаление контакта из карточки

Стратегическое направление:
- Битрикс24 Open Lines happy-path уже подтверждён через отдельный
  box-side пакет в коробке.
  Зафиксированная рабочая конфигурация:
  - Telegram line id `32`
  - MAX line id `31`
  - existing-contact rebinding happy-path работает для Telegram и MAX
- Любое новое расширение Bitrix24 вне подтверждённого happy-path
  требует отдельного ТЗ.
- Возможно подключение дополнительных мессенджеров.

## Стек

- PHP 8.2+, Laravel 11, PostgreSQL
- Filament 5 (админ-панель)
- Tailwind 3, Vite
- PHPUnit 11 (feature-тесты)
- Playwright (e2e smoke-тесты)
- Текущий queue driver — database; долгие side-эффекты
  предпочтительно уводить из HTTP-запроса в очередь.

## Активный runtime

Источником архитектурных решений считается активный Laravel-контур.
Каталог `legacy/` и второстепенные артефакты использовать только
как справочный материал, если они реально влияют на текущую систему.

## Архитектура

Прагматичный Laravel-монолит.

Карта слоёв:
- `app/Models/` — Eloquent-модели
- `app/Data/` — readonly DTO (следовать текущему стилю проекта)
- `app/Services/AI/` — интеграция с Gemini для structured extraction
- `app/Services/Bots/` — webhook, автоответ, ручной ответ, действия с каналами
- `app/Services/DataCollection/` — extractor/action-классы и collector orchestration helpers
- `app/Services/Contacts/` — ownership-действия и phone CRUD/action-классы
- `app/Services/Dialogs/` — dialog routing, history loading, feed building и overview-данные
- `app/Jobs/` — queued orchestration для автоответа, phone capture follow-up и collector flow
- `app/Http/Controllers/` — тонкие HTTP-контроллеры
- `app/Filament/Resources/` — UI админки (Resources + Pages)
- `app/Filament/Resources/*/Pages/` — page-level state и modal orchestration
- `resources/views/filament/` — Blade-партиалы для dialog workspace, contact overview, collector status и profile UI

Ветвление по провайдерам через `match($channel->platform)` в сервисах.
Общий интерфейс провайдера — преждевременная абстракция,
пока не появится третий провайдер или реальная проблема с дублированием.

Рабочий UI-принцип:
- `Contact` — обзорная карточка клиента
- `Dialog` — канальный thread и основное рабочее место оператора
- ownership остаётся на `Contact`, не на `Dialog`

## Доменная модель (стабильная часть)

- **Channel** — подключение бота к мессенджеру (platform, encrypted credentials,
  метаданные бота, health-статус, `auto_reply_mode = rules_only`)
- **Contact** — внешний человек; может иметь `assigned_user_id` (FK → users)
  и флаг `is_auto_reply_enabled`
  - профильные поля: `first_name`, `last_name`, `birth_date`,
    `age_years`, `age_range`, `country`, `city`,
    `region`, `region_status`, `region_source`,
    `distance_to_moscow_km`, `distance_to_moscow_status`,
    `distance_to_moscow_calculated_at`
  - collector state: `data_collection_status`,
    `data_collection_current_field`, `data_collection_started_at`,
    `data_collection_completed_at`, `data_collection_attempts_count`,
    `pending_region_candidates`
  - `region` — канонический российский business-region для фильтров,
    а не обязательно официальный административный субъект
  - `distance_to_moscow_*` — best-effort вычисляемое поле
    для квалификации только по России; считается асинхронно,
    не влияет на progression collector-а
  - вычисляемые поля: `display_name`, `effective_age_years`
- **ContactIdentity** — связь Contact ↔ Channel через external_user_id;
  unique constraint на `[channel_id, external_user_id]`
- **ContactPhoneNumber** — отдельная таблица телефонов контакта
  - `phone_raw`, `phone_normalized`, `source`, `is_primary`
  - unique constraint на `[contact_id, phone_normalized]`
  - primary переустанавливается автоматически при удалении текущего primary
- **Dialog** — канальный thread контакта
  - уникален на пару `[contact_id, channel_id]`
  - хранит route context канала:
    `current_contact_identity_id`, `external_chat_id`,
    `confirmed_phone_*`, `last_message_at`, `last_inbound_at`, `last_outbound_at`
  - manual reply из operator UI отправляется через точный `dialog_id`
- **Message** — единая таблица входящих и исходящих сообщений
  - `direction`: inbound | outbound
  - `message_kind`:
    `inbound_user | inbound_contact_share | outbound_auto_reply |
    outbound_manual_reply | outbound_phone_capture_confirmation |
    outbound_data_collection_question | outbound_data_collection_completion`
  - `provider_event_key` — ключ идемпотентности;
    partial unique index `[channel_id, direction, provider_event_key]`
  - `reply_to_message_id` — FK на родительское сообщение (self-referential)
  - `raw_payload` — jsonb, оригинальный payload от платформы
- **AutoReplyRule** — правило автоответа
  - `match_scope`: `exact_keyword | any_inbound`
  - `contact_phone_condition`: `null | has_phone | missing_phone`
  - `telegram_button_type`, `max_button_type`: `request_phone`
- **ChannelActivityLog** — append-only журнал событий канала
- **User** — внутренний сотрудник (is_active, is_admin)

## Конфигурация

- `config/bots.php` — webhook и платформенные настройки,
  `phone_capture_confirmation_text`, collector questions/messages,
  Gemini settings (`api_key`, `model`, `max_output_tokens`, `thinking_budget`),
  allowed Russian business regions, тексты `russian_region_confirm`
  и rate-limit настройки публичных bot webhook endpoints
- `config/bitrix24.php` — callback URLs, Bitrix24 интеграционные настройки
  и rate-limit настройки публичных callback endpoints
- `config/russian_region_cities.php` — deterministic source of truth
  для `российский город -> exact candidate regions` и
  geocode hints для `distance_to_moscow`
- `config/services.php` — внешние сервисы; в текущем runtime
  здесь лежат `yandex_geocoder` и reference point для Москвы
- `YANDEX_GEOCODER_API_KEY` — обязательный env var
  для расчёта `distance_to_moscow` вне special-case `Москва = 0`
- Токены ботов хранятся в `Channel.credentials` (encrypted:array), а не в .env
- Webhook-секреты генерируются и хранятся в том же поле credentials

## Ключевые файлы для входа в проект

- `app/Http/Controllers/BotWebhookController.php` — точка входа webhook
  c post-secret throttling для публичных bot webhook requests
- `app/Models/Contact.php` — профильные поля, collector state, `display_name`, `effective_age_years`
- `app/Services/Bots/StoreInboundMessageAction.php` — сохранение входящего сообщения
- `app/Data/Bots/StoredInboundMessageResult.php` — result object с `phoneCaptureStatus`
- `app/Services/Bots/BotIncomingMessageNormalizer.php` — нормализация inbound payload по платформам
- `app/Services/Bots/BotAutoReplyService.php` — orchestration автоответа
- `app/Services/Bots/ResolveAutoReplyRuleAction.php` — matching `exact_keyword / any_inbound`
- `app/Services/AI/GeminiApiService.php` — structured Gemini-запросы для extractor-ов
- `app/Services/DataCollection/ExtractFirstNameAction.php` — hybrid extraction имени
- `app/Services/DataCollection/ExtractResidenceCityAction.php` — city-first extraction города проживания и страны
- `app/Services/DataCollection/ExtractCountryAction.php` — extraction страны
- `app/Services/DataCollection/ExtractCityAction.php` — country-aware extraction города
- `app/Services/DataCollection/ResolveRussianRegionCandidatesLookupAction.php` — deterministic lookup exact candidate regions для российских городов
- `app/Services/DataCollection/ResolveRussianRegionAction.php` — Russian region resolution поверх lookup и AI fallback
- `app/Services/DataCollection/ResolveNextDataCollectionFieldAction.php` — определение следующего collector field
- `app/Services/DataCollection/ResumeContactDataCollectionAction.php` — ручное возобновление анкеты
- `app/Services/Geo/YandexGeocoderService.php` — geocode coordinates для российских населённых пунктов
- `app/Services/Geo/ResolveRussianLocalityGeocodeQueryAction.php` — deterministic geocode query resolver для distance-path
- `app/Services/Geo/CalculateDistanceToMoscowAction.php` — расчёт `distance_to_moscow_km` через Haversine
- `app/Jobs/ProcessAutoReplyJob.php` — queued auto-reply
- `app/Jobs/ProcessPhoneCaptureFollowUpJob.php` — queued confirmation после phone share
- `app/Jobs/ProcessDataCollectionQuestionJob.php` — отправка следующего вопроса collector-а
- `app/Jobs/ProcessDataCollectionResponseJob.php` — обработка ответа на collector field
- `app/Jobs/CalculateDistanceToMoscowJob.php` — queued sync `distance_to_moscow_*`
- `app/Services/Bots/StoreOutboundAutoReplyMessageAction.php` — сохранение исходящего автоответа
- `app/Services/Bots/SendManualDialogReplyAction.php` — ручной ответ оператора через точный dialog route
- `app/Services/Dialogs/ResolveDialogRouteSourceAction.php` — определение route source для dialog/provider
- `app/Services/Dialogs/LoadDialogMessagesPageAction.php` — порционная загрузка истории диалога
- `app/Services/Dialogs/BuildConversationFeedViewDataAction.php` — общий builder bubble/feed view-data
- `app/Services/Dialogs/LoadContactDialogsOverviewAction.php` — overview карточек диалогов на странице контакта
- `app/Services/Contacts/AddContactPhoneAction.php` — нормализация и сохранение телефона
- `app/Services/Contacts/UpdateContactPhoneAction.php` — редактирование телефона
- `app/Services/Contacts/DeleteContactPhoneAction.php` — удаление телефона
- `app/Services/Contacts/SyncContactRussianRegionAction.php` — синхронизация `region` и `pending_region_candidates` после изменения `city/country`
- `app/Services/Contacts/SyncContactDistanceToMoscowAction.php` — синхронизация `distance_to_moscow_*` после изменения location
- `app/Services/Contacts/UpdateContactProfileAction.php` — обновление профильных полей контакта
- `app/Services/Contacts/DeleteContactAction.php` — удаление контакта и связанных сущностей
- `app/Services/Contacts/ClaimContactAction.php` — взятие контакта в работу
- `app/Filament/Resources/Contacts/ContactResource.php` — overview карточка контакта
- `app/Filament/Resources/Contacts/Pages/ManageContacts.php` — Livewire-логика контактов
- `app/Filament/Resources/Dialogs/DialogResource.php` — hidden resource страницы диалога
- `app/Filament/Resources/Dialogs/Pages/ViewDialog.php` — operator workspace конкретного диалога
- `resources/views/filament/dialogs/pages/view-dialog.blade.php` — layout страницы диалога
- `resources/views/filament/contacts/partials/contact-dialogs.blade.php` — overview карточки диалогов на контакте

## Принципы разработки

1. Маленькие, изолированные, проверяемые шаги.
2. Расширять существующие пути, прежде чем добавлять новые абстракции.
3. Read-only улучшения предпочтительнее тяжёлых рефакторингов.
4. Использовать существующие Filament Resource / ViewAction modal.
   Для рабочего chat workflow приоритет у уже существующей dialog page,
   а не у расширения contact modal.
5. Новая таблица или сущность — только после обоснования,
   почему текущие модели недостаточны.
6. Transport-логика остаётся локализованной в сервисных классах.

## Соглашения по коду

- Код приложения и комментарии в коде: английский язык.
- UI-тексты и операторский интерфейс: русский язык.
- Документация проекта (включая этот файл, ТЗ, docs/): русский язык.
- Критичная бизнес-логика живёт вне контроллеров и UI;
  для сценариев предпочитать Action-классы с `handle()`,
  для переиспользуемой логики допустимы сервисные классы.
- DTO в `app/Data/` — по возможности readonly, следовать текущему стилю проекта.
- Предпочитать явные константы классов, а не магические строки.
- Формулировать через утверждение желаемого поведения;
  избегать конструкций с отрицанием, если есть утвердительная альтернатива.

## Соглашения по тестам

- Feature-тесты в `tests/Feature/`, с RefreshDatabase.
- Следовать существующему стилю: factories, Http::fake, Livewire::test.
- Тестировать тот слой, который реально меняется.
- Покрывать идемпотентность и edge cases.
- UI wiring тестировать через Livewire structural assertions.
- Уже покрытые webhook, dialog reply и ownership сценарии повторно не дублировать.
- При сомнениях в доменной модели сначала смотреть миграции, модели и текущие feature tests.

## Соглашения по ТЗ

Каждое ТЗ явно фиксирует:
- Цель шага
- Границы (что меняется, что остаётся)
- Тестовую стратегию
- Критерии приёмки
- Известные компромиссы или двусмысленности

## Преждевременная сложность (избегать до явной необходимости)

- Абстрактный интерфейс / адаптер провайдера
- Микросервисы
- SLA-движок или таймеры
- Round-robin назначение
- История назначений (assignment history)
- Новая Conversation / thread сущность поверх уже существующего `Dialog`
- Generic config-driven collector engine
- Auto re-entry / cooldown policy
- Interactions API для Gemini
- Phone-country inference для location
- Generic geocoder / routing engine вне текущего узкого кейса `distance_to_moscow`
- Routing API / Distance Matrix для точного расстояния по дорогам
- Distance buckets / qualification categories
- generic `location_confirm` / non-Russian ambiguous-city engine
- Новый код интеграции с Битрикс24 вне подтверждённого Open Lines happy-path

## Рабочий стиль для агентов

Жёсткое правило:
- без явной команды пользователя на реализацию и без чёткого согласованного ТЗ
  агент не меняет код, конфиги, миграции, тесты, документацию,
  git-состояние и окружения
- если пользователь просит анализ, review, критику, сравнение вариантов,
  план или ТЗ, агент работает только в read-only режиме
- если ТЗ неполное или двусмысленное, агент сначала уточняет
  и не начинает реализацию
- commit, push, migrate, deploy — только по отдельной явной команде пользователя

Стабильные process-правила:
- новые clean streams режутся от `origin/main`, а не от stale mixed-ветки
- перед новым extraction сначала делается audit residual diff против `origin/main`
- каждый clean stream публикуется отдельной веткой и отдельным draft PR
- auto-deploy не закрывает релиз сам по себе: после deploy обязателен post-deploy smoke-check
- старая mixed-ветка используется только как `reference-only`, пока явно не доказано обратное
- подробный workflow описан в `docs/clean-stream-release-flow.md`
- operational checklist описан в `docs/post-deploy-smoke.md`

Перед предложением изменений:
1. Изучить существующие пути в коде.
2. Найти минимальную безопасную точку расширения.
3. Сохранять текущее поведение, если шаг явно его не меняет.
4. Явно обозначать компромиссы и открытые вопросы.

Если AGENTS.md расходится с реальным кодом — источником истины
считается код. Обновление AGENTS.md выполнять отдельным коротким
шагом после подтверждения изменений.

## Поддержка файла

AGENTS.md описывает стабильные решения, а не временные эксперименты.
AGENTS.md обновлять отдельным коротким шагом после подтверждённых изменений.
Файл — живой справочник, а не журнал изменений.

<!-- ABRIKOSOFF CONNECTOR PROJECT END -->
