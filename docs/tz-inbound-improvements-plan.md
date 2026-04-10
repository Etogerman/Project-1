# ТЗ: Улучшение приёма входящих сообщений от клиентов

## Цель

Улучшить обработку входящих событий от клиентов в двух независимых эпиках:

1. Telegram unsubscribe: видеть, когда клиент заблокировал или разблокировал бота, и не пытаться писать в заблокированный диалог.
2. Media ingest: принимать медиа от клиента, хранить метаданные и локальные файлы, показывать их в интерфейсе, давать скачать и удалить локальную копию.

Отдельно, вне этого ТЗ: экспорт медиа в Bitrix24 Open Lines.

## Границы

В scope:
- Telegram unsubscribe.
- Telegram/MAX media ingest.
- Private storage для локальных файлов.
- История диалога.
- Preview в списке диалогов, overview карточки контакта и таблице контактов.
- Download/delete lifecycle для локальной копии.

Вне scope:
- MAX unsubscribe без подтверждённого webhook event.
- Новый Bitrix24 transport path.
- Grouped album UI как единый bubble.
- ffmpeg, video thumbnails generation, waveform generation.
- S3 migration.
- merge, rebase, deploy, migrate и публикация изменений.

## Архитектурные решения

- Для системных событий используется `messages.message_kind = inbound_system_event`.
- Для детализации системных событий добавляется `messages.system_event_code`.
- Для медиа не вводятся отдельные `message_kind` по типам файлов; media message остаётся `KIND_INBOUND_USER`.
- `messages.text` хранит обычный текст сообщения или caption.
- Вложения хранятся в новой таблице `message_attachments`.
- Блокировка отправки централизуется через `ResolveDialogRouteStatusAction`, а не дублируется по разным outbound-классам.
- Локальные файлы хранятся на существующем приватном диске `local` в `storage/app/private`.
- Любой будущий эпик по Bitrix media должен начинаться с discovery внутри текущего custom connector path, а не с выбора нового API-контура.

## Эпик 1: Telegram Unsubscribe

### Цель

Видеть в истории и статусе диалога, что клиент заблокировал или разблокировал бота, и блокировать исходящие отправки в заблокированный диалог.

### Граница

- В scope: только Telegram.
- MAX unsubscribe не входит в этот шаг, потому что в текущем контуре нет подтверждённого webhook event для private block/unsubscribe.
- Архитектура должна позволять позже добавить MAX без переделки схемы.

### Что меняется

#### Telegram webhook subscription

- В `config/bots.php` в `telegram.allowed_updates` добавляется `my_chat_member`.

#### Схема данных

В `messages`:
- `message_kind = inbound_system_event`
- `system_event_code nullable`

В `dialogs`:
- `bot_subscription_status nullable`
- `bot_subscription_changed_at nullable`
- `bot_subscription_source_message_id nullable`

Поддерживаемые значения `system_event_code` на старте:
- `bot_blocked_by_user`
- `bot_unblocked_by_user`

Поддерживаемые значения `bot_subscription_status` на старте:
- `null`
- `blocked_by_user`

`null` означает, что подтверждённого blocked-state нет. При unblock статус очищается обратно в `null`, а не переводится в искусственный `active`.

#### Нормализация Telegram event

`BotIncomingMessageNormalizer` должен обрабатывать `my_chat_member` по переходу `old_chat_member.status -> new_chat_member.status`, а не только по финальному значению.

Минимально поддерживаемые переходы:
- `member -> kicked` => `bot_blocked_by_user`
- `kicked -> member` => `bot_unblocked_by_user`

#### Централизация blocked-send логики

Blocked-state не проверяется вручную в четырёх местах. Вместо этого расширяется `ResolveDialogRouteStatusAction`:
- добавляется отдельный status/code для `blocked_by_user`
- route-status возвращает понятный label и blockedReason
- manual reply, auto-reply, collector outbound и phone-capture outbound используют этот единый route-status guard

Это сохраняет один источник истины для sendability диалога.

#### Правила для system events

System events являются `store-only`:
- сохраняются в историю
- видны в preview/overview
- обновляют денормализованный статус `Dialog`
- не запускают auto-reply
- не запускают collector response
- не считаются inbound, требующим ответа оператора
- не идут в Bitrix live/history export
- не участвуют в missed inbound retry

### UI и операторское поведение

Нужно покрыть все текущие рабочие поверхности:
- история диалога: системная плашка `Клиент заблокировал бота` / `Клиент разблокировал бота`
- список диалогов: badge или status marker у заблокированного диалога
- overview карточки контакта: признак blocked dialog у соответствующего канала
- ручная отправка: понятная блокирующая причина из route-status

Будущая отдельная timeline-card может использовать те же system events, но в acceptance этого шага не входит.

