# ТЗ: единый flow параметрического автоответа после квалификации контакта и готовности Bitrix24/Open Lines

## Цель

Сделать единый flow для клиента, пришедшего по ссылке с параметром, чтобы:
- параметр не терялся, если в момент входа контакт ещё не готов к финальному автоответу;
- финальный автоответ по параметру отправлялся только тогда, когда он сможет попасть в Bitrix24 Open Lines;
- если контакт уже готов, автоответ отправлялся сразу, без лишнего ожидания.

## Проблема

Сейчас параметр входа сохраняется, но финальный шаг по нему не выполняется после phone capture и анкеты. В результате:
- parameter-based правило может не сработать при первом входе;
- после квалификации параметр повторно не используется;
- если отправить финальный автоответ до готовности Bitrix/Open Lines, нет надёжной гарантии, что он попадёт в открытую линию.

## Главное продуктовое правило

У parameter-driven inbound есть два допустимых пути:

1. `Immediate path`
Если в момент входа контакт уже готов к финальному parameter auto-reply, автоответ отправляется сразу.

2. `Deferred path`
Если в момент входа контакт ещё не готов, система сохраняет исходное inbound-сообщение как pending-source и отправляет финальный автоответ позже, когда финальный gate будет выполнен.

## Финальный gate

Финальный parameter auto-reply можно отправлять только если одновременно:
- контакт квалифицирован;
- у контакта есть телефон;
- активная анкета завершена;
- `Bitrix24 contact sync` успешен;
- диалог готов к Open Lines live export.

Проверка телефона всегда выполняется по `Contact`.

Проверка live-ready должна опираться на существующую бизнес-логику готовности live bridge.

## Что считается квалифицированным контактом

Контакт считается квалифицированным, если:
- у него есть хотя бы один телефон;
- collector не активен;
- следующий обязательный шаг анкеты отсутствует.

## Сценарии поведения

### Сценарий 1. Контакт ещё не квалифицирован

- Клиент приходит с параметром.
- Возможен immediate `missing_phone` ответ, как сейчас.
- Исходный parameter-driven inbound сохраняется как pending-source.
- После квалификации, успешного sync и прохождения post-sync gate отправляется финальный автоответ.

### Сценарий 2. Контакт уже квалифицирован, но ещё не synced

- Pending-source сохраняется.
- После успешного sync и прохождения post-sync gate отправляется финальный автоответ.

### Сценарий 3. Контакт уже готов

Если в момент входа:
- контакт уже квалифицирован;
- `Bitrix24 contact sync` уже успешен;
- диалог live-ready;
- правило может сработать в текущем обычном auto-reply flow;

то финальный parameter auto-reply отправляется сразу, без pending.

## Границы

### Что меняется

- pending-state для parameter-driven inbound на уровне `Dialog`;
- единая логика выбора `immediate` или `deferred`;
- delayed final auto-reply после успешного post-sync хвоста;
- parameter-only matching для delayed final flow;
- merge/consolidation логика для pending-state;
- тесты.

### Что не меняется

- `contact_start_tags` остаётся audit-only;
- текущий phone capture flow;
- текущая collector-логика;
- текущий immediate auto-reply flow для уже готовых контактов;
- подтверждённый Bitrix24 happy-path вне этого сценария;
- админка и UI;
- новые сущности не добавляются.

## Изменение данных

Добавить в `dialogs` одно поле:
- `pending_auto_reply_source_message_id nullable`

Смысл поля:
- хранит `id` последнего inbound-сообщения с параметром, которое нужно обработать как финальный parameter auto-reply;
- source of truth по самому параметру остаётся в `messages.message_parameter`;
- при удалении source message значение очищается.

## Почему pending-state живёт в `Dialog`

- параметр привязан к конкретному каналу и thread;
- `Dialog` уже является route-context сущностью;
- один `Contact` может иметь несколько каналов и диалогов;
- eligibility по телефону и анкете остаётся на `Contact`, но pending-source принадлежит именно `Dialog`.

## Источник параметра

- source of truth по параметру: `messages.message_parameter`;
- `contact_start_tags` используется только как исторический след;
- pending-state не строится на `contact_start_tags`.

## Правило записи pending

Pending записывается, если одновременно:
- сообщение является клиентским inbound;
- у него есть `message_parameter`;
- сообщение уже привязано к `Dialog`;
- полный финальный gate не выполнен.

