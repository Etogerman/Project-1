# ТЗ: Bitrix24 Open Lines manual reply author model, phase 1a

## Цель

Перевести экспорт только `KIND_OUTBOUND_MANUAL_REPLY` с текущего
`imconnector.send.messages` на Open Lines path, при котором сообщение
в Bitrix24 отображается не как сообщение клиента.

## Проблема

Сейчас локально созданные outbound-сообщения Abrikosoff попадают
в Bitrix24 Open Lines через custom connector path и выглядят как
сообщения клиента.

Для `manual reply` это даёт неверную авторскую модель в рабочем
чате Bitrix24 и искажает операторскую историю.

## Текущий runtime и точки изменения

Основной текущий runtime:

- `app/Services/Bots/SendManualDialogReplyAction.php`
- `app/Services/Bots/StoreManualOutboundMessageAction.php`
- `app/Services/Bitrix24/ExportMessageToBitrix24OpenLinesAction.php`
- `app/Services/Bitrix24/BuildBitrix24OpenLinesMessagePayloadAction.php`
- `app/Services/Bitrix24/IsMessageReadyForBitrix24LiveExportAction.php`
- `app/Services/Bitrix24/IsDialogReadyForBitrix24LiveBridgeAction.php`
- `app/Services/Bitrix24/Bitrix24ApiClient.php`
- `app/Services/Bitrix24/NormalizeBitrix24OpenLinesEventAction.php`
- `app/Services/Bitrix24/StoreBitrix24OpenLinesOutboundMessageAction.php`
- `app/Models/Bitrix24MessageExport.php`
- `database/migrations/2026_04_01_000010_create_bitrix24_message_exports_table.php`

Тестовая точка входа:

- `tests/Feature/Bitrix24OpenLinesLiveExportTest.php`

## Scope

### Что входит в phase 1a

- только `manual reply` со страницы диалога;
- только outbound mirror в Bitrix24 Open Lines;
- новый author model через Open Lines REST path;
- сохранение текущего happy-path для клиента и для старого
  non-manual Bitrix export.

### Что остаётся без изменений

- `auto reply`;
- `collector message`;
- `system event`;
- blocked-feedback path;
- inbound import path из Bitrix24;
- box-side пакет;
- exact operator mapping;
- операторский UI-alert.

### Что вне scope

- `phase 1b` для `auto reply`;
- `phase 1c` для `collector message`;
- `phase 2` для `exact operator`;
- deduped-callback model;
- новый transport для остальных message kinds;
- `merge`, `deploy`, dangerous ops.

## Ключевые архитектурные решения

- Phase 1a меняет только transport path для `manual reply`.
- Exact operator в этот slice не входит.
- Primary CRM anchor — только `Contact`.
- Primary lookup chat — только через `imopenlines.crm.chat.get`.
- Fallback path — только через `imopenlines.session.open`.
- Старый `imconnector.send.messages` для phase 1a manual reply
  не используется как fallback.

## Author model

- `phase 1a = generic Bitrix employee actor`;
- actor берётся только из `bitrix24.openlines.service_user_id`;
- `bitrix24.defaults.assigned_user_id` для этого slice не используется;
- сообщение в Bitrix24 больше не выглядит клиентским, но и не обязано
  выглядеть сообщением exact оператора Abrikosoff.

Это осознанный продуктовый компромисс phase 1a и не считается багом.

## CRM anchor

- primary anchor: `rootContact->bitrix24_contact_id`;
- `Deal` не используется как primary anchor;
- если linked Bitrix contact отсутствует, phase 1a path не применяется.

## Router contract

- Phase 1a router живёт внутри live export runtime.
- Producers и queueing не меняются:
  - `StoreManualOutboundMessageAction` по-прежнему ставит live export
    в очередь;
  - остальные producers не меняются.
- Ветвление transport path делается внутри
  `ExportMessageToBitrix24OpenLinesAction` или в узком action,
  который вызывается только из него.
- Правило ветвления:
  - `KIND_OUTBOUND_MANUAL_REPLY` -> новый phase 1a path;
  - все остальные message kinds -> текущий старый path.

## Applicability contract

Новый path применяется только если одновременно выполнены все текущие
базовые условия live bridge:

- `bitrix24.features.openlines_enabled = true`;
- у root contact есть `bitrix24_contact_id`;
- `bitrix24_sync_status = synced`;
- `bitrix24_sync_pending = false`;
- у диалога заполнен `external_chat_id`.

Phase 1a не расширяет readiness contract.

Identity-only MAX dialogs без `external_chat_id` не входят в scope
phase 1a.

## Primary transport path

Для каждого `manual reply` exporter обязан:

1. определить, что `message kind = KIND_OUTBOUND_MANUAL_REPLY`;
2. проверить applicability contract;
3. взять `root contact` и `bitrix24_contact_id`;
4. вызвать `imopenlines.crm.chat.get`:
   - `CRM_ENTITY_TYPE = contact`
   - `CRM_ENTITY = <bitrix24_contact_id>`
   - `ACTIVE_ONLY = Y`
