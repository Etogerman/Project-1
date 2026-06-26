---
name: ab-pr-ci-review
description: Inspect AB Connector PR, CI, review, draft/ready state, PR body guard fields, and allowed next PR checkpoint without editing PRs, merging, or bypassing delivery gates.
---

# Проверка PR, CI и review

Этот skill помогает проверить состояние PR, CI, review и допустимый следующий
checkpoint для AB Connector.

Skill работает read-only. Он проверяет состояние и называет следующий допустимый
checkpoint, но не выполняет его.

## Границы

Skill не создаёт PR, не редактирует PR title/body, не комментирует PR, не
approve, не request changes, не dismiss review, не переводит PR в ready, не делает
merge, deploy или smoke.

Skill не меняет code diff и не меняет git-состояние.

Не используй `gh pr edit`, `gh pr review`, `gh pr ready`, `gh pr merge`, API-вызовы
изменения PR и любые команды, которые создают комментарии или меняют GitHub/PR
состояние.

Если PR body или metadata не соответствуют правилам проекта, skill называет
проблему и рекомендует отдельный следующий шаг. Исправление metadata выполняется
только по отдельной команде пользователя вне этого skill.

Merge в `staging` или `main` выполняет только пользователь.

## Когда использовать

Используй этот skill, если вопрос связан с:

- PR status;
- CI checks;
- review comments;
- draft/ready;
- поля готовности;
- release-process-guard;
- ab-readiness-check;
- PR body;
- следующим PR checkpoint.

Этот skill проверяет наличие и статус review comments, но не разбирает их как
задание на исправление и не реализует изменения по ним.

## Источники

Используй активные правила:

1. `AGENTS.md`
2. `docs/task-delivery-workflow.md`
3. `.github/PULL_REQUEST_TEMPLATE.md`
4. GitHub PR state и CI checks, если нужна live-проверка

Если `docs/agent-routing.md` или `docs/agent-docs-lifecycle.md` существуют только
как локальные черновики, скажи об этом и не используй их как активные правила.

## Read-only проверки

Проверь только то, что нужно для текущего вопроса:

- PR number и target branch;
- draft или ready;
- base/head branch;
- текущее состояние CI;
- текущее состояние review;
- mergeability как справочный сигнал;
- PR body по шаблону проекта;
- поля готовности;
- наличие guard-полей для `main` PR после staging;
- соответствует ли PR текущему delivery level.

## CI и review

Skill может прочитать текущее состояние CI и review по явной задаче пользователя.

Skill не запускает ожидание CI, не мониторит проверки до зелёного состояния и не
считает CI подтверждённым по памяти.

`mergeability` используется только как справочный сигнал. Решение о следующем
checkpoint строится по delivery rules, CI/review status и полям готовности, а не
только по mergeability.

## Обязательные правила

Нетривиальный PR сначала создаётся как `draft`.

После создания draft PR агент останавливается на checkpoint и сообщает ссылку,
target branch и статус.

CI не отслеживается и review не выполняется без отдельной команды пользователя.

После зелёного CI на draft PR, валидных полей готовности и отсутствия blocker-ов
следующий checkpoint — перед ready.

После ready следующий checkpoint — перед пользовательским merge.

PR в `staging` не включает merge в `staging`, staging smoke, PR в `main` или merge
в `main`.

## PR body

При проверке PR перед handoff проверь, что body содержит русские разделы и
допустимые поля готовности из `docs/task-delivery-workflow.md`.

Минимальные поля:

- `Тип изменения: кодовое | документационное | процессное | hotfix`
- `Существенный stream: да | нет`
- `Уровень доставки: PR в staging | через staging | до merge в main | документационный путь`
- `Локальный MVP: принят | не требуется`
- `Операторская приёмка: принята | не требуется`
- `Авторская самопроверка: выполнена`
- `Блокеры: отсутствуют`
- `Принятый риск: отсутствует | принят: <краткая причина>`

Для runtime/code PR в `main` после staging должны быть точные строки:

```text
Staging PR: #NNN
Staging smoke: https://github.com/.../actions/runs/...
```

или:

```text
Staging PRs: #NNN, #MMM
Staging smoke: https://github.com/.../actions/runs/...
```

Свободная проза вместо этих строк не считается выполнением правила.

## Следующий checkpoint

Назови следующий допустимый checkpoint, но не выполняй его.

Типовые варианты:

- draft PR создан -> пользователь или reviewer проверяет PR;
- draft PR + CI не проверен -> рекомендовать отдельную проверку CI;
- draft PR + CI зелёный + поля готовности валидны + blocker-ов нет -> checkpoint перед ready;
- PR ready -> пользователь выполняет merge;
- merge выполнен пользователем -> агент может проверить результат и cleanup по отдельной команде;
- PR body невалиден -> рекомендовать отдельный шаг на исправление PR metadata,
  не исправляя metadata из этого skill.

## Результат

Верни короткий отчёт:

- PR;
- base/head;
- draft/ready;
- CI status;
- review status;
- PR body/readiness status;
- текущий delivery level;
- blockers;
- следующий checkpoint;
- нужна ли отдельная команда пользователя.
