# Post-Deploy Smoke Checklist

## Цель

Закрывать релиз не фактом deploy, а фактом подтверждённого smoke-check
на staging и production.

## Обязательное правило

- auto-deploy сам по себе не означает, что релиз принят
- staging и production проверяются отдельно
- destructive maintenance-команды не запускаются просто ради smoke-check

## Staging

Проверить:

- `/`
- `/admin/login`
- login flow администратора
- ключевой admin route, относящийся к выпущенному scope
- отсутствие новых `500` в логах после проверки

Рекомендуемые admin routes по типу релиза:

- UI/admin changes: `/admin/contacts`, `/admin/dialogs`, релевантная operator page
- runtime/webhook changes: безопасный happy-path и отсутствие ошибок в логах
- QA/Playwright changes: public smoke и admin smoke через браузер

## Production

Проверить:

- `/`
- `/admin/login`
- безопасный admin smoke
- отсутствие новых `500` в логах после проверки

На production не делать ради проверки:

- destructive purge-команды
- forced maintenance repair без отдельной задачи
- агрессивный rate-limit spam, который мешает живому трафику

## Что считать success

- ключевые маршруты открываются
- admin login работает
- релевантный released flow не даёт явных regressions
- после проверки нет новых `500`
- нет жалоб от операторского workflow сразу после deploy

## Что делать при проблеме

Сначала локализовать проблему по слою:

- `UI/admin issue`
- `runtime/webhook issue`
- `ops/maintenance issue`
- `qa/checklist issue`

Потом соотнести проблему с конкретным PR или stream, а не с “релизом вообще”.

## Минимальный формат фиксации результата

Для каждого окружения:

- `status: ok | warn | fail | skip`
- что именно проверено
- короткий комментарий

Этого достаточно, чтобы релизный цикл был закрыт формально, а не “на словах”.