5. разрешить `CHAT_ID`;
6. вызвать `imopenlines.crm.message.add`:
   - `CRM_ENTITY_TYPE = contact`
   - `CRM_ENTITY = <bitrix24_contact_id>`
   - `USER_ID = <bitrix24.openlines.service_user_id>`
   - `CHAT_ID = <resolved chat id>`
   - `MESSAGE = <plain text manual reply>`

## Формат сообщения

- В `crm.message.add` уходит только `plain text`.
- HTML, rich text и сырой `sourceText` в phase 1a не отправляются.
- Если локальный manual reply был введён не в plain-text виде,
  перед экспортом он приводится к plain-text без Bitrix-specific
  formatting.

## Критерий подходящего чата

После `crm.chat.get(contact, ACTIVE_ONLY=Y)`:

- если найден ровно один active chat -> использовать его;
- если найдено больше одного active chat:
  - сначала фильтровать по
    `CONNECTOR_ID == current dialog connectorCode`;
  - если после фильтра остаётся ровно один chat -> использовать его;
  - иначе это `ambiguous_chat`;
- если active chats не найдено -> переходить в fallback path.

### Известное ограничение метода

Если после фильтра по `CONNECTOR_ID` остаётся несколько active chats,
это terminal `ambiguous_chat`.

В phase 1a это считается известным ограничением выбранного REST path,
а не багом реализации.

### ACTIVE_ONLY policy

В phase 1a нет fallback `ACTIVE_ONLY=Y -> N`.

Неактивные и исторические чаты вслепую не используются.

## Fallback path

`imopenlines.session.open` разрешён только если одновременно
заполнены:

- `connectorCode`;
- `lineId`;
- `external_chat_id`;
- `currentContactIdentity.external_user_id`.

Тогда:

1. собирается
   `USER_CODE = <connector>|<LINE_ID>|<CONNECTOR_CHAT_ID>|<CONNECTOR_USER_ID>`;
2. вызывается `imopenlines.session.open`;
3. из ответа берётся `chatId`;
4. `chatId` используется в `imopenlines.crm.message.add`.

Если хотя бы одного обязательного поля нет:

- fallback не выполняется;
- экспорт завершается controlled failure.

## Ограничение для MAX

Если MAX-диалог sendable для клиента, но у него нет `external_chat_id`,
а есть только `external_user_id`, это допустимое ограничение phase 1a:

- клиентское сообщение всё равно уходит как сейчас;
- mirror в Bitrix новым path может завершиться controlled failure;
- phase 1a не меняет readiness contract ради этого кейса.

## Политика при отсутствии чата

Если:

- `crm.chat.get` не дал usable chat;
- и `session.open` недоступен или не дал `chatId`;

тогда:

- phase 1a не откатывается на старый `imconnector.send.messages`;
- экспорт завершается controlled failure с точной причиной.

## Recovery через `crm.chat.user.add`

- actor не добавляется в чат заранее;
- сначала всегда пробуется `crm.message.add`;
- `crm.chat.user.add` вызывается только на access-related failure
  (`CANCELED` или эквивалентный запрет отправки);
- recovery policy:
  1. один вызов `crm.chat.user.add`;
  2. один повтор `crm.message.add`;
  3. если повтор неуспешен -> controlled failure без циклов.

### Допустимый side effect

`crm.chat.user.add` может permanently добавить service actor
в Open Lines chat.

В phase 1a это допустимо, если происходит только на recovery path
и только для `bitrix24.openlines.service_user_id`.

## Retry contract

### Read-only lookup methods

- `imopenlines.crm.chat.get`;
- `imopenlines.session.open`.

Для них допускается текущий retry policy клиента, потому что
они не создают сообщение.

### Mutating methods

- `imopenlines.crm.message.add`;
- `imopenlines.crm.chat.user.add`.

Для них phase 1a запрещает прозрачный blind retry на уровне
общего клиента.

Реализация обязана:

- либо вызывать их через отдельный no-retry path;
- либо ввести явный режим `without_transport_retry`.

### Явный mutating retry policy

- `crm.message.add`:
  - один явный вызов;
  - без автоматического transport-level retry.
- `crm.chat.user.add`:
  - один recovery-вызов;
  - затем ровно один повтор `crm.message.add`;
  - без циклов и без скрытых повторов.

Если mutating call завершился неоднозначно:

- статус не переходит в blind retry;
- кейс уходит в `failed_uncertain`.

## Storage contract

Source of truth для нового mirror path остаётся запись
`bitrix24_message_exports` для конкретного `message_id`.

Phase 1a не вводит новую таблицу.

Предпочтительное решение — минимально расширить
`bitrix24_message_exports`.

### Обязательные transport fields

Для phase 1a storage обязан хранить:

- `transport_method`;
- `resolved_bitrix_chat_id`;
- `bitrix_remote_message_id`;
- `failure_code`;
- `failure_uncertain`.

### Status model

Основной `export_status` не расширяется новым enum-слоем.

