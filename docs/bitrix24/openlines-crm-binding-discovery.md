# Bitrix24 Open Lines CRM Binding Discovery

## Статус

Discovery завершён на уровне достаточном для следующего implementation step.

Текущее состояние по production smoke-check:

- `Contact Sync` работает
- `Deal Sync` работает
- `History Export` работает
- `Open Lines` custom connector registration работает
- `Open Lines live transport` работает
- `Bitrix -> messenger -> local history -> ack` работает
- новый live-диалог в Open Lines создаёт новый лид вместо reuse существующего CRM-контакта

## Подтверждённые факты

### 1. Transport-проблема уже закрыта

Production smoke-check подтвердил:

- `OnSendMessageCustom` приходит в Laravel
- сообщение оператора доставляется обратно в MAX
- локально создаётся outbound message с `sent_by_system_code = bitrix24_openlines`
- `sendStatusDelivery` теперь успешен
- `Bitrix24WebhookEvent.processing_status = processed`

Следствие:

- текущий gap уже не в регистрации connector-а
- текущий gap уже не в `sendStatusDelivery`
- текущий gap находится именно в CRM binding Open Lines диалога

### 2. Existing CRM contact уже существует и sync-нут

В production уже есть локальный контакт, который синхронизирован в Bitrix:

- local `contact.id = 22`
- `bitrix24_contact_id = 70739`
- `bitrix24_sync_status = synced`

У этого же контакта:

- подтянута existing deal
- экспортирована history

Следствие:

- Bitrix already knows this person как CRM contact
- новый Open Lines диалог не должен был создавать новую сущность только из-за отсутствия CRM данных в системе вообще

### 3. Новый live-диалог создаёт новый лид, а не reuse существующий контакт

По production UI inspection подтверждено:

- в Open Lines создаётся новый чат в линии `ABR MAX бот Demo`
- Bitrix создаёт новый лид вида `Герман - ABR MAX бот Demo`
- в лиде нет телефона
- existing CRM contact `70739` не получает эту новую Open Lines сессию

Следствие:

- transport payload идентифицирует пользователя недостаточно для CRM tracker-а Bitrix
- либо линия настроена так, что всегда создаёт новый лид
- либо коробочный Bitrix требует post-create binding layer

## Что подтверждает текущий Laravel-side payload

Текущий payload builder:

- `/Users/abrikosov/Documents/Проект-1/app/Services/Bitrix24/BuildBitrix24OpenLinesMessagePayloadAction.php`

Сейчас Laravel отправляет:

- `CONNECTOR`
- `LINE`
- `MESSAGES[].chat.id`
- `MESSAGES[].chat.name`
- `MESSAGES[].user.id`
- `MESSAGES[].user.name`
- `MESSAGES[].message.id`
- `MESSAGES[].message.date`
- `MESSAGES[].message.text`

Текущий payload **не отправляет**:

- `user.phone`
- `user.email`
- `user.last_name`
- какой-либо documented CRM entity id

Следствие:

- у Bitrix нет phone-based CRM hint-а в live payload
- для нового Open Lines чата Bitrix видит только внешний `user.id` и имя
- этого достаточно для transport-а, но недостаточно для reuse существующего CRM contact в observed production behavior

## Что подтверждает официальная документация Bitrix

### 1. `sendMessages` документирует `user.phone`

В официальной docs для `CustomConnectors::sendMessages` описан формат сообщения, где в `user` поддерживаются:

- `id`
- `last_name`
- `name`
- `picture`
- `url`
- `sex`
- `email`
- `phone`

Источник:

- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/customconnectors/sendmessages.php

Следствие:

- `user.phone` является официально поддержанным полем
- это первый кандидат для existing-contact binding, потому что телефон уже является основным идентификатором в CRM contact sync

### 2. `sendMessages` документирует `message.disable_crm`

В той же docs у `message` есть поле:

- `disable_crm = 'Y'` — отключить чат трекер (CRM tracker)

Источник:

- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/customconnectors/sendmessages.php

Следствие:

- если цель шага — reuse существующего CRM contact, то `disable_crm` включать нельзя
- текущий payload его и не включает, и это правильно

### 3. В documented payload нет прямого поля для CRM contact id

В официально описанной структуре `sendMessages` нет documented поля вида:

- `crm_contact_id`
- `contact_id`
- `entity_id`
- `crm_entity`

Источник:

- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/customconnectors/sendmessages.php

Следствие:

