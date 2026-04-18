# Проект и текущий scope

Abrikosoff Connector — операторская платформа для работы
с входящими сообщениями из мессенджеров.

## Текущий продуктовый контур

- приём входящих сообщений из Telegram и MAX
- сохранение в единую историю переписки
- rule-based автоответы с `exact_keyword` и `any_inbound`
- условия правил по наличию телефона контакта
- phone capture flow для Telegram и MAX
- AI profile collector после получения телефона
- просмотр контактов, диалогов и истории в админке
- overview-only карточка контакта с профилем, ownership и списком диалогов
- отдельная страница диалога как рабочее место оператора
- ручной ответ со страницы диалога
- CRUD телефонов из карточки контакта
- ownership контакта
- редактирование профиля, ручное возобновление анкеты и удаление контакта

## Подтверждённый scope и жёсткие границы

- active collector имеет приоритет над обычным auto-reply
- collector flow: `first_name -> residence_city -> [country] -> [russian_region_confirm] -> age_range -> completion`
- для российских ambiguous-city кейсов используется deterministic Russian shortcut: сначала пытаемся определить `region` или exact candidate set, и только потом задаём fallback-вопрос про страну
- максимум 2 попытки на поле, затем мягкий skip на следующий безопасный шаг
- после точного определения российской локации асинхронно считается `distance_to_moscow_km`; special-case `Москва -> 0`, остальные города РФ идут через `Yandex Geocoder + Haversine`
- ambiguous-city кейсы считают расстояние только после подтверждения `region` и deterministic geocode query
- подтверждённый локальный Bitrix24 happy-path со стороны приложения: `contact sync -> deal sync -> history export`
- Bitrix24 Open Lines happy-path уже подтверждён через box-side пакет; зафиксированная рабочая конфигурация: Telegram line id `32`, MAX line id `31`
- existing-contact rebinding happy-path подтверждён для Telegram и MAX
- любое новое расширение Bitrix24 вне подтверждённого happy-path требует отдельного ТЗ
- возможно подключение дополнительных мессенджеров, но не через преждевременные абстракции

## Стек

- PHP 8.2+, Laravel 11, PostgreSQL
- Filament 5
- Tailwind 3, Vite
- PHPUnit 11
- Playwright
- текущий queue driver — `database`; долгие side-эффекты предпочтительно уводить из HTTP-запроса в очередь

## High-Risk Zones

- webhook ingestion и идемпотентность сообщений
- phone capture flow и follow-up orchestration
- collector progression, retry-логика, soft skip и manual resume
- dialog routing и точность manual reply через `dialog_id`
- Bitrix24 contact/deal/history sync и live export/import happy-path
- российская location-логика, deterministic region resolution и `distance_to_moscow` pipeline

## Start Here

Полный code map при необходимости можно вынести в отдельный reference doc. Для старта почти в любой задаче достаточно этих входных точек:

- `app/Http/Controllers/BotWebhookController.php`
- `app/Models/Contact.php`
- `app/Services/Bots/StoreInboundMessageAction.php`
- `app/Services/Bots/BotAutoReplyService.php`
- `app/Jobs/ProcessAutoReplyJob.php`
- `app/Jobs/ProcessDataCollectionResponseJob.php`
- `app/Services/Contacts/UpdateContactProfileAction.php`
- `app/Services/Bitrix24/SyncContactToBitrix24Action.php`
- `app/Filament/Resources/Dialogs/Pages/ViewDialog.php`
- `resources/views/filament/dialogs/pages/view-dialog.blade.php`