Остаются:

- `pending`;
- `exported`;
- `failed`.

Дополнительная семантика хранится отдельно:

- `transport_method`;
- `failure_code`;
- `failure_uncertain`.

Следствия:

- terminal deterministic failure =
  `export_status=failed`, `failure_uncertain=false`;
- uncertain outcome =
  `export_status=failed`, `failure_uncertain=true`.

## Write ordering contract

Для successful export порядок должен быть таким:

1. Bitrix mutating call завершился success-response;
2. локально сохранены:
   - `transport_method`;
   - `resolved_bitrix_chat_id`;
   - `bitrix_remote_message_id`;
   - `export_status=exported`;
3. только после этого кейс считается завершённым.

Если после Bitrix success локальная фиксация не завершилась:

- кейс считается `failed_uncertain`;
- blind retry запрещён.

## Anti-loop contract

Для phase 1a допустима только `no-callback model`.

До rollout на staging должно быть подтверждено, что сообщения,
отправленные через `imopenlines.crm.message.add`:

- не возвращаются в inbound import path как
  `OnSendMessageCustom` / `OnImConnectorMessageAdd`;
- не создают новый локальный `Message` в истории диалога.

Если staging показывает обратное:

- phase 1a блокируется;
- нужен отдельный stream на dedupe/echo handling.

## Controlled failure contract

Controlled failure для `manual reply` допустим, если одновременно:

- клиент уже получил manual reply;
- операторский workflow не блокируется;
- локальная диагностика фиксирует точную причину;
- duplicate в Bitrix не создаётся;
- blind retry не запускается.

## Failure classification contract

Классификация failure code должна происходить в одном месте
phase 1a runtime.

Минимально обязательные коды:

- `no_active_chat`;
- `ambiguous_chat`;
- `session_open_unavailable`;
- `session_open_failed`;
- `chat_access_denied`;
- `chat_user_add_failed`;
- `message_send_failed`;
- `failed_uncertain`.

## Observability requirements

Phase 1a обязан дать раздельную наблюдаемость минимум по:

- `manual_reply_export_primary_success`;
- `manual_reply_export_fallback_success`;
- `manual_reply_export_ambiguous_chat`;
- `manual_reply_export_no_active_chat`;
- `manual_reply_export_chat_user_add_recovery_success`;
- `manual_reply_export_failed_uncertain`.

На staging после smoke должны быть доступны ответы на вопросы:

- сколько manual replies ушло через primary path;
- сколько ушло через fallback;
- сколько завершилось `ambiguous_chat`;
- сколько завершилось `failed_uncertain`.

## Coexistence contract

В phase 1a допустимо и ожидаемо одновременное существование двух
transport path:

- `manual reply` -> новый Open Lines REST path;
- всё остальное, включая blocked-feedback -> текущий старый path.

Это сознательная часть rollout-а, а не временная архитектурная ошибка.

## Staging preconditions

Acceptance phase 1a допустим только если одновременно:

- `BITRIX24_OPENLINES_ENABLED=true`;
- `BITRIX24_FAKE_HAPPY_PATH_ENABLED=false`;
- задан `bitrix24.openlines.service_user_id`;
- service user существует в staging Bitrix;
- service user имеет право писать в Open Lines чат;
- staging portal использует реальный Open Lines happy-path;
- есть linked Bitrix contact и активный чат для smoke-сценария.

Подтверждённый текущий факт для этого ТЗ:

- staging service user реально существует в Bitrix.

## Acceptance criteria

Acceptance относится только к success-path smoke сценариям,
а не к 100% всех manual replies.

Phase 1a считается успешным, если на `staging` подтверждено:

1. `manual reply` больше не выглядит в Bitrix сообщением клиента;
2. клиент получает `manual reply` как и раньше;
3. inbound bridge из Bitrix не сломан;
4. retry не создаёт duplicate;
5. controlled failure фиксируется с точной причиной;
6. non-manual message kinds не меняют текущее поведение.

## Обязательная staging smoke matrix

1. Telegram manual reply with active chat;
2. MAX manual reply with active chat and `external_chat_id`;
3. ambiguous chat case;
4. no active chat -> `session.open` fallback;
5. recovery через `chat.user.add`;
6. no-callback verification;
7. coexistence со старым blocked-feedback path.

## Достаточность диагностики

Для phase 1a достаточно существующего admin/debug контура `Bitrix24`.

Новый UI-alert в операторском интерфейсе intentionally out of scope.

## Blockers для старта code stream

Phase 1a не считается готовым к реализации, если до старта
не подтверждены одновременно:

- no-retry contract для mutating methods;
- schema contract для `bitrix24_message_exports`;
- `no-callback model` на staging;
- `BITRIX24_FAKE_HAPPY_PATH_ENABLED=false` для acceptance.

## Следующие шаги вне этого slice

- `phase 1b` — `auto reply`;
- `phase 1c` — `collector message`;
- `phase 2` — `exact operator`;
- отдельный stream — `system event`.
