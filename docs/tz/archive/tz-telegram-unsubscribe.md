# ТЗ: Telegram unsubscribe

## Цель

Реализовать поддержку `Telegram unsubscribe` для private-диалогов так,
чтобы система:

- фиксировала факт блокировки и разблокировки бота пользователем;
- сохраняла эти события в истории диалога;
- обновляла blocked-state соответствующего `Dialog`;
- не пыталась отправлять новые сообщения в заблокированный диалог;
- показывала blocked-state оператору в истории, preview и route-status;
- не создавала ложный операторский сигнал `Требует ответа`.

## Проблема

Сейчас активный runtime не умеет:

- принимать `my_chat_member` как источник unsubscribe state;
- хранить отдельный blocked-state на `Dialog`;
- отличать system event от обычного пользовательского inbound;
- централизованно запрещать outbound-отправки в заблокированный Telegram dialog.

Из-за этого:

- оператор не видит в истории, что пользователь заблокировал бота;
- система может продолжать пытаться отправлять автоответы, сообщения анкеты и scenario outbound в уже заблокированный диалог;
- preview и route-status не отражают реальный статус канала общения;
- блокировка пользователя остаётся transport-level проблемой, а не явным доменным состоянием диалога.

## Текущий runtime и место изменения

Эпик реализуется внутри активного Laravel-контура и не требует новой сущности поверх `Dialog`.

Текущие опорные точки runtime:

- вход webhook:
  - `app/Http/Controllers/BotWebhookController.php`
- нормализация inbound:
  - `app/Services/Bots/BotIncomingMessageNormalizer.php`
- сохранение inbound:
  - `app/Services/Bots/StoreInboundMessageAction.php`
- route-status:
  - `app/Services/Dialogs/ResolveDialogRouteStatusAction.php`
  - `app/Services/Dialogs/DialogRoutePredicate.php`
  - `app/Services/Dialogs/ApplyDialogRoutePredicateAction.php`
- manual reply:
  - `app/Services/Bots/SendManualDialogReplyAction.php`
- auto-reply:
  - `app/Services/Bots/BotAutoReplyService.php`
  - `app/Jobs/ProcessAutoReplyJob.php`
- collector outbound:
  - `app/Jobs/ProcessPhoneCaptureFollowUpJob.php`
  - `app/Jobs/ProcessDataCollectionQuestionJob.php`
  - `app/Jobs/ProcessDataCollectionResponseJob.php`
- scenario outbound:
  - `app/Services/Scenarios/WarmupScenario.php`
  - `app/Services/Scenarios/NeedsDiscoveryScenario.php`
  - `app/Services/Scenarios/GenericDbScenarioRuntime.php`
- read models и UI:
  - `app/Services/Dialogs/BuildConversationFeedViewDataAction.php`
  - `app/Services/Dialogs/LoadContactDialogsOverviewAction.php`
  - `app/Filament/Resources/Dialogs/DialogResource.php`
  - `resources/views/filament/contacts/partials/conversation-chat.blade.php`
  - `resources/views/filament/contacts/partials/contact-dialogs.blade.php`
  - `resources/views/filament/dialogs/partials/reply-composer.blade.php`

## Продуктовый результат

После завершения эпика оператор должен получать следующий UX:

1. Пользователь блокирует Telegram-бота.
2. В историю текущего диалога попадает системное событие `Клиент заблокировал бота`.
3. Диалог переходит в blocked-state.
4. Route-status и preview отражают blocked-state.
5. Ни manual reply, ни auto-reply, ни collector, ни scenario outbound больше не пытаются писать в этот диалог.
6. Если пользователь позже разблокирует бота, в историю попадает `Клиент разблокировал бота`, blocked-state снимается, и последующие отправки снова разрешаются.

## Границы

### Что меняется

- Telegram webhook subscription и Telegram inbound normalizer.
- Схема `messages` и `dialogs`.
- Сохранение inbound system event.
- Read-model логика истории, preview и route-status.
- SQL-scopes route readiness.
- Логика sendability всех текущих bot outbound path.
- Feature-тесты и parity-тесты.

### Что остаётся как есть

- `Contact` остаётся обзорной карточкой клиента, а `Dialog` остаётся канальным thread.
- `contact.is_auto_reply_enabled` не становится unsubscribe-флагом и не меняется.
- Phone capture flow не меняет свою бизнес-логику.
- Collector state и scenario state не переводятся автоматически в cancelled/failed из-за blocked-state.
- Bitrix24 happy-path не расширяется и не меняется.
- MAX runtime в этот эпик не входит.

### Вне scope

- MAX unsubscribe.
- Media ingest.
- Replay пропущенных сообщений после unblock.
- Автоматическое восстановление blocked-state по `403 bot was blocked by the user`.
- Новый Bitrix24 transport path.
- `merge`, `rebase`, `deploy`, публикация изменений.

