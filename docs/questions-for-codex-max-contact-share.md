# Вопросы по MAX contact share для расследования

## Контекст

Workaround (01c97c6) уже в main. Но нам нужно понять root cause
чтобы решить — вернуть кнопку или оставить workaround.

Уточнение: "JBTMAIN" — это текст, который пользователь написал вручную.
Между двумя "JBTMAIN" пользователь нажал «Поделиться телефоном» и подтвердил.
Но webhook с contact share **не дошёл** до backend.

## Запросы к production БД

### 1. Raw payload успешных contact share (раньше работало)

```php
App\Models\Message::whereIn('id', [344, 357, 701])
    ->get(['id', 'message_kind', 'raw_payload', 'created_at'])
    ->toArray()
```

Это message_id из успешных `contact.phone_captured` логов на channel_id=2 (MAX-Лесли):
- id=344 → 31.03
- id=357 → 01.04
- id=701 → 03.04

Нужно увидеть: какой `update_type`, какая структура `body`, есть ли `contact`/`attachments`/`vcf_info`.

### 2. ВСЕ сообщения за период бага

```php
App\Models\Message::where('channel_id', 2)
    ->whereBetween('created_at', ['2026-04-07 16:19:00', '2026-04-07 16:21:00'])
    ->get(['id', 'direction', 'message_kind', 'text', 'provider_event_key', 'created_at'])
    ->toArray()
```

Нужно увидеть: есть ли **между** двумя "JBTMAIN" (id 805 и 808) какое-то третье сообщение
которое могло быть contact share.

### 3. Логи канала за период бага

```php
App\Models\ChannelActivityLog::where('channel_id', 2)
    ->whereBetween('created_at', ['2026-04-07 16:19:00', '2026-04-07 16:21:00'])
    ->orderBy('created_at')
    ->get(['event', 'message', 'context', 'created_at'])
    ->toArray()
```

Нужно увидеть: есть ли `webhook.max_unhandled_payload`, `max.contact_share_unknown_format`,
или другие diagnostic events.

### 4. Raw payload исходящего auto-reply (кнопка)

Нужно увидеть **что именно мы отправили** в MAX как outbound с кнопкой:

```php
App\Models\Message::where('channel_id', 2)
    ->where('direction', 'outbound')
    ->where('message_kind', 'outbound_auto_reply')
    ->where('reply_to_message_id', 805)
    ->first(['id', 'raw_payload', 'created_at'])
    ?->toArray()
```

Нужно увидеть: был ли в outbound payload `request_contact` attachment,
и если да — в каком именно формате.

## Вопросы

1. После выката диагностики (#151) — есть ли **хоть одна** запись
   `webhook.max_unhandled_payload` в логах? Если нет — MAX просто не прислал webhook.

2. Можешь ли ты воспроизвести contact share в MAX прямо сейчас
   (написать в бота → получить кнопку → нажать share)?
   И сразу проверить: появился ли webhook в логах?

3. С какого клиента тестировали 07.04 — web.max.ru или мобильное приложение MAX?
   И с какого клиента были успешные share 31.03-03.04?

4. Можно ли посмотреть raw HTTP access log nginx/apache за 07.04 16:19-16:20
   на webhook endpoint — были ли POST requests от MAX которые не дошли до Laravel?
