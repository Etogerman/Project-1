---
name: ab-pr-ci-review
description: Inspect AB Connector PR, CI, review, draft/ready state, Russian PR title/body language, PR body guard fields, Spec fields, review verdict, and allowed next PR checkpoint without editing PRs, merging, or bypassing delivery gates.
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
- связанные GitHub Issues и сохранение их lineage между staging и main;
- следующим PR checkpoint.

Этот skill проверяет наличие и статус review comments. После Copilot/reviewer
review skill разбирает comments только для технического вердикта, но не
реализует изменения по ним. Если review status, comments/threads или CI status
недоступны либо неоднозначны, skill не даёт вердикт `готово к merge`.

## Источники

Используй активные правила:

1. `AGENTS.md`
2. `docs/task-delivery-workflow.md`
3. `.github/PULL_REQUEST_TEMPLATE.md`
4. `.github/scripts/ab-readiness-check.mjs`
5. `.github/scripts/release-process-guard.mjs`
6. GitHub PR state и CI checks, если нужна live-проверка

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
- русский title PR;
- русские разделы и человекочитаемый русский текст в PR body;
- PR body по шаблону проекта;
- поля готовности;
- Spec-поля и `Spec revision`, если они присутствуют;
- наличие guard-полей для `main` PR после staging;
- поле `Связанные задачи:` и, для runtime/code PR в `main`, точное совпадение
  его набора с объединением связанных задач из всех указанных staging PR;
- отсутствие `Closes`, `Fixes` и `Resolves` для связанных задач до конечной
  приёмки соответствующего delivery path;
- соответствует ли PR текущему delivery level.

## CI и review

Skill может прочитать текущее состояние CI по явной задаче пользователя, когда
пользователь вернул красный или неоднозначный CI агенту, либо когда после
Copilot/reviewer review нужен технический вердикт перед merge.

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
Обычную проверку CI на draft PR выполняет пользователь: если всё ок, он
переводит PR в ready; если есть ошибки, возвращает задачу агенту.

После пользовательского подтверждения зелёного CI на draft PR, валидных полей
готовности и отсутствия blocker-ов следующий checkpoint — пользователь переводит
PR в ready.

После пользовательского ready следующий checkpoint — Copilot/reviewer review.
После Copilot/reviewer review агент читает review и даёт технический вердикт:
`готово к merge`, `нужны правки` или `нужен выбор пользователя`.
После вердикта `готово к merge` следующий checkpoint — пользовательский merge.

Вердикт `готово к merge` допустим только если агенту доступны review status,
comments/threads и CI status. Если данные недоступны, неполны или противоречат
друг другу, следующий checkpoint — получить данные или решение пользователя.

Copilot/reviewer review считается выполненным только когда review завершён, не
находится в pending/in-progress состоянии и агент может прочитать актуальный
результат review.

PR в `staging` не включает merge в `staging`, staging smoke, PR в `main` или merge
в `main`.

## PR title и body

При проверке PR перед handoff проверь, что title написан на русском языке. Токен
`[codex]` и технические термины допустимы, но человекочитаемая часть title должна
быть русской.

Проверь, что body содержит русские разделы, человекочитаемый русский текст и
допустимые поля готовности из `docs/task-delivery-workflow.md`.

Перед созданием, handoff или исправлением PR metadata сверяй body с действующими
guard-скриптами `.github/scripts/ab-readiness-check.mjs` и
`.github/scripts/release-process-guard.mjs`, если они есть в текущей ветке. Не
полагайся только на шаблон PR: executable guard является более точным источником.

Минимальные поля:

- `Тип изменения: кодовое | документационное | процессное | hotfix`
- `Существенный stream: да | нет`
- `Уровень доставки: PR в staging | через staging | до merge в main | документационный путь`
- `Локальный MVP: принят | не требуется`
- `Операторская приёмка: принята | не требуется`
- `Авторская самопроверка: выполнена`
- `Блокеры: отсутствуют`
- `Принятый риск: отсутствует | принят: <краткая причина>`
- `Связанные задачи: #NNN[, #MMM] | не требуется`

Поле `Связанные задачи:` должно содержать только строгий список номеров через
запятую и пробел либо точное значение `не требуется`. URL, дубли номеров,
поясняющий текст и closing keywords (`Closes`, `Fixes`, `Resolves`) не считаются
валидной связью с задачами.

Spec-поля:

- Для docs-only, process-only и несущественного PR не добавляй `Spec repo:`,
  `Spec doc:` и `Spec revision:`, если реального внешнего `Spec revision` нет.
- Не пиши `Spec revision: не требуется`. Если поле `Spec revision:` присутствует,
  оно должно содержать конкретный commit hash длиной 7-40 hex-символов.
- Для substantial stream, publish/release boundary или PR со статусом
  `Spec pending` проверяй Spec-поля по `ab-spec-workflow` и активным guard-скриптам.

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

Для runtime/code PR в `main` набор в поле `Связанные задачи:` должен точно
совпадать с объединением наборов из всех перечисленных `Staging PR` / `Staging
PRs`. Пропуск и необъяснённое добавление задачи являются blocker-ом. Порядок
номеров значения не имеет.

## Следующий checkpoint

Назови следующий допустимый checkpoint, но не выполняй его.

Типовые варианты:

- draft PR создан -> пользователь или reviewer проверяет PR;
- draft PR + CI не проверен -> пользователь проверяет CI;
- draft PR + CI зелёный + поля готовности валидны + blocker-ов нет -> пользователь переводит PR в ready;
- draft PR + CI красный или неоднозначный -> пользователь возвращает задачу агенту, агент разбирает ошибку и предлагает исправление;
- PR ready -> Copilot/reviewer выполняет review;
- PR ready + Copilot/reviewer review выполнен + review status/comments/threads и CI status доступны + вердикт агента ещё не дан -> агент читает review и даёт технический вердикт;
- вердикт агента `готово к merge` -> пользователь выполняет merge;
- вердикт агента `нужны правки` -> рекомендовать исправление в текущем scope;
- вердикт агента `нужен выбор пользователя` -> показать риск и запросить решение пользователя;
- review status/comments/threads или CI status недоступны либо неоднозначны -> запросить недостающие данные или решение пользователя;
- merge выполнен пользователем -> агент может проверить результат и cleanup по отдельной команде;
- production deploy, production smoke и пользовательская приёмка завершены ->
  агент сверяет все связанные Issues, готовит по каждой вердикт и русский
  итоговый комментарий; закрывает или переоткрывает Issue только пользователь;
- docs/process-only PR смержен в `main` и результат merge проверен -> агент
  сверяет связанные Issues до cleanup; если поле содержит `не требуется`,
  reconciliation считается завершённым;
- связанная выполненная Issue остаётся открытой без явной причины -> считать её
  `issue/admin tail`, а не закрытым stream;
- PR body невалиден -> рекомендовать отдельный шаг на исправление PR metadata,
  не исправляя metadata из этого skill.

## Результат

Верни короткий отчёт:

- PR;
- base/head;
- draft/ready;
- CI status;
- review status;
- review verdict;
- PR body/readiness status;
- связанные задачи и status их lineage;
- наличие `issue/admin tail`, если проверка выполняется после конечной приёмки
  соответствующего delivery path;
- текущий delivery level;
- blockers;
- следующий checkpoint;
- нужна ли отдельная команда пользователя.