## Продуктовые правила и инварианты

- Source of truth для blocked-state первой версии — webhook `my_chat_member`.
- Unsubscribe является route-state конкретного `Dialog`, а не настройкой `Contact`.
- Любое корректно распознанное unsubscribe event сохраняется в историю, даже если оно устаревшее относительно текущего состояния диалога.
- Denormalized state на `Dialog` обновляется только если событие не старее текущего `bot_subscription_changed_at`.
- `unblock` снимает запрет только на будущие отправки.
- System event может стать последним preview-сообщением, но не должен создавать inbox status `Требует ответа`.
- Blocked-state не должен завершать active collector run и не должен завершать active scenario run автоматически.
- Ни один текущий bot outbound path не должен обходить blocked-send guard.

## Архитектурные решения

- Primary source of truth для blocked-state — Telegram webhook `my_chat_member`.
- Системные события хранятся в `messages` как отдельный inbound kind:
  - `message_kind = inbound_system_event`
  - `system_event_code nullable`
- Для этих сообщений:
  - `direction = inbound`
  - `sent_by_type = system`
  - `sent_by_system_code = telegram_bot_subscription`
- Blocked-state хранится на `dialogs`, а не вычисляется из истории.
- Общий blocked-send guard реализуется как внутренний application-level action/service в текущем Laravel-монолите без введения нового provider interface.
- В первой версии `403 bot was blocked by the user` fallback не входит и рассматривается как отдельный hardening slice.

## Схема данных

### Изменения в `messages`

Добавить:

- поддержку `message_kind = inbound_system_event`;
- поле `system_event_code nullable`.

Поддерживаемые `system_event_code` первой версии:

- `bot_blocked_by_user`
- `bot_unblocked_by_user`

### Изменения в `dialogs`

Добавить:

- `bot_subscription_status nullable`
- `bot_subscription_changed_at nullable`
- `bot_subscription_source_message_id nullable`

Поддерживаемые `bot_subscription_status` первой версии:

- `null`
- `blocked_by_user`

Семантика:

- `null` означает, что подтверждённого blocked-state нет;
- после unblock статус очищается обратно в `null`, а не переводится в искусственный `active`.

## Внешний Telegram contract

Эпик опирается на Telegram Bot API:

- `Update / my_chat_member`
- `ChatMemberUpdated`
- `setWebhook / allowed_updates`

Первая версия поддерживает только private Telegram unsubscribe contract.

## Нормализация Telegram webhook

### Subscription

В `config/bots.php` в `telegram.allowed_updates` добавить `my_chat_member`.

### Controller-level routing

`BotWebhookController` должен:

- распознавать `my_chat_member` как допустимый Telegram update type;
- не относить его к обычному user message;
- передавать payload в normalizer тем же путём, что и другие inbound Telegram events, кроме уже существующего special-case для `callback_query`.

### Identity contract

Для private Telegram `my_chat_member` система должна использовать тот же identity contract, что и для обычных private inbound-сообщений, чтобы unsubscribe event привязывался к тому же `ContactIdentity` и `Dialog`.

В реализации обязательно нужно:

- явно зафиксировать точное поле Telegram payload, из которого берётся `external_user_id`;
- проверить, что оно консистентно с текущей нормализацией обычного private inbound;
- не допустить расхождения между identity contract для `message` и `my_chat_member`.

### Поддерживаемые переходы

Нормализация строится по переходу `old_chat_member.status -> new_chat_member.status`, а не только по финальному статусу.

Поддерживаемые переходы первой версии:

- `member -> kicked` => `bot_blocked_by_user`
- `kicked -> member` => `bot_unblocked_by_user`

Все остальные переходы:

- не создают `Message`;
- не меняют blocked-state;
- не влияют на sendability диалога.

## Поведение при неизвестном identity/dialog

Первая версия использует безопасное правило:

- unsubscribe system event не должен создавать новый `Contact`, если по событию невозможно надёжно резолвить уже существующий `ContactIdentity` или `Dialog`;
- если существующий identity/dialog не найден, событие игнорируется без создания нового контакта;
- допустимо логирование такого случая в channel activity log как диагностического события;
- создание “пустого” контакта только ради blocked/unblocked system event в scope первой версии не входит.

## Правила сохранения и обновления состояния

При сохранении корректно распознанного unsubscribe event система должна:

- создать inbound `Message` с `message_kind = inbound_system_event`;
- проставить `sent_by_type = system`;
- проставить `sent_by_system_code = telegram_bot_subscription`;
- привязать сообщение к существующему `Dialog`;
- обновить `Dialog.bot_subscription_*` по правилам stale/out-of-order;
- записать `bot_subscription_source_message_id` как ссылку на сообщение-источник.

## Out-of-order и stale events

