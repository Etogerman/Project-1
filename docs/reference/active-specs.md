# Активные внешние ТЗ

Этот файл — локальный stream-level реестр существенных stream-ов, которые
реально открыты в основном репозитории.

Он не хранит slice-level state, не дублирует полный статус внешнего `Spec doc`
и не заменяет PR audit trail с блоком `Spec repo / Spec doc / Spec revision`.

## Схема записи

Одна запись на один открытый substantial stream:

- `[stream-name]` — Spec repo: `<repo-or-location>`; Spec doc: `<path-or-doc-name>`; Spec revision: `<commit-hash>`; status: `opened|blocked|closing`; opened-at: `YYYY-MM-DD`

## Правила

1. Здесь фиксируется только stream-level state.
2. Slice-level state живёт только во внешнем spec-repo.
3. Issue или task tracker хранит execution-state: blockers, next step, ссылки на PR и handoff notes.
4. Запись добавляется, обновляется или удаляется в том же открывающем/закрывающем шаге, где stream реально появляется или закрывается в основном repo.
5. Отдельный `docs-only` sync допустим как fallback, но не является default-требованием.
6. Если внешний spec-repo, нужный `Spec doc` или согласованная `Spec revision` недоступны, substantial stream paused.
7. Если активных substantial stream-ов нет, это указывается одной строкой: `- none`.

## Текущее состояние

- `[critical-fixes-v7]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/tz-critical-fixes-v7.md`; Spec revision: `aee1c51979465c32371b1c2fc33e22d9565d25c4`; status: `opened`; opened-at: `2026-04-23`

## Связанные документы

1. [docs/reference/specs-pointer.md](./specs-pointer.md)
2. [docs/task-delivery-workflow.md](../task-delivery-workflow.md)
3. [docs/tz/README.md](../tz/README.md)