- прямой binding через уже известный `bitrix24_contact_id = 70739` не подтверждён docs
- использовать его как прямое payload поле на следующем шаге без дополнительного box evidence рискованно

## Что у нас уже есть локально

Laravel runtime уже умеет получить данные, которые потенциально полезны для CRM binding:

- primary/normalized phone(s) контакта  
  Опорный код: `/Users/abrikosov/Documents/Проект-1/app/Services/Bitrix24/CollectBitrix24ContactPhonesAction.php`
- `bitrix24_contact_id`
- display name
- external platform user id

Следствие:

- для первого implementation step нет необходимости вводить новые таблицы или новые sync-сущности
- первый кандидат можно реализовать через расширение текущего payload builder-а

## Наиболее вероятная причина текущего поведения

На основе docs и production behavior наиболее вероятное объяснение такое:

1. Laravel не передаёт `user.phone`
2. Bitrix CRM tracker не получает strongest CRM hint
3. Open Lines создаёт новый лид как новое обращение
4. existing CRM contact не reuse-ится автоматически

Это **вывод из собранных фактов**, а не официально подтверждённый контракт Bitrix.

## Что пока не доказано

Следующие пункты ещё не подтверждены:

- что одного `user.phone` достаточно для deterministic reuse existing contact
- что line settings Bitrix позволяют полностью подавить auto-created lead в этом сценарии
- что direct post-create rebinding чата к существующему контакту не потребуется

## Ответы на вопросы ТЗ-6.4.3b.1

### Почему Bitrix сейчас создаёт новый лид

Потому что current live payload не содержит documented CRM-identifying field `user.phone`, а observed box behavior при таком payload — создать новый lead/chat object.

### Можно ли reuse existing CRM contact через payload alone

На текущем discovery уровне: **возможно, да**, но это пока доказано только как сильная гипотеза.

Причина:

- `user.phone` официально документирован
- телефон уже есть в существующем CRM contact
- direct CRM contact id в docs не описан

### Нужен ли box-side adapter

Пока **не доказано**, что он обязателен.

Но он остаётся необходимым fallback, если:

- `user.phone` будет добавлен,
- а Bitrix всё равно продолжит создавать новый лид вместо reuse контакта.

### Влияют ли настройки линии

Да, вероятно влияют, но на текущем шаге нет достаточно подтверждённого box-side evidence, чтобы считать line settings единственным решением.

Observed fact:

- активная линия и connector уже работают
- при этом новый лид всё равно создаётся

Следствие:

- одних line/connector activation settings недостаточно
- binding path нужно проверять отдельно

## Рекомендованный следующий implementation path

Следующий шаг должен идти в таком порядке:

1. Расширить live payload в `BuildBitrix24OpenLinesMessagePayloadAction`:
   - добавить `user.phone` из primary normalized phone
   - добавить `user.last_name`, если есть
   - не включать `message.disable_crm`
2. Повторить production smoke-check на already-synced contact.
3. Если existing contact начнёт reuse-иться:
   - закрепить решение тестами и docs
4. Если Bitrix всё ещё создаёт новый лид:
   - переходить к box-side fallback path
   - исследовать post-create rebinding чата/лида к existing CRM contact

## Итог discovery

Текущий CRM binding gap не выглядит как проблема:

- queue wiring
- Open Lines connector registration
- line activation
- delivery ack

Наиболее вероятно, что это проблема **недостаточно полного live payload**, и первый минимальный шаг должен быть:

- добавить `user.phone` в `imconnector.send.messages`

При этом docs не подтверждают прямое использование `bitrix24_contact_id` в payload, поэтому direct CRM contact id binding на следующем шаге считать основным путём нельзя.

## Рекомендованный следующий шаг

Следующий implementation step:

- `ТЗ-6.4.3b.2 / CRM Binding Implementation`

Минимальный scope этого шага:

- добавить documented CRM-relevant fields в live payload
- production smoke-check на reuse existing contact
- только при провале этого пути рассматривать box-side fallback

## Источники

- https://dev.1c-bitrix.ru/api_d7/bitrix/imconnector/customconnectors/sendmessages.php
- `/Users/abrikosov/Documents/Проект-1/app/Services/Bitrix24/BuildBitrix24OpenLinesMessagePayloadAction.php`
- `/Users/abrikosov/Documents/Проект-1/app/Services/Bitrix24/CollectBitrix24ContactPhonesAction.php`
- `/Users/abrikosov/Documents/Проект-1/bitrix-box/abrikosoff-openlines/local/php_interface/include/abrikosoff_openlines/src/Runtime.php`