### Тестовая стратегия

- `BotIncomingMessageNormalizerTest`: `my_chat_member` blocked/unblocked transitions.
- `StoreInboundMessageActionTest`: сохранение `inbound_system_event`.
- `ResolveDialogRouteStatusActionTest`: blocked dialog возвращает non-sendable status.
- tests для outbound guard: manual reply, auto-reply, collector outbound, phone-capture outbound не отправляются в blocked dialog.
- negative tests: system events не идут в auto-reply, collector, Bitrix export и retry.

### Критерии приёмки

- Telegram blocked/unblocked событие сохраняется в историю.
- После blocked event диалог перестаёт быть sendable.
- После unblock event blocked-state снимается.
- Оператор видит blocked-state в истории и статусных preview.
- Исходящие отправки не уходят в blocked dialog.

## Эпик 2: Media Ingest

### Цель

Принимать фото, видео, аудио, voice, animation, sticker и document от клиента, сохранять метаданные и локальные файлы, показывать оператору медиа в интерфейсе и давать скачать или удалить локальную копию.

### Граница

В scope:
- Telegram и MAX media ingest.
- Metadata storage.
- Local download pipeline.
- Rendering в истории и preview.
- Local delete и retry.

Вне scope:
- Bitrix media export.
- Склейка альбомов в единый bubble.
- Thumbnail generation через ffmpeg.
- S3 и внешнее object storage.

### Доменный контракт

- Media message остаётся `KIND_INBOUND_USER`.
- `messages.text` хранит caption, если он есть.
- Media-only message без caption остаётся `KIND_INBOUND_USER` с пустым `text`.
- Текстовые match scopes (`exact_keyword`, `contains_text`, `exact_text_or_parameter`) работают по `text/caption`.
- `any_inbound` сохраняет текущую семантику и продолжает срабатывать на любое `KIND_INBOUND_USER`, включая media-only сообщения без текста.
- Collector для media-only сохраняет текущую семантику blank text reply; отдельного “валидного ответа полем” из одного только медиа не появляется.

### Схема хранения

Создаётся таблица `message_attachments` со следующими полями:

| Поле | Тип | Назначение |
|------|-----|------------|
| `id` | bigint PK | |
| `message_id` | bigint FK -> messages | |
| `media_kind` | varchar | `photo`, `video`, `audio`, `voice`, `animation`, `sticker`, `document` |
| `provider_attachment_key` | varchar | нормализованный idempotency key вложения внутри provider payload |
| `provider_file_id` | varchar nullable | provider file id |
| `provider_file_unique_id` | varchar nullable | provider unique file id |
| `provider_group_key` | varchar nullable | `media_group_id` / аналог группы |
| `provider_token` | varchar nullable | token/reference, если provider его использует |
| `mime_type` | varchar nullable | |
| `original_name` | varchar nullable | |
| `file_size_bytes` | bigint nullable | |
| `width` | int nullable | |
| `height` | int nullable | |
| `duration_seconds` | int nullable | |
| `local_disk` | varchar nullable | |
| `local_path` | varchar nullable | |
| `download_status` | varchar | `pending`, `downloading`, `downloaded`, `failed` |
| `download_attempts` | int default 0 | |
| `download_error_code` | varchar nullable | |
| `download_error_message` | text nullable | |
| `downloaded_at` | timestamp nullable | |
| `deleted_at` | timestamp nullable | |
| `deleted_by_user_id` | bigint nullable | |
| `raw_payload` | jsonb nullable | provider attachment payload |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Требование idempotency:
- должен существовать явный unique/upsert rule на уровне attachment rows
- replay webhook не должен создавать дубликаты вложений

### Provider-specific rules

#### Telegram

- `photo` приходит массивом `PhotoSize`; в системе создаётся одно attachment по largest variant.
- `caption` попадает в `messages.text`.
- `media_group_id` сохраняется в `provider_group_key`.
- В первой версии UI не группирует альбом в единый bubble.
- `webp` sticker отображается как image.
- `tgs` sticker в первой версии идёт через fallback без отдельной Lottie-зависимости.
- `webm` sticker допускается как video fallback.

#### MAX

- Поддерживается ingest media metadata из подтверждённых attachment payloads.
- MAX unsubscribe в этот ТЗ не входит.
- Для каждого media type путь retrieval подтверждается в рамках реализации и тестов.

### Локальное хранение

Используется существующий приватный диск `local`.

Структура пути:

```text
chat-media/{platform}/{channel_id}/{Y}/{m}/{d}/{message_id}/{attachment_id}-{safe_name}
```

Удаление означает только удаление локального файла. `Message` и запись `message_attachments` не удаляются.

### Слайсы реализации

#### Слайс 1: Metadata + первый видимый результат