- Событие blocked/unblocked может прийти не по порядку относительно уже сохранённого unsubscribe history.
- Любое корректно распознанное событие сохраняется в историю как `Message`.
- Поля `Dialog.bot_subscription_status`, `Dialog.bot_subscription_changed_at` и `Dialog.bot_subscription_source_message_id` обновляются только если текущее событие новее или равно текущему состоянию по времени.
- Если событие старее текущего `bot_subscription_changed_at`, оно не должно перетирать актуальное состояние диалога.
- При равенстве timestamps реализация должна использовать детерминированный tie-breaker, зафиксированный в коде и тестах.

## Поведение pipeline

### System event является `store-only`

Корректно распознанный unsubscribe event:

- сохраняется в историю;
- участвует в preview как системное событие;
- обновляет denormalized blocked-state на `Dialog`;
- не считается обычным пользовательским inbound;
- не запускает auto-reply;
- не запускает collector response;
- не запускает scenario start;
- не продолжает active scenario run;
- не участвует в `pending_auto_reply_source_message_id`;
- не идёт в Bitrix live export;
- не идёт в Bitrix history export;
- не участвует в missed inbound retry;
- не создаёт inbox-сигнал `Требует ответа`.

### Preview и inbox status

Preview и inbox status считаются разными проекциями:

- system event может стать последним сообщением диалога и использоваться как preview message;
- system event не должен участвовать в логике inbox status `Требует ответа`.

## Blocked-send логика

Система должна централизованно определять sendability диалога с учётом blocked-state.

### Route-status

`ResolveDialogRouteStatusAction` получает отдельный code для blocked dialog:

- `blocked_by_user`

Для blocked dialog route-status обязан возвращать:

- отдельный `label`;
- отдельный `tone`;
- точный `blockedReason`, отличный от generic route error.

### SQL-scopes

`DialogRoutePredicate` и SQL-scopes `whereRouteReady` / `whereRouteProblem` должны учитывать blocked-state и оставаться в parity с resolver.

### Guard contract

Каждый outbound path обязан:

- проверять актуальную sendability целевого `Dialog` непосредственно перед отправкой;
- опираться на current route-status целевого диалога, а не на предположение по source-message или устаревшему route context;
- при blocked-state не выполнять outbound-отправку и считать это корректным business block, а не transport failure.

Это правило распространяется на:

- manual reply;
- auto-reply;
- phone capture confirmation;
- data collection question;
- data collection completion;
- прочие collector follow-up send path;
- scenario outbound.

Реализация не должна оставлять прямые ветки `telegramBotApiService->sendTextMessage()` / `maxBotApiService->sendTextMessage()` без этого guard.

## UI и операторское поведение

### История диалога

System event отображается как системная плашка.

Тексты первой версии:

- `Клиент заблокировал бота`
- `Клиент разблокировал бота`

### Dialog page

- route-status показывает blocked-state отдельным статусом;
- reply composer показывает точную блокирующую причину;
- форма ручного ответа не должна позволять отправку в blocked dialog.

### Список диалогов и overview карточки контакта

- blocked dialog должен иметь явный route-status;
- preview последнего сообщения должен корректно показывать system event как системное, а не как обычное входящее от контакта;
- blocked dialog не должен ошибочно маркироваться как `Требует ответа` только из-за system event.

## Ключевые файлы реализации

### Webhook и normalizer

- `app/Http/Controllers/BotWebhookController.php`
- `app/Services/Bots/BotIncomingMessageNormalizer.php`
- `config/bots.php`

### Data model и storage

- `app/Data/Bots/IncomingBotMessage.php`
- `app/Models/Message.php`
- `app/Models/Dialog.php`
- `app/Services/Bots/StoreInboundMessageAction.php`
- migration files

### Route-status и read model

- `app/Services/Dialogs/ResolveDialogRouteStatusAction.php`
- `app/Services/Dialogs/DialogRoutePredicate.php`
- `app/Services/Dialogs/ApplyDialogRoutePredicateAction.php`
- `app/Services/Dialogs/BuildConversationFeedViewDataAction.php`
- `app/Services/Dialogs/LoadContactDialogsOverviewAction.php`
- `app/Filament/Resources/Dialogs/DialogResource.php`

### Outbound guard

- `app/Services/Bots/SendManualDialogReplyAction.php`
- `app/Services/Bots/BotAutoReplyService.php`
- `app/Jobs/ProcessPhoneCaptureFollowUpJob.php`
- `app/Jobs/ProcessDataCollectionQuestionJob.php`
- `app/Jobs/ProcessDataCollectionResponseJob.php`
- `app/Services/Scenarios/WarmupScenario.php`
- `app/Services/Scenarios/NeedsDiscoveryScenario.php`
- `app/Services/Scenarios/GenericDbScenarioRuntime.php`

