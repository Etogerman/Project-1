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

- `[telegram-account-gateway-deployment-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/telegram-account/tz-gateway-deployment-v1.md`; Spec revision: `82af0ac9da1dba04ba4938e131e4c08536f5db62`; status: `opened`; opened-at: `2026-04-26`
- `[telegram-account-outgoing-replies-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/telegram-account/tz-outgoing-replies-v1.md`; Spec revision: `e846c9ff32377cc062317de6ca5ce81dc0537d0c`; status: `opened`; opened-at: `2026-04-27`
- `[telegram-account-automated-replies-via-gateway-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/telegram-account/tz-automated-replies-via-gateway-v1.md`; Spec revision: `892c2f783e38383efc63e107c17fcbb1fc63eb92`; status: `opened`; opened-at: `2026-04-28`
- `[bitrix24-openlines-manual-reply-connector-mirror-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/tz-bitrix24-openlines-manual-reply-connector-mirror-v1.md`; Spec revision: `474c0576011e0e95dbcffd8a3276438e12f5c69d`; status: `opened`; opened-at: `2026-05-05`
- `[bitrix24-openlines-dynamic-route-registry-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/tz-bitrix24-openlines-dynamic-route-registry-v1.md`; Spec revision: `c16f2cdf0736bfc913dd9a03507ec4e5a2d57bc9`; status: `opened`; opened-at: `2026-05-10`
- `[admin-dialogs-contacts-search-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/tz-admin-dialogs-contacts-search-v1.md`; Spec revision: `4124718138fd317edd79a45b33feff334db1f3ca`; status: `opened`; opened-at: `2026-05-16`

## Связанные документы

1. [docs/task-delivery-workflow.md](../task-delivery-workflow.md)
