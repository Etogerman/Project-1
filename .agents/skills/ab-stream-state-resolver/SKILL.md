---
name: ab-stream-state-resolver
description: Resolve AB Connector active stream state, tails, changed-scope type, delivery level, execution ceiling, blockers, and next checkpoint before starting work, answering next-step questions, or advancing docs/code/release streams.
---

# Определение состояния stream-а

Этот skill помогает определить текущее состояние stream-а AB Connector перед
началом новой работы, рекомендацией следующего шага или продвижением текущего
docs/code/release stream.

Skill работает read-only. Он определяет состояние и называет следующий допустимый
шаг, но не выполняет этот шаг.

## Границы

Skill не создаёт, не редактирует и не удаляет файлы.

Skill не делает staging, commit, push, PR, пользовательский перевод PR в ready,
пользовательский merge, deploy или smoke.

Если после определения состояния нужен профильный маршрут, агент переходит к
соответствующему активному skill. Если skill ещё не активен, агент использует
профильный документ или запасной источник и сообщает это пользователю:

- PR/CI/review -> `ab-pr-ci-review`
- staging/main/production -> `ab-release-gates`
- ТЗ/Spec -> `ab-spec-workflow`
- Bitrix24/Open Lines/Telegram/MAX -> `ab-integration-gates`
- agent-docs -> `ab-connector-skill-authoring`, `docs/agent-docs-lifecycle.md`
- Laravel/Filament/Livewire/database -> `laravel-boost-context`

## Когда использовать

Используй этот skill, если пользователь спрашивает:

- какой следующий правильный шаг;
- можно ли начинать новый stream;
- есть ли хвост от предыдущей работы;
- текущие изменения относятся к docs-only, code/runtime, release, cleanup или agent-docs;
- достаточно ли чистая ветка или worktree для следующего шага;
- можно ли двигать PR/release/staging/main path дальше;
- как классифицировать текущий diff, commits, ветку, PR или delivery level.

## Источники

Используй активные правила в таком порядке:

1. `AGENTS.md`
2. `docs/task-delivery-workflow.md`
3. активные repo skills, относящиеся к выбранному маршруту
4. профильные документы по затронутой области

Если `docs/agent-routing.md` или `docs/agent-docs-lifecycle.md` существуют только
как локальные черновики, скажи об этом и не используй их как активные правила.

## Read-only проверки

Собирай только тот контекст, который нужен для ответа.

Обычно достаточно проверить:

- путь worktree;
- текущую ветку;
- `git status --short --branch`;
- изменённые, staged и untracked файлы;
- впереди или позади ветка относительно upstream;
- последние commits, если это нужно;
- список worktree, если есть риск смешения stream-ов.

Если нужно понять, есть ли PR-хвост, используй только read-only проверку через
GitHub или `gh`. Детальную проверку PR, CI, review, PR body и полей готовности
передавай в `ab-pr-ci-review`, если этот skill активен.

Не создавай, не редактируй, не переводи в ready, не merge, не закрывай и не
комментируй PR или Issue из этого skill.

Не используй `git add`, `git commit`, `git push` и другие команды, которые
меняют файлы, git-состояние, PR или внешние системы.

## Классификация

Классифицируй текущую работу по фактически затронутой области изменений, а не по имени ветки:

- `docs-only`
- `agent-docs`
- `spec/admin`
- `cleanup`
- `code/runtime`
- `release`
- `integration/runtime`

Bitrix24, Open Lines, Telegram, MAX, queues, scheduler, secrets, migrations,
staging, production, deploy, smoke, callbacks и webhooks считай более строгим
integration/runtime или release контуром, пока не доказано обратное.

## Поиск хвостов

Отдельно сообщай найденные хвосты:

- локальный diff;
- staged changes;
- untracked files;
- локальные commits без push;
- запушенная ветка без PR;
- открытый draft PR;
- PR, который ждёт CI или review;
- ready PR, который ждёт Copilot/reviewer review, технический вердикт агента по
  review или пользовательский merge;