### UI

- `resources/views/filament/contacts/partials/conversation-chat.blade.php`
- `resources/views/filament/contacts/partials/contact-dialogs.blade.php`
- `resources/views/filament/dialogs/partials/reply-composer.blade.php`

## Разбиение на slices

### Slice 1

- `my_chat_member` subscription;
- normalizer;
- schema changes;
- store system event;
- update `Dialog.bot_subscription_*`.

### Slice 2

- history / preview / route-status / read-path;
- parity route scopes.

### Slice 3

- единый blocked-send guard;
- перевод всех текущих outbound path на общий sendability check;
- тесты на блокировку отправки во всех актуальных ветках.

### Slice 4

- regression sweep по pipeline-ограничениям;
- negative tests;
- stale / out-of-order tests;
- unknown identity tests.

## Тестовая стратегия

Покрывать изменённый слой и точки интеграции.

### Обязательные тесты normalizer

- `BotIncomingMessageNormalizerTest`
  - private `my_chat_member` blocked transition;
  - private `my_chat_member` unblocked transition;
  - unsupported transitions ignored;
  - non-private updates ignored;
  - bot-originated updates ignored.

### Обязательные тесты storage

- `StoreInboundMessageActionTest`
  - сохранение `inbound_system_event`;
  - корректный `system_event_code`;
  - корректный `sent_by_type` и `sent_by_system_code`;
  - обновление `Dialog.bot_subscription_*`;
  - idempotent replay по `provider_event_key`.

### Обязательные route tests

- `ResolveDialogRouteStatusActionTest`
  - blocked dialog => non-sendable;
  - отдельный code / label / blockedReason;
  - unblock снимает blocked-state.
- `DialogRoutePredicateParityTest`
  - `whereRouteReady` совпадает с resolver;
  - `whereRouteProblem` совпадает с resolver;
  - blocked dialogs попадают в problem set.

### Обязательные outbound guard tests

- manual reply не отправляется в blocked dialog;
- auto-reply не отправляется в blocked dialog;
- phone capture confirmation не отправляется в blocked dialog;
- collector outbound не отправляется в blocked dialog;
- scenario outbound не отправляется в blocked dialog.

### Negative tests

- system event не запускает auto-reply;
- system event не запускает collector;
- system event не запускает scenario start / continue;
- system event не идёт в Bitrix export / retry.

### Unknown identity tests

- unsubscribe event без существующего identity/dialog не создаёт новый `Contact`.

### Out-of-order tests

- stale blocked event не перетирает более новый unblock state;
- stale unblock event не перетирает более новый blocked state.

### UI structural tests

- history bubble / system label;
- preview sender / system preview text;
- blocked reason в composer;
- blocked route-status в overview / list;
- inbox status не становится `Требует ответа` из-за system event.

## Критерии приёмки

- Telegram blocked/unblocked событие сохраняется в историю.
- После blocked event соответствующий `Dialog` становится non-sendable.
- После unblock event blocked-state снимается.
- Оператор видит blocked-state в истории, preview и route-status.
- Ни один текущий bot outbound path не пытается отправить сообщение в blocked dialog.
- System event не создаёт ложный статус `Требует ответа`.
- SQL-scopes route readiness не расходятся с runtime resolver.
- Повторный webhook не создаёт дубли system event и не ломает blocked-state.
- Out-of-order unsubscribe events не ломают актуальный blocked-state диалога.
- Unsubscribe event без существующего route context не создаёт новый пустой контакт.
- Blocked-state не завершает автоматически collector/scenario state и не меняет их статус сам по себе.

## Известные компромиссы

- Первая версия зависит от webhook `my_chat_member` как от primary source of truth.
- Если webhook был пропущен, система первой версии не обязана сама восстанавливать blocked-state по `403` от Telegram API.
- Поддерживаются только явно зафиксированные blocked/unblocked transitions первой версии.
- В первой версии unsubscribe event без уже существующего identity/dialog игнорируется, а не создаёт новую сущность.
- Blocked-state в первой версии является route-level ограничением и не меняет статусы collector/scenario автоматически.
- При равенстве времени событий нужен детерминированный tie-breaker, который должен быть зафиксирован в коде и тестах.

## Слабые места, которые нельзя размывать при реализации

- Нельзя смешивать unsubscribe system event с обычным `inbound_user`.
- Нельзя обновлять `Dialog.bot_subscription_*` без stale/out-of-order protection.
- Нельзя создавать новый `Contact` только ради blocked/unblocked event без отдельного согласования.
- Нельзя оставить хотя бы один текущий outbound path без blocked-send guard.
- Нельзя допустить расхождение между runtime resolver и SQL-scopes `whereRouteReady` / `whereRouteProblem`.
- Нельзя превратить общий blocked-send guard в преждевременный generic provider interface.
