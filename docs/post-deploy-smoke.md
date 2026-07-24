# Post-Deploy Smoke Checklist

## Цель

Подтвердить production-результат на рабочем окружении. Smoke не закрывает
Issue/Spec Closure и не разрешает cleanup.

## Обязательное правило

- auto-deploy сам по себе не означает, что релиз принят
- automatic smoke должен проверять то окружение, куда реально попал новый код
- automatic smoke не заменяет staging QA для code/runtime stream-а
- production smoke без нового production deploy не считается подтверждением релиза
- destructive maintenance-команды не запускаются просто ради smoke-check
- success -> принятие результата/риска -> result-route; no-result допустим
  только без materialization; cleanup ждёт оба checkpoint

## Автоматизация

В репозитории можно запускать тот же smoke автоматически через:

- `.github/workflows/post-deploy-smoke.yml`
- `.github/workflows/production-post-deploy-smoke.yml`

Если staging автодеплоится из ветки `staging`, основной automatic smoke
должен запускаться после `push` в `staging`, а не после `push` в `main`.

`production` environment нужен отдельно для manual smoke после ручного
production deploy.

Для каждого реально используемого environment нужны свои secrets:

- `PLAYWRIGHT_BASE_URL`
- `PLAYWRIGHT_ADMIN_EMAIL`
- `PLAYWRIGHT_ADMIN_PASSWORD`

Автоматический workflow должен проверять только те окружения, которые реально
участвуют в текущем release flow. Формально включать нерабочий staging нельзя:
это создаёт постоянный красный run без реальной диагностической ценности.

И staging, и production smoke дополнительно проверяют rev задеплоенного
приложения по marker в админке до запуска public/admin smoke.

## Где смотреть статус

Статус staging smoke проверяется в GitHub, а не в админке приложения:

- во вкладке `Actions` репозитория
- в конкретном workflow `Staging Post-Deploy Smoke`
- в checks у push/merge-коммита в ветке `staging`

До merge удобнее смотреть checks прямо в PR. После merge надёжнее смотреть
конкретный workflow run или checks коммита в `staging`, но всегда с учётом
того, в какое окружение реально ушёл текущий change-set.

## Staging

Если staging реально участвует в release flow:

- merge или push в `staging` должен проверяться staging smoke
- staging становится главным автоматическим acceptance gate уже после публикации change-set в среду, а не trigger-ом выхода из локалки
- для code/runtime stream-а после успешного automatic smoke отдельно проводится staging QA затронутого рабочего сценария
- переход к `draft PR` в `main` запрещён, если staging QA нашёл blocker или не был проведён

Staging QA фиксируется коротко:

- environment и видимый rev
- какие сценарии проверены вручную или оператором
- результат каждого сценария
- незакрытые риски или явная причина, почему пользователь принимает риск

Для релизов, затрагивающих входящие медиа, дополнительно проверить в Laravel Cloud:

- отдельный background process запущен с командой
  `php artisan queue:work inbound-media --queue=inbound-media --tries=1 --sleep=1`
- обычный worker не слушает очередь `inbound-media`
- после тестовой загрузки queue age возвращается к нулю, а медиа выходит из
  состояния `Ожидает загрузки`

Если staging smoke запускается автоматически по `push` в `staging`:

- ожидаемый deployed rev берётся из SHA этого push-коммита
- rev-check должен подтвердить, что в staging реально появился именно этот rev

Если staging smoke запускается вручную через `workflow_dispatch`:

- нужно явно передать `expected_app_rev`
- этот input должен соответствовать реально ожидаемому deployed commit SHA
- запуск manual smoke с неверным `expected_app_rev` считается ошибкой запуска,
  а не регрессией приложения

Если staging не работает или не участвует в приёмке:

- staging не должен оставаться формально включённым в автоматический smoke
- workflow может временно проверять другое реально используемое окружение

## Production

Manual workflow `Production Post-Deploy Smoke` запускается только после
фактического production deploy.

При запуске нужно обязательно передать:

- `release_ref` — ожидаемый deployed commit SHA

Production rev-check обязан сначала подтвердить, что в админке уже виден
именно этот rev, и только потом запускать public/admin smoke.

Проверить:

- `/`
- `/admin/login`
- безопасный admin smoke
- отсутствие новых `500` в логах после проверки, если доступ к логам есть

Если релиз затрагивает входящие медиа, до отправки тестового файла подтвердить
production background process для connection/queue `inbound-media` той же
командой, что и на staging. После загрузки проверить queue age и конечный статус
вложения. Без отдельного media worker новые задания останутся в `pending`.

Production smoke делать только после фактического production deploy.

На production не делать ради проверки:

- destructive purge-команды
- forced maintenance repair без отдельной задачи
- агрессивный rate-limit spam, который мешает живому трафику

## Что считать success

- ключевые маршруты открываются
- admin login работает
- релевантный released flow не даёт явных regressions
- после проверки нет новых `500`, если логи доступны
- нет жалоб от операторского workflow сразу после deploy

## Следующий checkpoint после success

1. Зафиксировать environment/release ref/result и принять результат или риск.
2. Пройти result-route, затем cleanup после обоих checkpoint.

No-result route без materialization описан в `docs/task-delivery-workflow.md`;
иначе нужен result-route, rollback или forward-fix.

## Если доступа к логам нет

Если у агента или оператора нет прямого доступа к runtime-логам:

- это ограничение явно фиксируется в результате smoke-check
- шаг всё равно может считаться закрытым при `Tests = success`
  и success smoke по тому окружению, куда реально ушёл новый код
- отсутствие явных новых ошибок в проверяемом released flow считается
  достаточным минимальным сигналом успеха

## Что делать при проблеме

Сначала локализовать проблему по слою:

- `UI/admin issue`
- `runtime/webhook issue`
- `ops/maintenance issue`
- `qa/checklist issue`

Потом соотнести проблему с конкретным PR или stream, а не с “релизом вообще”.

Если падает именно rev-check:

- сначала проверить, что deploy действительно завершён
- затем проверить, что в workflow передан правильный expected rev
- только после этого трактовать падение как возможную проблему rollout-а

## Минимальный формат фиксации результата

Для каждого реально проверяемого окружения:

- `status: ok | warn | fail | skip`
- что именно проверено
- короткий комментарий
- если логов нет, это ограничение фиксируется отдельной строкой

Это закрывает smoke, но не stream: затем идут acceptance, Issue/Spec Closure и
cleanup.
