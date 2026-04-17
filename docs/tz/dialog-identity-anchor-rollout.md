# ТЗ: перевод `Dialog` на identity-модель

## Статус

Это staged-rollout ТЗ на отдельный архитектурный stream.

Это не ТЗ на один кодовый шаг.

Текущий активный slice: только `docs-only inventory + transition contract`.

## Активный slice и write-set

Для текущего active slice разрешено менять только:

1. `docs/tz/dialog-identity-anchor-rollout.md`
2. `docs/tz/dialog-identity-anchor-rollout-inventory.md`
3. `docs/tz/README.md`

В текущий slice не входят:

1. код приложения
2. тесты
3. миграции
4. `AGENTS.md`
5. runtime changes
6. перенос существующих ТЗ в `docs/tz/archive/`
7. `merge` в `staging` или `main`
8. staging dry-run / smoke
9. dangerous ops

## 1. Цель

1. Перевести `Dialog` с модели `contact_id + channel_id` на модель канонической identity.
2. Сделать так, чтобы один внешний пользователь в одном канале соответствовал одному каноническому диалогу.
3. Убрать штатную зависимость merge контактов от объединения диалогов.
4. Сохранить на одном `Dialog` рабочий контур переписки:
   - history
   - route context
   - телефонные признаки
   - bot-subscription признаки
   - Bitrix/Open Lines state
   - source-message поля

## 2. Текущий source of truth

1. В текущем runtime `Dialog` уникален по `[contact_id, channel_id]`.
2. Этот инвариант закреплён:
   - в `AGENTS.md`
   - в миграции `2026_03_31_000006_create_dialogs_table.php`
   - в `DialogSchemaTest`
   - в `ResolveOrCreateDialogAction`
3. До отдельного cutover именно этот инвариант остаётся действующим source of truth репозитория.
4. Новый контракт в этом документе описывается как целевой, а не как уже действующий.

## 3. Новый целевой инвариант

1. `ContactIdentity` остаётся natural key внешнего пользователя в канале.
2. Для `contact_identities` уже действует `unique(channel_id, external_user_id)`.
3. Один `ContactIdentity` должен иметь ровно один канонический `Dialog`.
4. `Dialog` больше не должен рождаться из пары `contact_id + channel_id`.
5. Merge контактов должен перепривязывать канонический диалог к root-contact, а не порождать штатную same-channel consolidation.

## 4. Архитектурное решение

1. Выбирается вариант B: `Dialog` якорится на `ContactIdentity`.
2. В `dialogs` вводится новое поле `anchor_contact_identity_id`.
3. `anchor_contact_identity_id` — канонический identity-anchor диалога.
4. `current_contact_identity_id` сохраняется как отдельное mutable route-source поле.
5. Эти два поля имеют разную семантику и не подменяют друг друга.
6. `external_user_id` не дублируется в `dialogs`.

## 5. Семантика полей

1. `anchor_contact_identity_id`
   - каноническая identity диалога
   - по ней задаётся новая уникальность
2. `current_contact_identity_id`
   - текущий route-source для отправки и восстановления маршрута
   - может меняться без смены anchor
3. `contact_id`
   - CRM-владелец диалога
   - синхронизируется с `anchor_contact_identity_id -> contact_id`
4. `channel_id`
   - денормализованное рабочее поле
   - обязано совпадать с каналом anchor identity
5. `external_chat_id`
   - route context
   - не участвует в уникальности
6. `Message.contact_identity_id`
   - исторический атрибут конкретного сообщения
   - не равен автоматически каноническому anchor диалога

## 6. Какие поля входят в этот stream

Этот stream фиксирует только уже существующие runtime-поля `Dialog` плюс новое целевое поле anchor:

1. `contact_id`
2. `channel_id`
3. `current_contact_identity_id`
4. `anchor_contact_identity_id`
5. `external_chat_id`
6. `pending_auto_reply_source_message_id`
7. `manual_reply_dismissed_source_message_id`
8. `bot_subscription_status`
9. `bot_subscription_changed_at`
10. `bot_subscription_source_message_id`
11. `confirmed_phone_raw`
12. `confirmed_phone_normalized`
13. `phone_confirmed_at`
14. `phone_confirmed_via`
15. `bitrix24_live_chat_id`
16. `bitrix24_live_status`
17. `bitrix24_live_last_exported_at`
18. `bitrix24_live_last_imported_at`
19. `last_message_at`
20. `last_inbound_at`
21. `last_outbound_at`

