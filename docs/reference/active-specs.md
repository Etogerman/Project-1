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

- `[dialog-stage-remove-review-stage]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/tz-dialog-stage-remove-review-stage.md`; Spec revision: `dd811e41dab8b4653e3634140fd678435f332c8f`; status: `opened`; opened-at: `2026-04-25`
- `[bitrix24-admin-oauth-connect-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/tz-bitrix24-admin-oauth-connect-v1.md`; Spec revision: `4b3f4891e3f5511ff15136f76db94b1bc4b5037a`; status: `opened`; opened-at: `2026-04-26`
- `[telegram-account-outgoing-replies-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/telegram-account/tz-outgoing-replies-v1.md`; Spec revision: `e846c9ff32377cc062317de6ca5ce81dc0537d0c`; status: `opened`; opened-at: `2026-04-27`
- `[telegram-account-automated-replies-via-gateway-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/telegram-account/tz-automated-replies-via-gateway-v1.md`; Spec revision: `892c2f783e38383efc63e107c17fcbb1fc63eb92`; status: `opened`; opened-at: `2026-04-28`
- `[scenario-builder-green-start-blocks-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `streams/tz-scenario-builder-green-start-blocks-v1.md`; Spec revision: `c3644a389aa8fae7508f9a518c7fae3c436a7e39`; status: `opened`; opened-at: `2026-04-27`
- `[salebot-green-start-conditions-v1]` — Spec repo: `Etogerman/Project-1-specs`; Spec doc: `salebot/tz-green-start-conditions-v1.md`; Spec revision: `e6c219ee5609677daa3e45bbbe64cbf5b5eaf0a5`; status: `opened`; opened-at: `2026-04-28`

## Связанные документы

1. [docs/reference/specs-pointer.md](./specs-pointer.md)
2. [docs/task-delivery-workflow.md](../task-delivery-workflow.md)
3. [docs/tz/README.md](../tz/README.md)
