# ТЗ: Bitrix24 Open Lines two-stage dedupe для echo-callback после `crm.message.add`

## 1. Контекст

В `phase 1a` manual reply из Abrikosoff:
1. уходит клиенту напрямую;
2. зеркалится в Bitrix через `imopenlines.crm.message.add`;
3. возвращается callback-ом `OnSendMessageCustom` / `OnImConnectorMessageAdd`;
4. текущий inbound bridge воспринимает этот callback как новое операторское сообщение;
5. повторно доставляет его клиенту и создаёт локальный дубль.

`no-callback model` на `staging` опровергнута.
Нужен dedupe-guard в inbound Open Lines path.

## 2. Цель

Сделать так, чтобы callback, порождённый нашим же `manual reply` export через `imopenlines.crm.message.add`:
1. не отправлялся клиенту повторно;
2. не создавал новый local `Message`;
3. корректно ack-ался в Bitrix;
4. не ломал реальные operator replies, написанные в самом Bitrix.

## 3. Scope

Входит:
1. только `manual reply`, экспортированный через `imopenlines.crm.message.add`;
2. только inbound Open Lines callbacks:
   - `OnSendMessageCustom`
   - `OnImConnectorMessageAdd`;
3. только inbound dedupe / anti-race / ack behavior;
4. только `staging` stream.

Не входит:
1. `auto reply`;
2. `collector message`;
3. `system event`;
4. `exact operator`;
5. смена transport path;
6. `production`;
7. UI changes.

## 4. Source tree

Source of truth для этого slice:
1. `origin/staging`;
2. staging-based lineage, где уже есть `phase 1a`;
3. не локальный `main`.

Все implementation ветки и review для этого slice стартуют от `origin/staging`.

## 5. Общая модель

Dedupe должен быть двухступенчатым:
1. **Stage A: exact echo match**
2. **Stage B: delayed anti-race recheck**

Главный safety principle:
1. лучше пропустить echo и получить дубль;
2. чем ошибочно заглушить реальное сообщение оператора из Bitrix.

## 6. Stage A — exact echo match

### 6.1. Правило exact match

Если входящий callback `bitrixMessageId` совпадает с:
- `bitrix24_message_exports.bitrix_remote_message_id`

для существующего local `manual reply`, то callback считается **точным echo**.

### 6.2. Ограничение области exact match

Lookup должен быть жёстко ограничен:
1. `bitrix24_message_exports.export_mode = live`
2. `transport_method = imopenlines.crm.message.add`
3. связанное сообщение:
   - `message_kind = KIND_OUTBOUND_MANUAL_REPLY`
   - `direction = outbound`
   - принадлежит текущему `dialog`

### 6.3. Поведение на exact echo

Для exact echo система должна:
1. не вызывать доставку в Telegram/MAX;
2. не создавать новый local `Message`;
3. не вызывать `StoreBitrix24OpenLinesOutboundMessageAction`;
4. взять `external_message_id` уже существующего local `manual reply`;
5. отправить ack в Bitrix;
6. завершить event как `processed`.

## 7. Stage B — delayed anti-race recheck

### 7.1. Проблема race

Callback может прийти раньше, чем export path успел локально сохранить `bitrix_remote_message_id`.

### 7.2. Echo-candidate

Если exact match не найден, callback считается **suspicious echo-candidate** только если одновременно выполнено всё:

1. `chat.id` указывает на наш dialog (`abrikosoff-dialog:<id>`);
2. в dialog есть local outbound `manual reply`;
3. candidate — **ровно один**;
4. candidate — самый свежий outbound `manual reply` в dialog;
5. у candidate есть `bitrix24_message_exports` record:
   - `export_mode = live`
   - `export_status = pending`;
6. candidate создан не старше **10 секунд** на момент обработки callback;
7. `text` callback-а совпадает с `text` candidate после нормализации.

### 7.3. Нормализация текста

Для text compare с обеих сторон использовать только:
1. приведение к string;
2. замена `\r\n` -> `\n`;
3. замена `\r` -> `\n`;
4. `trim()` по краям.

Запрещено:
1. lowercase;
2. fuzzy matching;
3. HTML-to-text;
4. схлопывание внутренних пробелов.

### 7.4. Candidate uniqueness

Если candidate count:
1. `0` -> это не suspicious echo;
2. `1` -> suspicious echo-candidate допустим;
3. `>1` -> suppression запрещён, callback идёт по обычному inbound path.

### 7.5. Delayed recheck

Для первого implementation slice зафиксировать:
1. `delayed recheck = 2 секунды`;
2. только один recheck;
3. без циклов и без дополнительных повторов.