## 7. Что исключено из этого stream

В этот stream не входят:

1. `status`
2. `stage_code`
3. любые precedence-правила для `status/stage`
4. любые repair semantics для `status/stage`

## 8. Разрешённые и запрещённые состояния

1. Разные `Message.contact_identity_id` внутри одного `Dialog` не считаются corruption автоматически.
2. Historical mixed-identity history допустима, если у диалога можно детерминировать один канонический anchor и валидный route-source.
3. Invariant breach считается только если:
   - anchor нельзя детерминировать
   - два диалога претендуют на один и тот же anchor
   - `dialog.channel_id` конфликтует с каналом anchor identity
   - route-source не даёт валидного routable состояния
4. `split-dialog` в этом ТЗ означает только неоднозначный anchor, а не любой mixed-identity history.

## 9. Целевая схема

### Подготовительная фаза

1. добавить `dialogs.anchor_contact_identity_id nullable`
2. добавить FK на `contact_identities.id`
3. добавить индекс на `anchor_contact_identity_id`

### Финальное состояние

1. `anchor_contact_identity_id not null`
2. `unique(anchor_contact_identity_id)`
3. старый `unique(contact_id, channel_id)` удалён
4. `current_contact_identity_id` сохраняется как отдельное route-source поле
5. в рамках этого stream `current_contact_identity_id` не удаляется и не переименовывается

## 10. Детерминированный backfill

Для каждого существующего `Dialog` anchor определяется так:

1. Сначала собираются все distinct `Message.contact_identity_id` внутри диалога с тем же `channel_id`.
2. Если distinct identity ровно одна, anchor = эта identity.
3. Иначе собираются все `ContactIdentity` для `dialog.contact_id + dialog.channel_id`.
4. Если кандидат ровно один, anchor = этот `ContactIdentity`.
5. `current_contact_identity_id` сам по себе не является достаточным основанием для anchor и используется только как дополнительный consistency-signal:
   - он может подтвердить уже найденного кандидата
   - он может перевести кейс в `route_source_conflict`, если противоречит candidate set
6. Если единственный anchor-кандидат детерминировать нельзя, диалог получает unresolved-статус в отдельном реестре.
7. Backfill не имеет права автоматически repair-ить ambiguous cases.
8. Backfill обязан быть идемпотентным.

## 11. Реестр unresolved и repair cases

Вводится таблица `dialog_identity_repairs`.

Минимальные поля:

1. `issue_type`
2. `dialog_id`
3. `anchor_contact_identity_id nullable`
4. `current_contact_identity_id nullable`
5. `contact_id`
6. `channel_id`
7. `payload jsonb`
8. `status`
9. `detected_at`
10. `resolved_at`

Типы проблем:

1. `missing_anchor`
2. `duplicate_anchor_dialogs`
3. `ambiguous_mixed_identity_history`
4. `channel_mismatch`
5. `route_source_conflict`

Правила:

1. dry-run без постоянного реестра недостаточен
2. все unresolved cases пишутся туда
3. все destructive repair-операции должны ссылаться на запись из этого реестра

## 12. Route-contract

Пока отдельно не согласована новая route-model, термины:

1. `routable`
2. `route_source_conflict`
3. `valid route-source`
4. `sendability`

означают только текущий validated runtime-contract проекта, выраженный через:

1. `ResolveDialogRouteStatusAction`
2. `ApplyDialogRoutePredicateAction`
3. `CanSendThroughDialogAction`

Дополнительно:

1. В рамках этого stream запрещено вводить новый ad hoc смысл `routable`.
2. Для Telegram, MAX, blocked-dialog cases и Bitrix/Open Lines route-validity должна переиспользовать текущий predicate/sendability contract.
3. Если позже понадобится новая route-model, это оформляется отдельным ТЗ, а не молча внутри identity-stream.

## 13. Новый runtime-контракт

После runtime switch:

