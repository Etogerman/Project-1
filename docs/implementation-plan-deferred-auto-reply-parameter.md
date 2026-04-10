# План реализации: deferred parameter auto-reply

Этот документ описывает техническое разбиение реализации на слайсы после согласования итогового ТЗ.

## Слайс 0. Preflight по post-sync orchestration

Статус: завершён read-only.

Зафиксировано:
- delayed reply нельзя запускать просто после `SyncContactToBitrix24Job`;
- continuation должен быть двухветочным:
  - `retry_not_required` для конкретного `Dialog` -> delayed reply можно ставить сразу после sync;
  - `retry_required` -> delayed reply стартует только после успешного `retryAfterSync` live export для того же `Dialog`.

## Слайс 1. Pending-source на `Dialog`

Цель:
- безопасно хранить актуальный parameter-driven source inbound, если финальный gate ещё не выполнен.

Изменения:
- добавить `dialogs.pending_auto_reply_source_message_id`;
- писать pending после `syncStoredInboundMessageMetadata()` и до live export queue во всех ветках `StoreInboundMessageAction`;
- писать pending только для client inbound с `message_parameter`, если full final gate не выполнен;
- не писать pending для immediate-ready case;
- не писать pending для source message с уже заполненным `auto_reply_sent_at`;
- переписывать pending только если новый source новее по `received_at`, fallback по `id`;
- duplicate и out-of-order inbound не должны затирать более новый pending;
- immediate-success inbound должен очищать stale pending.

Критерий выхода:
- `Dialog` всегда хранит только актуальный pending-source.

## Слайс 2. Delayed matcher

Цель:
- добавить отдельный matcher для delayed final flow, не меняя current immediate flow.

Изменения:
- отдельный parameter-only path;
- matcher возвращает не коллекцию для отправки, а один выбранный final rule или `null`;
- матчить только `EXACT_PARAMETER` и `EXACT_TEXT_OR_PARAMETER`;
- учитывать только `contact_phone_condition = has_phone`;
- не матчить `ANY_INBOUND`, `EXACT_KEYWORD`, text-only scopes, `missing_phone`, `null`;
- если matched rules несколько, выбирать первое по текущему порядку resolver-а.

Критерий выхода:
- delayed flow имеет свою безопасную single-rule semantics.

## Слайс 3. Per-dialog retry decision

Цель:
- для каждого `Dialog` с pending понять, нужен ли relevant missed inbound retry именно ему.

Изменения:
- добавить helper/action в `app/Services/Bitrix24/`, который по `dialog_id` отвечает:
  - `retry_required`
  - `retry_not_required`
- решение должно приниматься на уровне `Dialog`, а не `Contact`;
- retry в другом диалоге того же контакта не должен влиять на текущий dialog.

Критерий выхода:
- для любого pending dialog система знает, ждать retry completion или нет.

## Слайс 4. Post-sync continuation

Цель:
- встроить delayed reply в правильный порядок `sync -> relevant retry -> delayed reply`.

Изменения:
- после успешного sync найти pending dialogs root-контакта;
- для каждого pending dialog вызвать per-dialog retry decision;
- если `retry_not_required`, ставить delayed job сразу;
- если `retry_required`, delayed job не ставить сразу;
- continuation делать из completion-path live export:
  - только если `retryAfterSync = true`;
  - только для того же `dialog_id`;
  - только если у dialog ещё есть pending.

Критерий выхода:
- delayed reply не может попасть в Open Lines раньше relevant missed inbound этого dialog.

## Слайс 5. Delayed final reply job

Цель:
- реализовать строго идемпотентный delayed send одного финального parameter reply.

Изменения:
- новый `ProcessDeferredParameterAutoReplyJob(dialogId)`;
- `WithoutOverlapping` по `dialog_id`;
- загрузка `Dialog`, root `Contact`, source `Message`, route context;
- early-exit если:
  - pending пуст;
  - source не найден;
  - параметр пуст;
  - source уже имеет `auto_reply_sent_at`;
  - final gate не выполнен;
  - auto reply disabled у контакта;
- delayed matcher возвращает один final rule или `null`;
- если `null`, pending очищается условно;
- если rule найден, отправляется один reply;
- send-path должен отправлять один уже выбранный rule, а не выполнять multi-rule loop;
- использовать existing outbound store/export path;
- pending очищать только при success или `no_rule`;
- при transport failure pending не очищать;
- очистка pending только условная: если `pending_auto_reply_source_message_id` всё ещё равен обработанному source message.

Критерий выхода:
- delayed flow отправляет ровно один final reply и не даёт дублей.

## Слайс 6. Merge / consolidation

Цель:
- не потерять pending-source при merge.

Изменения:
- при consolidation сохранять более новый pending-source;
- сравнение по `messages.received_at`, fallback по `messages.id`.

Критерий выхода:
- merge не теряет актуальный pending и не откатывает его на старое сообщение.

## Слайс 7. Сквозные регрессии

Цель:
- проверить всю задачу end-to-end.

Сценарии:
- parameter inbound без телефона -> qualification -> sync -> retry/no-retry -> delayed final reply;
- parameter inbound при qualified, но not-synced контакте -> sync -> delayed final reply;
- parameter inbound при already synced + live-ready контакте -> immediate final reply;
- `missing_phone` immediate path не ломается;
- delayed reply не обгоняет relevant inbound retry в Open Lines;
- stale pending не уходит после более нового immediate-success inbound;
- duplicate и out-of-order inbound не ломают pending;
- delayed flow не создаёт дублей.

## Рекомендуемый порядок реализации

1. Слайс 1
2. Слайс 2
3. Слайс 3
4. Слайс 4
5. Слайс 5
6. Слайс 6
7. Слайс 7

Слайс 0 уже закрыт read-only preflight-ом.

## Короткая суть плана

- Сначала фиксируется state на `Dialog`.
- Потом фиксируется single-rule delayed matching.
- Потом решается per-dialog post-sync orchestration.
- Только после этого добавляется delayed send.
- Merge и end-to-end проверки идут в конце.
