## ТЗ: `/start` во время активной анкеты не должен считаться ответом

### Цель

Убрать поведение, при котором slash-команда пользователя, в первую очередь `/start`,
во время активного collector flow интерпретируется как ответ на текущее поле анкеты.

### Проблема

Сейчас при активной анкете inbound `/start` попадает в обычный path
`ProcessDataCollectionResponseJob` как `replyText`.
Если extractor не может принять такой текст, система шлёт `retry_message`
текущего поля.

На практике это создаёт ложное ощущение, что:
- сообщение после unblock “съелось как ответ”;
- система обработала сервисную команду как содержательный пользовательский ответ.

Это особенно заметно после `block -> unblock`, когда пользователь часто первым делом
жмёт `/start`.

### Наблюдаемое текущее поведение

Пример:
- ранее уже был отправлен вопрос `В каком городе вы живёте?`
- пользователь блокирует и разблокирует бота
- первым сообщением после unblock отправляет `/start`
- collector трактует `/start` как ответ на `residence_city`
- extractor отклоняет ответ
- бот шлёт retry: `Подскажите, пожалуйста, город, где вы живёте...`

### Желаемое поведение

Если у контакта активна анкета и приходит slash-команда:
- slash-команда не считается ответом на текущее поле;
- attempts по текущему полю не увеличиваются;
- collector state не двигается;
- pending/retry semantics не ломаются;
- система либо:
  - мягко повторяет текущий вопрос, либо
  - игнорирует slash-команду и оставляет collector ждать нормальный ответ.

### Рекомендация по первой версии

Для первой версии выбрать простой и безопасный вариант:

- если inbound text начинается с `/` и контакт находится в активной анкете,
  `ProcessDataCollectionResponseJob` не должен запускать extraction текущего field;
- вместо этого нужно переотправить текущий вопрос как `data_collection_question`,
  без изменения attempts и without field progression.

Это лучше, чем silent ignore, потому что оператор и пользователь получают понятное
текущее состояние.

### Границы

В scope:
- только active collector flow;
- только slash-команды вида `/...`;
- только защита от интерпретации команды как ответа на поле.

Вне scope:
- общий redesign semantics для bot commands;
- отдельная логика restart анкеты по `/start`;
- scenario command routing;
- Bitrix24;
- unsubscribe/webhook rollout.

### Затрагиваемые точки

С высокой вероятностью:
- `app/Jobs/ProcessDataCollectionResponseJob.php`
- возможно `app/Services/Bots/DispatchStoredInboundBotMessageAction.php`,
  если захотим отрезать slash-команды раньше
- tests:
  - `tests/Feature/ProcessDataCollectionResponseJobTest.php`
  - возможно `tests/Feature/BotWebhookAutoReplyTest.php`

### Архитектурное решение

Не вводить новый command-router.

Минимальный путь:
- в collector response job добавить ранний guard:
  - если `replyText` начинается с `/`
  - и contact находится в active data collection
  - то вызвать path повторной отправки текущего вопроса
  - и выйти без extraction / attempts increment / state mutation

### Тестовая стратегия

Нужны как минимум такие тесты:
- active collector + `/start`:
  - attempts не увеличиваются
  - current field не меняется
  - бот переотправляет текущий вопрос
- active collector + другая slash-команда `/help`:
  - тот же контракт
- обычный валидный текстовый ответ:
  - текущее поведение не ломается
- blocked/unblock path:
  - `/start` после unblock не съедается как ответ на поле

### Критерии приёмки

- slash-команды не считаются пользовательским ответом на collector field;
- collector state и attempts сохраняются;
- пользователь получает повтор текущего вопроса, а не странный retry после
  failed extraction;
- обычный collector flow не деградирует.

### Известный компромисс

Первая версия не делает `/start` специальной бизнес-командой restart/resume.
Она только перестаёт считать slash-команду ответом на текущее поле.