### 7.6. Поведение на suspicious echo-candidate

На первом suspicious pass система не должна:
1. доставлять сообщение клиенту;
2. создавать новый local `Message`;
3. отправлять ack;
4. переводить event в `processed`;
5. переводить event в `ignored`;
6. ронять dialog в `failed`.

Вместо этого система должна:
1. оставить event в `pending`;
2. запланировать один delayed recheck;
3. не писать integration failure.

### 7.7. Результат delayed recheck

На delayed recheck:
1. если exact match появился -> confirmed echo, safe skip + ack;
2. если exact match не появился -> callback считается реальным Bitrix operator reply и идёт по обычному inbound path.

## 8. Recheck orchestration

### 8.1. Storage/model contract

Для этого slice не вводить новую таблицу.

Использовать существующую `bitrix24_webhook_events` с минимальным расширением:
1. `recheck_scheduled_at`
2. `recheck_attempted_at`

### 8.2. Status contract

Финальные статусы остаются:
1. `pending`
2. `processed`
3. `failed`
4. `ignored`

Новый отдельный status enum для recheck не вводить.

### 8.3. Anti-concurrency rule

Delayed recheck нельзя строить на голом `pending`.

Нужен явный контракт:
1. scheduling допускается только если:
   - `recheck_scheduled_at is null`
   - `recheck_attempted_at is null`;
2. повторное планирование того же event запрещено;
3. второй worker не должен обрабатывать такой event как fresh pending event;
4. delayed recheck может быть запущен только один раз.

## 9. Ack contract

### 9.1. Exact echo

Если callback признан exact echo:
1. повторной доставки клиенту нет;
2. нового local `Message` нет;
3. ack отправляется;
4. для ack используется `external_message_id` уже существующего local `manual reply`.

### 9.2. Suspicious pass

Если callback только suspicious echo-candidate:
1. ack не отправляется;
2. event не считается завершённым;
3. система ждёт delayed recheck.

### 9.3. Real Bitrix operator reply

Если после delayed recheck exact match не появился:
1. callback идёт по обычному inbound path;
2. delivery -> store -> ack.

### 9.4. Ack failure

Если ack для confirmed echo падает:
1. event считается `failed`;
2. повторная доставка клиенту всё равно не выполняется.

### 9.5. Ack-delay bound

Максимально допустимая ack-delay в этом slice:
1. один delayed window (`2 секунды`);
2. плюс queue overhead одного recheck job.

## 10. Ordering rule

При поиске самого свежего local `manual reply` использовать:
1. `coalesce(received_at, created_at)`
2. при равенстве — `id desc`

## 11. Точка изменения

Основная точка guard-а:
- `ProcessBitrix24OpenLinesWebhookAction`

Guard должен стоять до:
1. `DeliverBitrix24OpenLinesMessageToMessengerAction`
2. `StoreBitrix24OpenLinesOutboundMessageAction`

## 12. Что нельзя сломать

1. реальный Bitrix operator reply -> Telegram/MAX;
2. существующий duplicate callback guard по `provider_event_key`;
3. blocked-dialog path;
4. обычный ack path для реального inbound из Bitrix;
5. текущие `processed / failed / ignored` для прочих Open Lines callbacks.

## 13. Observability

Нужна отдельная диагностика минимум по:
1. `openlines_exact_echo_skipped`
2. `openlines_delayed_recheck_scheduled`
3. `openlines_delayed_recheck_confirmed_echo`
4. `openlines_delayed_recheck_fell_through`
5. `openlines_delayed_recheck_ack_failed`

## 14. Acceptance criteria

Slice считается успешным на `staging`, если подтверждено:
1. `manual reply` из Abrikosoff уходит клиенту ровно один раз;
2. это сообщение появляется в Bitrix;
3. echo-callback не создаёт новый local `Message`;
4. echo-callback не вызывает повторную доставку клиенту;
5. реальный operator reply, написанный в самом Bitrix, продолжает приходить клиенту;
6. существующий duplicate callback behavior не ломается;
7. blocked-dialog path не ломается.

## 15. Preconditions

Перед продолжением code stream:
1. `BITRIX24_OPENLINES_SERVICE_USER_ID` на `staging` остаётся выключенным до готовности slice;
2. реализация идёт только как `PR в staging`;
3. dangerous ops вне scope.

## 16. Текущий статус

1. `phase 1a` остаётся blocked без этого guard-а;
2. текущий `crm.message.add` path без two-stage dedupe непригоден для rollout;
3. этот документ — source of truth для дальнейшей реализации slice от `origin/staging`.