Что делается:
- миграция `message_attachments`
- модель и связи
- нормализация Telegram/MAX media metadata
- сохранение attachment rows
- attachment-level idempotency
- caption в `messages.text`
- текстовые summary в истории и preview:
  - `📷 Фото`
  - `🎥 Видео`
  - `🎧 Аудио`
  - `📎 document.pdf`

Что не делается:
- нет скачивания файлов
- нет `DownloadMessageAttachmentJob`
- нет local file path
- нет inline preview

Критерий пользы:
- оператор уже видит, что клиент прислал медиа, даже без скачивания файла

#### Слайс 2: Download pipeline

Что делается:
- `DownloadMessageAttachmentJob`
- получение provider file reference
- скачивание файла
- сохранение в private storage
- protected open/download endpoint
- retry failed downloads

Правила ошибок:
- используется `failed`, а не отдельный lifecycle-status `expired`
- причина ошибки хранится в `download_error_code` и `download_error_message`
- для Telegram истёкшая ссылка трактуется как очередной recoverable failure с повторным `getFile`

#### Слайс 3: Inline rendering

Что делается:
- image preview
- video/audio player или карточка
- document download block
- sticker fallback
- placeholders для `pending` / `downloading`
- error state для `failed`
- tombstone для `deleted_at`

#### Слайс 4: Local delete + retry UX

Что делается:
- удаление локальной копии файла
- заполнение `deleted_at` и `deleted_by_user_id`
- tombstone в UI
- ручной retry failed download

#### Слайс 5: Future Bitrix media discovery

Отдельный будущий ТЗ.

Граница будущего эпика:
- только discovery и проектирование внутри текущего custom connector path
- без преждевременного выбора `imopenlines.message.add` или другого нового transport path

### UI и операторское поведение

В scope должны входить все поверхности, где оператор видит последнее сообщение:
- история диалога
- список диалогов
- overview карточки контакта
- таблица контактов с `latest_message_text`

Отображение:
- photo: preview
- video: player/card
- audio/voice: player
- document: имя + размер + скачать
- `webp` sticker: image
- `tgs` sticker: fallback badge/text
- `webm` sticker: video fallback
- `pending/downloading`: заглушка
- `failed`: ошибка + retry
- `deleted_at`: tombstone `Файл удалён локально`

Preview в списках должен строиться не из сырого `message->text`, а из summary builder-а, чтобы media-only сообщения не выглядели пустыми.

### Тестовая стратегия

- `BotIncomingMessageNormalizerTest`: Telegram media payloads, Telegram photo array, caption, media-only, MAX attachments.
- `StoreInboundMessageActionTest`: attachment rows, idempotency, caption persistence.
- `BuildConversationFeedViewDataActionTest`: history and preview summaries.
- Filament/Livewire structural tests: dialog list, contact overview, contact table previews.
- `DownloadMessageAttachmentJobTest`: success, failure, retry.
- Auth tests для protected attachment endpoint.
- Negative tests: media-only не ломает collector semantics.

### Критерии приёмки

- Telegram/MAX media создаёт attachment records без дублей.
- Caption сохраняется в `messages.text`.
- Media-only сообщения видны в истории и preview.
- После `S1` оператор видит хотя бы текстовые summary по типу медиа.
- После `S2` файлы скачиваются в private storage и доступны только авторизованным пользователям.
- После `S3` основные media types отображаются в истории.
- После `S4` локальное удаление не ломает историю.

## Что не меняется

- Подтверждённый Bitrix24 happy-path `contact sync -> deal sync -> history export`.
- Существующий custom connector path для Open Lines.
- MAX unsubscribe logic.
- Grouped album UI.
- Внешнее object storage.

Важно: outbound behaviour меняется в части reply gating для blocked dialog. Это осознанное изменение, а не “ничего не меняется”.

## Известные компромиссы и риски

- Для MAX unsubscribe пока нет подтверждённого event source.
- Для MAX file retrieval путь нужно подтвердить по каждому media type в пределах реализации.
- Grouped album UI отложен.
- Video thumbnails и продвинутая media-processing логика отложены.
- Future Bitrix media export требует отдельного discovery и отдельного ТЗ.

## Порядок реализации

Рекомендуемый порядок:

1. Эпик 1: Telegram unsubscribe.
2. Эпик 2 / Слайс 1: media metadata + visible summaries.
3. Эпик 2 / Слайс 2: download pipeline.
4. Эпик 2 / Слайс 3: inline rendering.
5. Эпик 2 / Слайс 4: local delete + retry UX.
6. Отдельный будущий ТЗ по Bitrix media.

Эпики архитектурно независимы, но в рамках текущего process-режима проекта реализуются последовательно как отдельные implementation streams.
