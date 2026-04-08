# Диагностика: silent drop MAX contact share в intake path

## Статус: класс поломки определён, root cause ещё не доказан

## Что доказано

1. **Outbound кнопка уходит корректно:**
   Outbound payload `806` содержит `inline_keyboard` с `type: request_contact`.

2. **Webhook после нажатия кнопки ДОХОДИТ до Laravel:**
   `webhook.received` в `2026-04-07 16:19:44Z` — отдельный event между двумя "JBTMAIN".

3. **После этого webhook — тишина:**
   Нет `Message`, нет `inbound_contact_share`, нет reply pipeline,
   нет `webhook.max_unhandled_payload`, нет `max.contact_share_unknown_format`.

4. **Это не проблема rules, phone storage или UI:**
   Проблема до persistence слоя.

## Класс поломки

Silent drop в MAX intake path:
- после `webhook.received`
- до создания `Message`

Наиболее вероятный узел: `BotIncomingMessageNormalizer::normalizeMax()`
или ветка в `BotWebhookController` вокруг `normalize()`.

## Что НЕ доказано

Конкретная ветка `return null` в normalizer. Возможные gate-ы:

| Строка | Условие | Статус |
|--------|---------|--------|
| 165 | `update_type !== 'message_created'` | **Маловероятно** — по production context webhook был `message_created` |
| 171 | `sender.is_bot === true` | Возможно — если MAX отправляет contact share с bot sender |
| 179 | `!$isDialog` | Возможно — если contact share payload не содержит `user_locale` / `recipient.user_id` |
| 186 | `userId` или `chatId` пустой | Возможно — если contact share имеет другую структуру sender/recipient |

## Рекомендуемый следующий шаг

### Reason-coded диагностика на null-path

Не raw payload dump, а точная причина отказа.
В `normalizeMax()` — перед каждым `return null` добавить лог:

```php
// строка 165
if ($updateType !== 'message_created') {
    Log::info('MAX normalize null: unsupported_update_type', [
        'channel_id' => $channel->id,
        'update_type' => $updateType,
    ]);
    return null;
}

// строка 171
if (! is_array($message) || data_get($message, 'sender.is_bot') === true) {
    Log::info('MAX normalize null: sender_is_bot_or_no_message', [
        'channel_id' => $channel->id,
        'has_message' => is_array($message),
        'sender_is_bot' => data_get($message, 'sender.is_bot'),
    ]);
    return null;
}

// строка 179
if (! $isDialog) {
    Log::info('MAX normalize null: not_dialog', [
        'channel_id' => $channel->id,
        'has_user_locale' => filled($payload['user_locale'] ?? null),
        'has_recipient_user_id' => filled(data_get($message, 'recipient.user_id')),
        'has_recipient_chat_id' => filled(data_get($message, 'recipient.chat_id')),
    ]);
    return null;
}

// строка 186
if (! filled($userId) || ! filled($chatId)) {
    Log::info('MAX normalize null: missing_ids', [
        'channel_id' => $channel->id,
        'has_user_id' => filled($userId),
        'has_chat_id' => filled($chatId),
    ]);
    return null;
}
```

Плюс safe summary перед normalize (в controller):

```php
if ($expectedPlatform === Channel::PLATFORM_MAX) {
    Log::info('MAX webhook pre-normalize', [
        'channel_id' => $channel->id,
        'update_type' => $payload['update_type'] ?? null,
        'has_sender_user_id' => filled(data_get($payload, 'message.sender.user_id')),
        'sender_is_bot' => data_get($payload, 'message.sender.is_bot'),
        'has_recipient_chat_id' => filled(data_get($payload, 'message.recipient.chat_id')),
        'has_body_contact' => is_array(data_get($payload, 'message.body.contact')),
        'has_body_attachments' => is_array(data_get($payload, 'message.body.attachments')),
        'has_vcf_info' => filled(data_get($payload, 'message.body.vcf_info')),
        'body_type' => data_get($payload, 'message.body.type'),
    ]);
}
```

### Воспроизведение

После деплоя диагностики — воспроизвести отдельно:
1. `web.max.ru` — нажать «Поделиться телефоном»
2. Мобильное приложение MAX — то же самое
3. Проверить `storage/logs/laravel.log` — какой reason code

### По результатам

Когда увидим конкретный reason code:
- Точечный фикс в normalizer (5-10 строк)
- Не broad workaround

## Что делать с workaround (01c97c6)

Workaround уже в main. Он не мешает диагностике — кнопка `request_contact`
уже не отправляется, значит воспроизвести баг нельзя.

**Для воспроизведения нужно:**
1. Временно вернуть кнопку на тестовом канале
2. Или создать отдельную тестовую ветку без workaround

Или: добавить диагностику, откатить workaround, воспроизвести, починить root cause.