- staging/main/release follow-up;
- production deploy или smoke follow-up;
- выполненное основание закрытия без завершённого `Issue Closure` или применимого
  `Spec Closure`;
- `issue/admin tail`: Issue, оставленная открытой после основания закрытия;
  после cleanup не блокирует новый stream;
- branch hygiene tail: слитая удалённая или локальная ветка, stale worktree или
  локальная ветка без upstream;
- spec/admin tail во внешнем репозитории документации;
- загрязнение ветки или worktree другим stream-ом.

Отсутствие запланированного skill не является blocker-ом, если активных правил и
запасных документов достаточно для текущего разрешённого шага.

## Состояние доставки

Определи:

1. тип текущей работы;
2. active stream, если он есть;
3. текущий delivery level из `docs/task-delivery-workflow.md`;
4. execution ceiling, который делегировал пользователь;
5. статус локального MVP, если применимо;
6. статус операторской приёмки, если применимо;
7. нужны ли сейчас Spec repo / Spec doc / Spec revision;
8. состояния `Issue Closure` и применимого `Spec Closure`, если stream уже дошёл
   до closure checkpoint;
9. требует ли следующий шаг отдельной команды пользователя.

Не выводи следующий delivery-шаг только из топологии веток.

## Правила следующего шага

Для docs-only или agent-docs изменений при публикации рекомендуй docs-only path
из clean worktree от `origin/main`, если пользователь явно разрешил публикацию.

Для code/runtime stream не прыгай сразу в staging. По умолчанию локальная
реализация продолжается до согласованного локального MVP и операторской приёмки,
если пользователь явно не делегировал другой уровень.

После пользовательского ready следующий checkpoint — Copilot/reviewer review.
После Copilot/reviewer review агент читает review и даёт технический вердикт:
`готово к merge`, `нужны правки` или `нужен выбор пользователя`.
Вердикт `готово к merge` допустим только если агенту доступны review status,
comments/threads и CI status. Если данные недоступны, неполны или противоречат
друг другу, следующий шаг — получить данные или решение пользователя. Merge в
`staging` или `main` выполняет пользователь после вердикта агента `готово к
merge`. Агент может проверять до или после пользовательского merge только по
отдельной команде.

GitHub-контрольные действия выполняет пользователь: пользовательский перевод PR в
ready, пользовательский перевод PR обратно в draft, пользовательский merge,
close/reopen PR или Issue, approve/request changes, GitHub deploy/promote, environment
approval, branch protection, required checks и secrets. Агент не предлагает себя
исполнителем таких действий и в меню указывает пользователя.

После принятия production-результата/риска либо проверки merged docs/process PR
используй closure-route и records из `docs/task-delivery-workflow.md`.
Missing/mismatched evidence означает pending; после cleanup `left_open` —
неблокирующий `issue/admin tail`.

Исключение branch cleanup: после closure-checkpoint и явной команды пользователя
агент может удалить remote head branch слитого PR, если проверил `MERGED`,
соответствие branch этому PR, отсутствие другого открытого PR и статуса
защищённой ветки, активного stream-а или backup-ветки. Если любой признак нельзя
подтвердить read-only проверкой, агент не удаляет remote branch и показывает
blocker. PR, закрытый без merge, считается blocker-ом branch cleanup для агента:
агент показывает blocker и ждёт действия пользователя, а remote branch удаляет
только пользователь. GitHub auto-delete при merge не завершает cleanup/checkpoint.

Если правильный следующий шаг требует отдельной команды пользователя, скажи это
прямо.

## Результат

Верни короткий отчёт:

- тип работы;
- active stream;
- текущая ветка/worktree;
- краткая затронутая область изменений без списка файлов по умолчанию;
- найденные хвосты;
- текущий delivery level;
- execution ceiling;
- blockers или недостающие факты;
- `Issue Closure` / `Spec Closure` status, если применимо;
- следующий правильный шаг;
- требуется ли решение пользователя.

Не выполняй следующий шаг из этого skill. Только назови его и укажи, какое
разрешение нужно.
