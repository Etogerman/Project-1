# Инженерные стандарты проекта

Этот модуль читается перед изменением Laravel-кода, схемы данных, тестов,
Filament/Livewire UI, интеграционной логики или проектной конфигурации.

Общие требования к языку документов и Git определены только в
[модуле языка и Git](language-and-git-standards.md).

## Источники истины

1. Текущее поведение и архитектура подтверждаются активным Laravel runtime,
   кодом и тестами; `legacy/` используется только как справка.
2. Целевое поведение задают явный запрос пользователя и согласованное ТЗ.
3. После publish-boundary существенного stream-а контрактом является внешний
   `Spec doc` на согласованной `Spec revision`.
4. Локальные `docs/` содержат process, policy и reference. Расхождения сначала
   показываются пользователю.

## Архитектура и домен

- Проект остаётся прагматичным Laravel-монолитом.
- Критичная бизнес-логика живёт в service/action-классах, не в контроллерах и UI.
- Transport-логика локализуется в сервисах; ветвление провайдера использует
  существующий `match($channel->platform)`. Общий provider interface появляется
  только при третьем провайдере или доказанной проблеме дублирования.
- `Contact` — ownership и обзор клиента; `Dialog` — канальный thread и рабочее
  место оператора. Ownership не переносится на `Dialog`.
- `Dialog` уникален для `[contact_id, channel_id]`, хранит route context и задаёт
  точный manual reply route.
- `Message.provider_event_key` участвует в идемпотентности.
- Active collector имеет приоритет над обычным auto-reply; progression следует
  зафиксированному flow, максимум две попытки на поле, затем мягкий skip.
- Сущность, принадлежащая Contact, явно определяет удаление; обычно
  `cascadeOnDelete()` от `contact_id`, preview удаления и regression-тест.
- `region` — российский business-region; `distance_to_moscow_*` — асинхронное
  best-effort поле и не управляет progression collector-а.
- Live bridge Bitrix24 проходит через Dialog/Message; missed inbound recovery
  допустим только внутри подтверждённого Open Lines happy-path.

Текущий продуктовый контур и high-risk зоны:
[`docs/reference/project-scope.md`](../../reference/project-scope.md).

## Конфигурация

- `config/bots.php` — платформы, тексты phone capture/collector и Gemini.
- `config/bitrix24.php` — callback URL, Bitrix24 и rate limits callback endpoints.
- `config/russian_region_cities.php` — детерминированные кандидаты регионов.
- `config/services.php` — Yandex Geocoder и reference point Москвы.
- `BITRIX24_AUTH_SERVER_URL` обязателен для token refresh.
- `YANDEX_GEOCODER_API_KEY` обязателен для `distance_to_moscow`, кроме Москвы.
- Токены ботов и webhook-секреты живут в `Channel.credentials`, не в `.env`.

## Реализация и UI

1. Изменения делаются маленькими, изолированными и проверяемыми шагами.
2. Сначала расширяется существующий путь; новая абстракция, таблица или сущность
   требует доказательства недостаточности текущей модели.
   Безопасное улучшение только для чтения предпочтительнее тяжёлого
   рефакторинга, если оно закрывает ТЗ.
3. Сценарии оформляются Action-классами с `handle()`; переиспользуемая логика —
   сервисами; DTO в `app/Data/` по возможности readonly.
4. Используются явные константы вместо магических строк.
5. Новый UI-элемент повторяет ближайший однотипный паттерн по структуре,
   визуальному весу, подписям, ошибкам и loading-state.
6. Операторский UI применяет `docs/UI_PATTERNS.md`, `design/tokens.css`,
   `design/base.css`, ближайший HTML-образец и проектные `.ac-*` классы.
7. Для chat workflow приоритет у существующей dialog page, не contact modal.

## Тесты и команды

1. Любое изменение поведения добавляет или обновляет тест изменяемого слоя.
2. Feature-тесты используют существующий стиль `tests/Feature`, factories,
   `RefreshDatabase`, `Http::fake` и `Livewire::test`.
3. Проверяются идемпотентность и крайние случаи; UI wiring — структурными
   Livewire assertions. Уже покрытые webhook/dialog/ownership сценарии не
   дублируются без причины.
4. Миграции, модели и текущие feature-тесты проверяются до предположений о домене.
5. Laravel DB/feature/integration тесты, миграции, сидеры и сборки запускаются
   последовательно. Два `php artisan test` процесса не работают одновременно с
   общей тестовой БД.
6. Параллельно допустимы только проверки без общей записи: чтение, `rg`,
   `php -l`, `git diff --check` и сходные статические проверки.
7. Доказательства усиливаются по лестнице: чтение кода/документов → статическая
   проверка → автотест → локальный runtime → staging → production. Более высокий
   уровень нужен только когда этого требует риск или критерий готовности.
8. Повторяемые operational-процедуры живут в `docs/runbooks/`, включая
   [`release-rollback`](../../runbooks/release-rollback.md) и
   [`test-env`](../../runbooks/test-env.md).

## Изменения, требующие отдельного основного ТЗ

Отдельное ТЗ требуется для provider interface/adapter, микросервиса, SLA/timer
engine, round-robin и assignment history, новой Conversation-сущности поверх
Dialog, generic collector engine, auto re-entry/cooldown, Gemini Interactions
API, phone-country inference, generic geocoder/routing engine, Distance Matrix,
distance buckets, generic non-Russian location confirmation и нового Bitrix24
кода вне подтверждённого Open Lines happy-path.
