# Post-Deploy Smoke Checklist

## Цель

Закрывать релиз не фактом deploy, а фактом подтверждённого smoke-check
на реальном рабочем окружении.

## Обязательное правило

- auto-deploy сам по себе не означает, что релиз принят
- automatic smoke должен проверять то окружение, куда реально попал новый код
- production smoke без нового production deploy не считается подтверждением релиза
- destructive maintenance-команды не запускаются просто ради smoke-check

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
- staging становится главным автоматическим acceptance gate

Если staging smoke запускается автоматически по `push` в `staging`:

- ожидаемый deployed rev берётся из SHA этого push-коммита
- rev-check должен подтвердить, что в staging реально появился именно этот rev

Если staging smoke запускается вручную через `workflow_dispatch`:

- нужно явно передать `expected_app_rev`
- этот input должен соответствовать реально ожидаемому deployed commit SHA
- запуск manual smoke с неверным `expected_app_rev` считается ошибкой запуска,
  а не регрессией приложения

Перед real Bitrix Open Lines manual-reply smoke на staging дополнительно проверить:

- `BITRIX24_FAKE_HAPPY_PATH_ENABLED=false`
- `BITRIX24_OPENLINES_SERVICE_USER_ID` задан
- service user реально может писать в целевой Open Lines chat

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

Перед real production manual-reply smoke дополнительно проверить:

- `BITRIX24_OPENLINES_SERVICE_USER_ID` задан
- service user реально может писать в целевой Open Lines chat

Проверить:

- `/`
- `/admin/login`
- безопасный admin smoke
- отсутствие новых `500` в логах после проверки, если доступ к логам есть

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

Этого достаточно, чтобы релизный цикл был закрыт формально, а не “на словах”.