Наличие или отсутствие подходящего final-rule в момент записи pending не проверяется. Проверка rule set выполняется позже, в момент финальной отправки.

## Когда pending не записывается

Pending не записывается, если:
- `message_parameter` отсутствует;
- в момент входа уже выполнен полный финальный gate и финальный parameter auto-reply отправляется сразу;
- source message уже имеет успешный auto reply.

## Правило перезаписи pending

В одном `Dialog` хранится только один последний pending-source.

Новый source message заменяет старый только если он новее по правилу:
1. сначала `received_at`;
2. при равенстве или отсутствии `received_at` fallback по `message.id`.

Это нужно, чтобы поздно пришедшее старое inbound-сообщение не затёрло более новый pending.

## Duplicate-safe и out-of-order поведение

- Повторный webhook на уже сохранённое inbound-сообщение не должен слепо переписывать pending.
- Старый duplicate не должен затирать более новый pending-source.
- MAX out-of-order inbound не должен ломать выбор актуального pending-source.

## Immediate-success cleanup

Если пришёл новый parameter-driven inbound и он уже проходит по immediate path:
- stale pending для этого `Dialog` должен быть очищен или перезаписан этим же source;
- после этого в `Dialog` не должно оставаться старого pending, который позже может породить устаревший delayed reply.

## Что делают phone capture и collector

Phone capture и collector не отправляют delayed final parameter reply напрямую.

Их роль:
- не ломать текущий flow;
- довести контакт до qualification-ready;
- запустить существующий путь к Bitrix sync там, где он уже предусмотрен.

## Post-sync orchestration

Это критичная часть задачи.

После успешного `Bitrix24 contact sync` порядок должен быть таким:

1. Контакт получает успешный sync state.
2. Для соответствующего dialog/channel выполняется post-sync retry пропущенных inbound-сообщений в Open Lines, если они нужны именно этому `Dialog`.
3. Только после завершения relevant retry для данного `Dialog` можно запускать delayed final parameter auto-reply.
4. Если relevant retry для `Dialog` не нужен, delayed reply можно ставить сразу после успешного sync.

Недостаточно просто поставить retry и delayed reply в очередь подряд. Delayed final reply не должен появляться в Open Lines раньше relevant пользовательских inbound для того же `Dialog`.

## Единственная группа событий для старта deferred final reply

Deferred final reply не стартует:
- ни из `ProcessPhoneCaptureFollowUpJob`;
- ни из обычного completion collector-а;
- ни из terminal skip collector-а.

Его запуск возможен только как часть post-sync orchestration:
- либо сразу после успешного sync для `Dialog` без relevant retry;
- либо после completion relevant `retryAfterSync` live export для того же `Dialog`.

## Delayed final flow

### Общая логика

Для `Dialog` с pending:
- если relevant retry не нужен, delayed reply можно поставить сразу после successful sync;
- если relevant retry нужен, delayed reply ставится только после successful completion этого retry-export для того же `Dialog`.

### Новый job

Нужен отдельный job:
- `ProcessDeferredParameterAutoReplyJob(dialogId)`

Он не переиспользует `ProcessAutoReplyJob`.

### Требования к delayed job

Job должен:
- иметь `WithoutOverlapping` по `dialog_id`;
- загружать `Dialog`, root `Contact`, source inbound `Message`, channel и route context;
- повторно проверять финальный gate на момент выполнения;
- уважать существующие глобальные gate'ы автоответов контакта;
- работать только с одним финальным rule;
- очищать pending только условно, если `pending_auto_reply_source_message_id` всё ещё указывает на тот же source message.

### Early-exit условия

Job завершает работу без отправки, если:
- pending пуст;
- source message не найден;
- у source message пустой `message_parameter`;
- source message уже имеет `auto_reply_sent_at`;
- финальный gate не выполнен;
- auto reply отключён у контакта.

Если source message уже имеет `auto_reply_sent_at`:
- повторный delayed reply не отправляется;
- pending очищается условно как устаревший.

### Rule resolution

Delayed final flow использует отдельный узкий matcher.

Он:
- использует `message_parameter` из source inbound;
- возвращает один выбранный final rule или `null`;
- если matched rules несколько, выбирает первое по текущему порядку resolver-а.

### Какие правила допускаются

Delayed final flow участвует только для правил:
- parameter-scoped;
- `contact_phone_condition = has_phone`.

Он не матчится на:
- `ANY_INBOUND`;
- `EXACT_KEYWORD`;
- text-only scopes;
- `missing_phone`;
- `null`.

