# ТЗ: Bitrix24 Open Lines bridge и blocked dialog

## Цель

Привести inbound bridge `Bitrix24 Open Lines -> messenger` к тому же
route-level контракту, что уже действует для bot runtime после
`Telegram unsubscribe`.

После завершения шага система должна:

- не пытаться отправлять Open Lines inbound-сообщение в `Dialog`
  со статусом `blocked_by_user`;
- не трактовать blocked dialog как transport failure;
- не переводить `bitrix24_live_status` в `failed` только из-за
  route-block;
- сохранять текущий happy-path для sendable dialog без изменений.

## Проблема

Сейчас live bridge Bitrix24 проверяет только готовность
`Dialog` к Open Lines happy-path, но не проверяет sendability маршрута.

Из-за этого операторское сообщение из Bitrix24 всё ещё может:

- уйти в уже заблокированный Telegram dialog;
- получить provider error;
- ошибочно перевести `bitrix24_live_status` в `failed`,
  хотя для остального runtime такой диалог уже считается
  `not sendable`.

## Текущий runtime и место изменения

Точка проблемы находится в inbound bridge из Bitrix24 Open Lines:

- `app/Services/Bitrix24/DeliverBitrix24OpenLinesMessageToMessengerAction.php`
- `app/Services/Bitrix24/ProcessBitrix24OpenLinesWebhookAction.php`
- `app/Services/Bitrix24/IsDialogReadyForBitrix24LiveBridgeAction.php`

Текущий общий sendability contract уже существует в bot runtime:

- `app/Services/Bots/SendBotDialogTextAction.php`
- `app/Services/Dialogs/ResolveDialogRouteStatusAction.php`

Тестовая точка входа:

- `tests/Feature/Bitrix24OpenLinesInboundBridgeTest.php`

## Границы

### Что меняется

- delivery path Open Lines inbound -> Telegram/MAX;
- webhook processor Open Lines для inbound operator message;
- feature tests для blocked dialog в inbound bridge.

### Что остаётся как есть

- текущий подтверждённый Bitrix24 Open Lines happy-path;
- `IsDialogReadyForBitrix24LiveBridgeAction` как readiness-check;
- route-status resolver и `Telegram unsubscribe` contract;
- логика replay после unblock;
- существующий export path `messenger -> Bitrix24`.

### Вне scope

- новый Bitrix24 transport path;
- replay suppressed operator messages после unblock;
- расширение PR `Telegram unsubscribe`;
- docs/workflow/process fixes;
- `merge`, `rebase`, `deploy`, публикация изменений.

## Архитектурные решения

- `IsDialogReadyForBitrix24LiveBridgeAction` остаётся проверкой
  live-bridge readiness, а не вторым route resolver.
- Фактическая отправка из Open Lines должна проходить через
  уже существующий sendability gate, а не напрямую через
  `TelegramBotApiService` / `MaxBotApiService`.
- Рекомендованный путь: использовать
  `SendBotDialogTextAction` или тонкий Bitrix24-wrapper поверх него.
- `blocked_by_user` трактуется как business skip, а не как
  transport exception.

## Требуемое поведение

### Если dialog sendable

- bridge отправляет сообщение в messenger;
- сохраняет outbound `Message`;
- выполняет Bitrix acknowledgement;
- поддерживает текущий happy-path без изменений.

### Если dialog `blocked_by_user`

- provider transport не вызывается;
- outbound `Message` как доставленный не сохраняется;
- `bitrix24_live_status` не переводится в `failed`;
- webhook event не считается hard failure transport-слоя;
- пишется отдельный sync/activity log со skipped reason
  `blocked_by_user`.

### Если transport реально упал

- текущий failure path сохраняется;
- `bitrix24_live_status = failed` остаётся допустимым;
- event остаётся `failed`.

## Семантика статуса webhook event

Рекомендация для blocked-case:

- считать событие `processed/skipped`, а не `failed`.

Причина:

- blocked dialog — это бизнес-ограничение маршрута;
- transport не вызывался;
- callback обработан корректно, просто доставка не разрешена.

## Границы хранения данных

В этом шаге система не должна:

- создавать outbound `Message`, если физическая доставка не состоялась;
- отправлять acknowledgement в Bitrix как будто сообщение доставлено;
- делать replay suppressed operator messages после unblock.

## Тестовая стратегия

Нужны таргетные feature-тесты для inbound bridge:

- blocked Telegram dialog:
  - HTTP в Telegram не вызывается;
  - event не `failed`;
  - `bitrix24_live_status` не `failed`;
- blocked MAX dialog:
  - HTTP в MAX не вызывается;
  - тот же статусный контракт;
- обычная transport error:
  - event `failed`;
  - dialog `failed`;
- duplicate callback:
  - не ломается после нового skipped path.

Дополнительно желательно проверить:

- отдельную log/sync-log operation для skipped-case;
- отсутствие outbound `messages` при suppressed delivery.

## Критерии приёмки

- Open Lines inbound bridge уважает `blocked_by_user`;
- suppressed delivery не делает ложный `bitrix24_live_status = failed`;
- happy-path sendable dialog не меняется;
- transport failures и route-blocked cases разведены явно;
- поведение закреплено feature-тестами.

## Известные компромиссы

- replay suppressed Bitrix operator messages после unblock
  не входит в этот шаг;
- шаг не расширяет Bitrix24 happy-path, а только делает его
  безопаснее относительно нового route gate;
- текущий PR `Telegram unsubscribe` не меняется и не расширяется
  этим follow-up.

## Рекомендуемое разбиение на slices

### Slice 1

- sendability-aware delivery path для Open Lines inbound.

### Slice 2

- разведение `skipped_not_sendable` и `failed_transport`
  в webhook processor.

### Slice 3

- таргетные tests для blocked Telegram/MAX dialog.