1. inbound-path сначала ищет или создаёт `ContactIdentity` по `channel_id + external_user_id`
2. затем ищет `Dialog` по `anchor_contact_identity_id`
3. если диалог найден, он переиспользуется
4. если не найден, создаётся
5. `dialog.contact_id` синхронизируется с `anchor_contact_identity_id -> contact_id`
6. `dialog.channel_id` синхронизируется с anchor identity
7. `current_contact_identity_id` продолжает обновляться как route-source по отдельным runtime-правилам
8. создание канонического диалога по `contact_id + channel_id` после switch запрещено

## 14. Transition contract по фазам

### До runtime switch

1. старый lookup по `contact_id + channel_id` остаётся активным
2. новый anchor только backfill-ится и валидируется

### Во время dual-write

1. `anchor_contact_identity_id` заполняется и поддерживается
2. `current_contact_identity_id` продолжает жить как route-source
3. оба поля обязаны совпадать по `channel_id`

### После runtime switch

1. lookup/create читает anchor
2. route/send paths всё ещё используют `current_contact_identity_id`
3. эти два понятия не схлопываются

### После стабилизации

1. отдельно оценивается, нужно ли упрощать модель дальше
2. это не часть текущего stream

## 15. Merge-sequencing contract

1. До runtime switch текущий merge-path остаётся действующим validated behavior.
2. После runtime switch `MergeContactsAction` перестаёт считать same-channel consolidation штатной логикой.
3. Новый merge делает:
   - rebinding `ContactIdentity.contact_id` на root-contact
   - rebinding `Dialog.contact_id` через anchor
   - обновление `current_contact_identity_id` только по route-validity правилам
4. `ConsolidateDialogsForRootContactAction` после switch используется только для legacy repair cases.
5. Уже подтверждённый контракт по `ScenarioRun` сохраняется:
   - active run блокирует destructive consolidation
   - completed/cancelled/failed runs relink-ятся по существующим правилам

## 16. Precedence contract при repair

В этом stream precedence фиксируется только для существующих полей:

1. `current_contact_identity_id`
   - берётся из наиболее свежего routable состояния по текущему route-contract
2. `pending_auto_reply_source_message_id`
   - сохраняется из наиболее свежего валидного source message
3. `manual_reply_dismissed_source_message_id`
   - сохраняется из наиболее свежего валидного source message
4. `bot_subscription_*`
   - берутся из наиболее свежего валидного bot-subscription transition
5. `confirmed_phone_*` и `phone_confirmed_*`
   - берутся из наиболее свежего phone-confirmation события
6. `bitrix24_live_*`
   - `active` выше `failed`, `closed`, `not_linked`
   - при одинаковом статусе побеждает более свежий timestamp
7. `external_chat_id`
   - берётся из наиболее свежего routable route-source
8. `last_message_at`, `last_inbound_at`, `last_outbound_at`
   - максимум по объединяемым данным

`status` и `stage_code` в этот precedence contract не входят.

## 17. Полный blast radius

Это high-risk runtime re-anchor. Затронуты как минимум:

### Создание и поиск диалога

1. `ResolveOrCreateDialogAction`
2. `StoreInboundMessageAction`
3. `BackfillDialogsCommand`
4. `BackfillDialogsForRootContactAction`, если используется

### Metadata и route

1. `SyncMessageDialogMetadataAction`
2. `ResolveDialogRouteSourceAction`
3. `ResolveDialogRoutePayloadAction`
4. `SyncDialogConfirmedPhoneAction`

### Outbound / store / send

1. `StoreManualOutboundMessageAction`
2. `StoreBitrix24OpenLinesOutboundMessageAction`
3. `StoreOutboundScenarioMessageAction`
4. `StoreOutboundAutoReplyMessageAction`
5. `StoreDataCollectionOutboundMessageAction`
6. `StorePhoneCaptureConfirmationAction`
7. `SendManualDialogReplyAction`

### Merge / repair / commands

1. `MergeContactsAction`
2. `ConsolidateDialogsForRootContactAction`
3. `RepairMergedContactDialogsCommand`
4. `CanonicalizeContactPhoneNumbersCommand`, если он затрагивает identity-path

### Jobs и runtime

1. `ProcessDataCollectionResponseJob`
2. `ProcessAutoReplyJob`
3. `GenericDbScenarioRuntime`
4. `ResumeContactDataCollectionAction`
5. phone-capture / data-collection jobs
6. Bitrix/Open Lines export / import jobs

