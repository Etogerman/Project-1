# Расследование: MAX request_contact не работает

## Хронология событий (07.04.2026)

На скриншоте из MAX web client:
1. Пользователь пишет "JBTMAIN" → бот отвечает текстом + кнопка «Поделиться номером телефона»
2. Пользователь нажимает кнопку → MAX показывает «Герман Абрикосов — Это вы»
3. Пользователь подтверждает → снова появляется "JBTMAIN" и бот снова отвечает

В БД (raw_payload):
- `id=805` (16:19:35): `{"text": "JBTMAIN"}` — обычный текст
- `id=808` (16:19:49): `{"text": "JBTMAIN"}` — обычный текст
- **Нет записи с contact share payload**

Диагностика `webhook.max_unhandled_payload` (коммит `5d0cabb`):
- **Не сработала** — значит normalizer не вернул `null`
- Оба webhook обработаны как обычные `inbound_user`

## Что известно точно

1. MAX **раньше доставлял** contact share на этом же канале:
   - 31.03, 01.04, 03.04 — `source: max_contact_share` в логах
2. MAX web client **показывает** UI шаринга контакта (скриншот подтверждает)
3. Webhook с contact share payload **не дошёл** до нашего backend 07.04
4. Normalizer **корректен** — он не ронял webhook, его просто не было

## Как мы отправляли кнопку (до workaround)

```json
{
    "type": "inline_keyboard",
    "payload": {
        "buttons": [[[{
            "type": "request_contact",
            "text": "Поделиться номером телефона"
        }]]]
    }
}
```

Это **inline keyboard** с кнопкой типа `request_contact`.
По MAX API docs: `request_contact` — валидный тип inline-кнопки.

## Гипотезы

### Гипотеза A: MAX web client не отправляет contact share webhook после нажатия

MAX web client (`web.max.ru`) возможно обрабатывает `request_contact`
иначе, чем мобильное приложение:
- Показывает UI подтверждения
- Пользователь подтверждает
- Но webhook с контактом **не отправляется**
- Или отправляется как callback, а не как message_created

Это объяснило бы почему раньше работало (если раньше тестировали
с мобильного приложения или с другой версии web client).

### Гипотеза B: MAX изменил поведение request_contact между 03.04 и 07.04

Platform contract drift: MAX обновил web client или API,
и `request_contact` кнопка перестала генерировать contact share webhook.

### Гипотеза C: Contact share отправляется как отдельный update_type

Возможно MAX отправляет contact share не как `message_created`,
а как другой `update_type` (например `message_callback`).
Наш `BotWebhookController` и normalizer фильтруют по `update_type === 'message_created'`
и могут пропускать callback-и.

## Следующие шаги для расследования

### 1. Воспроизвести с мобильного приложения MAX

Отправить contact share с мобильного MAX (не web.max.ru).
Проверить: приходит ли webhook? Какой `update_type`? Какой payload?

### 2. Логировать ВСЕ входящие HTTP requests на webhook endpoint

Временно добавить в `BotWebhookController::max()` лог **до** нормализации:

```php
Log::info('MAX raw webhook', [
    'payload_keys' => array_keys($payload),
    'update_type' => $payload['update_type'] ?? null,
    'has_message' => isset($payload['message']),
]);
```

Это поймает webhook-и которые normalizer может пропускать.

### 3. Проверить MAX API docs по request_contact callback

Что именно MAX отправляет когда пользователь нажимает `request_contact`
inline-кнопку? Это `message_created` с contact в body?
Или `message_callback`? Или `message_contact_shared`?

### 4. Посмотреть raw_payload успешных contact share (31.03-03.04)

```php
App\Models\Message::whereIn('id', [344, 357, 701])
    ->get(['id', 'raw_payload'])
    ->toArray()
```

Сравнить формат payload тех успешных share с текущими webhook-ами.
Возможно раньше приходил другой `update_type` или другая структура.

### 5. Проверить нет ли пропущенного update_type в нашем endpoint

Наш MAX webhook endpoint принимает **все** POST requests.
Но normalizer фильтрует по `update_type`:
- `bot_started` → обрабатывается
- `message_created` → обрабатывается
- Всё остальное → `return null` (строка 165-167 normalizer)

Если MAX присылает contact share как другой update_type —
мы его молча дропаем. Диагностика `webhook.max_unhandled_payload`
ловит `normalize() === null`, но только для platform MAX.

## Что делать с workaround (коммит 01c97c6)

Workaround уже в main. Он убирает `request_contact` attachment для MAX.

**Если расследование покажет что проблема на стороне MAX** (web client / API change):
- Workaround оставить
- Добавить fallback: парсинг номера из текста для MAX
- Или: текст авто-ответа «напишите номер в формате +7XXXXXXXXXX»

**Если расследование покажет что проблема в нашем коде** (пропущенный update_type):
- Откатить workaround
- Починить normalizer / controller чтобы ловить правильный update_type

**Если проблема — web.max.ru но не мобильный клиент:**
- Вернуть кнопку (работает для мобильных)
- Добавить fallback текст для web-клиентов