### Ключевое ограничение

Delayed final flow отправляет только **одно** финальное правило.

Это сознательное продуктовое решение:
- задача про один финальный ответ по параметру;
- delayed flow не является replay всех matched rules;
- это упрощает идемпотентность и убирает частичный success внутри цикла.

### Send path

Для delayed final flow нужен узкий send-path, который:
- отправляет один уже выбранный rule;
- использует source inbound как `reply_to_message_id`;
- использует существующий outbound auto-reply store path;
- сохраняет outbound как `outbound_auto_reply`;
- ставит live export через существующую механику.

## Очистка pending

Pending очищается только в двух случаях:
- финальный delayed reply успешно отправлен;
- финальный rule не найден.

Pending не очищается:
- при transport failure;
- если финальный gate на момент выполнения delayed job ещё не выполнен.

Любая очистка pending должна быть условной:
- очищать только если `Dialog.pending_auto_reply_source_message_id` всё ещё равен тому source message, который обрабатывал job.

## Merge / consolidation

Если при merge или consolidation есть несколько pending-source для связанных `Dialog`, нужно сохранить более новый pending-source.

Правило выбора:
- сравнение по `messages.received_at`;
- fallback по `messages.id`.

Цель:
- после merge выживает последний актуальный parameter-driven inbound.

## Логирование

Минимально достаточно следующих событий:
- `dialog.pending_parameter_captured`
- `dialog.pending_parameter_reply_queued_after_sync`
- `dialog.pending_parameter_reply_sent`
- `dialog.pending_parameter_cleared`

Опционально:
- `dialog.pending_parameter_no_rule`

## Тестовая стратегия

Тестировать только реально изменяемый слой.

Обязательные кейсы:
- inbound с параметром и неготовым контактом записывает pending;
- inbound с параметром при qualified, но not-synced контакте записывает pending;
- inbound с параметром при qualified + synced + live-ready контакте отправляет final reply сразу;
- immediate `missing_phone` path не ломается;
- delayed flow стартует только после успешного post-sync хвоста;
- delayed flow не стартует раньше relevant post-sync inbound retry;
- delayed flow матчит только `EXACT_PARAMETER` и `EXACT_TEXT_OR_PARAMETER`;
- delayed flow не матчит `ANY_INBOUND`;
- delayed flow не матчит `EXACT_KEYWORD`;
- delayed flow не матчит `missing_phone`;
- delayed flow не матчит `null` phone-condition;
- delayed flow отправляет только одно финальное правило;
- новый inbound с параметром перезаписывает старый pending;
- поздний старый inbound не затирает более новый pending;
- duplicate webhook не ломает pending;
- новый inbound, который проходит immediate path, не оставляет stale pending;
- если source уже имеет `auto_reply_sent_at`, delayed reply повторно не отправляется;
- при `no_rule` pending очищается;
- при transport failure pending не очищается;
- параллельный запуск delayed job не создаёт дубль;
- merge сохраняет более новый pending-source;
- outbound после delayed reply проходит через существующий Open Lines export path;
- в Open Lines delayed reply не появляется раньше relevant missed inbound retry для того же `Dialog`.

## Критерии приёмки

- параметрический inbound не теряется до завершения всей qualification/sync цепочки;
- если контакт уже готов, финальный parameter auto-reply отправляется сразу;
- если контакт ещё не готов, финальный parameter auto-reply отправляется только после успешного post-sync хвоста;
- финальный parameter auto-reply попадает в Open Lines через существующий live bridge;
- pending-state хранится на `Dialog`;
- телефон и qualification проверяются на `Contact`;
- в одном `Dialog` хранится только один последний pending-source;
- delayed final flow не переотправляет generic и text-based правила;
- delayed final flow отправляет только один финальный reply;
- delayed final flow не создаёт дубли;
- merge не теряет актуальный pending-source;
- порядок сообщений в Open Lines не ломается delayed reply раньше post-sync retry пользовательских inbound.

## Известные компромиссы

- в `Dialog` хранится только один последний pending-source;
- delayed final flow поддерживает только parameter-scoped rules с `has_phone`;
- delayed final flow не покрывает `ANY_INBOUND`, `EXACT_KEYWORD` и text-only правила;
- если sync, live-ready или post-sync retry не достигают нужного состояния, pending остаётся до следующей возможности или ручного решения;
- UI-отображение pending-state в админке в этот шаг не входит.
