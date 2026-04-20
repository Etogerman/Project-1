# Активные внешние ТЗ

Этот файл — локальный реестр существенных stream-ов реализации, которые
реально открыты в основном репозитории и уже имеют зафиксированные:

1. `Spec repo`
2. `Spec doc`
3. `Spec revision`

Если активных существенных stream-ов сейчас нет, это должно быть написано явно.

## Текущее состояние

Сейчас в основном репозитории зафиксирован один открытый существенный stream:

### Rollback phase 1a Open Lines switch для manual reply на staging

- `Spec repo`: `Etogerman/Project-1-specs`
- `Spec doc`: `streams/tz-bitrix24-manual-reply-phase-1a-rollback-staging.md`
- `Spec revision`: `f8bb34db81a157c231bdbce84b33052abbccc2e8`
- внешний статус документа: `active`
- текущий активный slice:
  `Rollback Slice A: revert #321 + revert #317 + transition quarantine`
- execution ceiling текущего slice:
  `ready PR в staging`
- frozen baseline:
  - current staging baseline: `332e687669f8bbacdd4658ea2a6f58871ae3eba5`
  - rollback target `#321`: `f8e8d2f7e3276e9775d4234248947dbab07cdd31`
  - rollback target `#317`: `00d30bf09137e65a77eca96fd2eb832f6c6f5ff4`
- локальный смысл:
  source of truth для rollback stream по возврату manual reply к pre-phase-1a
  runtime на staging уже открыт; rollback PR доведён до `ready`, а `merge` в
  `staging` остаётся отдельным следующим шагом процесса; `main` и production
  в этот stream не входят

## Как использовать этот файл

1. Если нужно понять, какой внешний spec реально открыт сейчас, сначала смотри этот файл.
2. Если здесь указано, что активных stream-ов нет, новый существенный stream нужно сначала открыть во внешнем `specs`-контуре и зафиксировать `Spec repo / Spec doc / Spec revision`.
3. После открытия нового существенного stream-а этот файл обновляется отдельным коротким `docs-only` шагом.
4. Документы со статусом `planned` не должны фиксироваться в этом реестре; здесь допускаются только реально открытые существенные stream-ы.
5. После закрытия существенного stream-а запись из этого файла удаляется или заменяется новой актуальной записью отдельным `docs-only` шагом.

## Связанные документы

1. [docs/reference/specs-pointer.md](/Users/abrikosov/Documents/Проект-1/docs/reference/specs-pointer.md)
2. [docs/task-delivery-workflow.md](/Users/abrikosov/Documents/Проект-1/docs/task-delivery-workflow.md)
3. [docs/tz/README.md](/Users/abrikosov/Documents/Проект-1/docs/tz/README.md)
