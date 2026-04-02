# Bitrix24 Open Lines Box CRM Rebinding Spike

## Статус

Discovery завершён.
Выбранный box-side path позже был доведён до рабочего happy-path.

Текущее состояние по production smoke-check:

- `Contact Sync` работает
- `History Export` работает
- box-side custom connector registration работает
- `Open Lines` transport работает
- `Bitrix -> messenger -> local history -> ack` работает
- documented payload hints (`user.phone`, `user.last_name`) уже отправляются
- новый лид в последнем тестовом сценарии не создался
- на момент spike новая Open Lines сессия ещё не привязывалась к existing CRM contact

Финальный подтверждённый outcome после implementation phase:

- Telegram happy-path работает end-to-end
- MAX happy-path работает end-to-end
- existing CRM contact reuse подтверждён
- рабочие line ids:
  - Telegram `32`
  - MAX `31`

## Подтверждённые факты

### 1. Laravel-side payload больше не выглядит главным блокером

После `ТЗ-6.4.3b.2` live payload уже включает documented CRM hints:

- `MESSAGES[].user.phone`
- `MESSAGES[].user.last_name`
- `MESSAGES[].user.name`

При этом observed production behavior изменился так:

- новый лид в happy-path тесте больше не появился
- existing CRM contact остался существовать
- но Open Lines chat/session не attach-нулась к нему

Следствие:

- проблема уже не выглядит как отсутствие phone-based hints в payload
- remaining gap находится на стороне box-side CRM/session orchestration

### 2. Existing CRM contact и CRM data уже есть в Bitrix

По production inspection подтверждено:

- existing CRM contact уже существует
- в него уже попадает `Архив переписки Abrikosoff`
- значит contact sync и history export живут на нужной CRM-сущности

Следствие:

- Bitrix already knows this person as CRM contact
- remaining problem не в отсутствии CRM-данных как таковых
- remaining problem в том, что Open Lines session не reuse-ит этот existing contact context

### 3. Текущий box-side runtime не делает CRM/session reconciliation

Текущий box package:

- `/Users/abrikosov/Documents/Проект-1/bitrix-box/abrikosoff-openlines/local/php_interface/include/abrikosoff_openlines/src/Runtime.php`

Сейчас runtime:

- регистрирует connector-ы
- обрабатывает `OnInfoLine`
- форвардит `OnSendMessageCustom`
- форвардит `OnUpdateMessageCustom`
- форвардит `OnDeleteMessageCustom`

Но он не делает:

- lookup existing CRM contact по телефону
- inspection Open Lines session/chat CRM context
- post-create rebinding existing contact
- cleanup/replacement mistakenly created CRM context

Следствие:

- текущий box-side слой решает только transport/provider registration
- CRM binding сейчас действительно не покрыт ни Laravel-side, ни box-side кодом

## Что подтверждает официальная документация Bitrix

### 1. Box-side `Tracker` умеет стартовать диалог, уже привязанный к CRM entities

В официальной docs:

- `\Bitrix\ImOpenLines\Tracker::getMessengerLink($lineId, $connectorId, $crmEntities)`

Метод принимает массив CRM entities и возвращает messenger link, при переходе по которому диалог стартует уже с CRM context.

Источник:

- https://dev.1c-bitrix.ru/api_d7/bitrix/imopenlines/tracker/getmessengerlink.php

Следствие:

- box-side слой Bitrix в принципе умеет связывать Open Lines dialogue с конкретными CRM entities
- это не доказывает прямой rebinding existing incoming chat, но доказывает, что CRM attach на стороне `imopenlines` существует как capability

### 2. Box-side события `imopenlines` дают доступ к session/chat context

В docs события `OnBeforeSessionTransfer` видно, что в обработчик приходят:

- `session`
- `config`
- `chat` (`\Bitrix\ImOpenLines\Chat`)

Источник:

- https://dev.1c-bitrix.ru/api_help/imopenlines/events/onbeforesessiontransfer.php

Следствие:

- на стороне коробки существуют события жизненного цикла Open Lines session
- через box-side events можно получить доступ к chat/session context
- значит post-create fallback не противоречит общей модели модуля `imopenlines`

### 3. Официальные docs не дают явного documented API “привяжи existing contact к уже созданному incoming chat”

По найденным primary sources:

- есть documented путь старта messenger dialogue с CRM entities
- есть documented lifecycle events с access к session/chat

Но нет прямой, явно документированной страницы вида:

- “attach current incoming Open Lines chat to existing CRM contact”

Следствие:

- feasibility box-side workaround выглядит реальной
- но implementation path придётся подтверждать через box-side prototype и/или inspection internal services коробки

## Ответы на вопросы ТЗ-6.4.3b.3.1

### Можно ли в принципе сделать existing-contact rebinding в коробке

На текущем этапе: **похоже, да**.

Это вывод из двух подтверждённых фактов:

1. `imopenlines` already has box-side CRM attach semantics через `Tracker::getMessengerLink`
2. `imopenlines` lifecycle events дают доступ к `session` и `chat`

Этого достаточно, чтобы считать box-side rebinding **технически правдоподобным следующим шагом**, а не тупиковым направлением.

### Где наиболее вероятная точка реализации

Наиболее вероятный путь:

- **post-create box-side fallback**

То есть не пытаться ещё сильнее насыщать Laravel payload, а:

1. дать Bitrix создать/инициализировать Open Lines session
2. на box-side событии получить `session/chat` context
3. найти existing CRM contact по телефону
4. попытаться attach/reconcile session к этому contact

### Почему не стоит делать ещё один payload-only шаг

Потому что outcome `6.4.3b.2` уже показал:

- documented payload enrichment поменял CRM side-effect
- но не создал working existing-contact Open Lines binding

Следствие:

- дальнейшее усложнение Laravel-side payload без box-side evidence имеет низкую ожидаемую ценность

### Нужен ли следующий implementation step

Да.

На этом discovery этапе достаточно evidence, чтобы переходить к:

- `ТЗ-6.4.3b.3.2 / Box Rebinding Implementation`

Но следующий шаг должен начинаться не с “идеальной архитектуры”, а с минимального box-side prototype на тестовой линии.

## Что пока не доказано

Следующие пункты ещё не подтверждены:

- какой именно internal service или API в коробке делает rebinding
- можно ли сделать rebinding до создания session, а не только post-create
- можно ли полностью избежать промежуточных CRM side-effects в Bitrix
- как корректно вести себя при ambiguous phone match

## Рекомендованный implementation path

Следующий шаг стоит строить так:

1. В box-side runtime добавить минимальную диагностическую ветку для session/chat inspection.
2. Подтвердить, где в lifecycle уже доступен номер телефона и CRM context.
3. Реализовать минимальный rebinding prototype только для happy-path:
   - один contact found by phone
   - один new live dialog
4. Если prototype успешен:
   - закрепить его как основной fallback path
5. Если prototype невозможен:
   - отдельно зафиксировать ограничение коробки и пересмотреть продуктовые ожидания

## Итог discovery

Current state after `6.4.3b.2` показывает:

- payload-only path исчерпан как first-line solution
- transport path уже здоров
- следующий осмысленный слой — **box-side session/CRM reconciliation**

Этого достаточно, чтобы считать `Box Rebinding Feasibility Spike` завершённым и переходить к минимальной box-side implementation phase.

## Исторический следующий шаг

Следующий implementation step:

- `ТЗ-6.4.3b.3.2 / Box Rebinding Implementation`

Его scope:

- box-side inspection hooks
- minimal happy-path rebinding prototype
- smoke-check на existing contact reuse

Этот шаг был реализован и подтверждён production-like smoke-check.