### UI и policy

1. `DialogResource`
2. `ViewDialog`
3. `LoadContactDialogsOverviewAction`
4. `ContactResource`
5. `DialogPolicy`

### Тесты

1. большой feature-контур
2. полный grep-backed inventory тестов выносится в отдельный appendix и является частью `Фазы 0`

## 18. Фазы rollout

### Фаза 0 — docs-only inventory

1. grep-backed inventory
2. transition contract
3. repair taxonomy
4. blast radius appendix

### Фаза 1 — schema prep

1. добавить `anchor_contact_identity_id`
2. FK
3. индекс
4. `dialog_identity_repairs`

### Фаза 2 — deterministic backfill + registry

1. backfill только однозначных anchors
2. ambiguous cases уходят в registry

### Фаза 3 — dual-write

1. runtime начинает писать anchor
2. route-source остаётся отдельным

### Фаза 4 — runtime lookup switch

1. lookup/create через anchor
2. route/send через `current_contact_identity_id`

### Фаза 5 — merge switch

1. merge перестаёт считать same-channel consolidation нормой
2. consolidation уходит в repair-only

### Фаза 6 — repair legacy conflicts

1. repair unresolved registry cases
2. duplicate-anchor dialogs
3. ambiguous mixed-history cases

### Фаза 7 — finalize constraints

1. `anchor_contact_identity_id not null`
2. `unique(anchor_contact_identity_id)`
3. drop `unique(contact_id, channel_id)`

## 19. Уровень делегации и проверка

Нужно жёстко различать:

### `PR в staging`

1. подготовка diff
2. локальные проверки
3. CI
4. draft/ready PR в `staging`

### `через staging`

1. merge в `staging`
2. staging dry-run
3. staging smoke
4. post-deploy проверка на конкретном SHA

Правило по фазам:

1. `Фаза 0` — только docs-only
2. `Фаза 1` — может ограничиться уровнем `PR в staging`
3. `Фазы 2-3` — код можно готовить как `PR в staging`, но фактическая проверка стадии требует отдельного шага `через staging`
4. `Фазы 4-7` — требуют отдельной staging-проверки
5. любые `migrate`, destructive repair и final constraint switch требуют отдельной явной команды

## 20. Отдельные критерии завершения для Фазы 0

`Фаза 0` считается закрытой только если одновременно выполнено всё ниже:

1. создан основной документ rollout-ТЗ: `docs/tz/dialog-identity-anchor-rollout.md`
2. создан отдельный inventory appendix: `docs/tz/dialog-identity-anchor-rollout-inventory.md`
3. обновлён `docs/tz/README.md`
   - новый stream добавлен в список
   - каталог явно допускает `active`, `pre-existing` и `reference / legacy` документы
4. основной документ фиксирует:
   - текущий runtime-source-of-truth
   - целевой identity contract
   - transition contract
   - rollout phases
   - delivery / verification levels
5. inventory appendix является именно grep-backed:
   - содержит перечень контуров
   - содержит список ключевых файлов по контурам
   - содержит команды или паттерны поиска
   - при необходимости содержит агрегированные counts по `app/`, `tests/`, `database/`
6. в тексте явно зафиксировано, что `current_contact_identity_id` и `anchor_contact_identity_id` имеют разную семантику
7. в тексте явно зафиксировано, что `status` и `stage_code` не входят в acceptance этого stream
8. нет изменений вне разрешённого write-set

## 21. Общие критерии приёмки всего stream

1. Один `ContactIdentity` имеет ровно один канонический `Dialog`.
2. Runtime после switch больше не создаёт новые канонические диалоги по старому контракту `contact + channel`.
3. Merge контактов делает rebinding, а не штатную same-channel consolidation.
4. Historical mixed-identity messages не считаются дефектом автоматически.
5. Все недетерминированные cases попадают в `dialog_identity_repairs`.
6. Repair не теряет:
   - history
   - route context
   - `confirmed_phone_*`
   - `bot_subscription_*`
   - `bitrix24_live_*`
   - pending / dismissed source-message поля
7. Telegram / MAX happy-path остаются зелёными.
8. Подтверждённый Bitrix/Open Lines happy-path остаётся зелёным.
