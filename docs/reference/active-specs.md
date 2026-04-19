# Активные внешние ТЗ

Этот файл — локальный реестр существенных stream-ов реализации, которые
реально открыты в основном репозитории и уже имеют зафиксированные:

1. `Spec repo`
2. `Spec doc`
3. `Spec revision`

Если активных существенных stream-ов сейчас нет, это должно быть написано явно.

## Текущее состояние

Сейчас в основном репозитории зафиксирован один открытый существенный stream:

### Deployment-safe avatar storage

- `Spec repo`: `Etogerman/Project-1-specs`
- `Spec doc`: `streams/tz-deployment-safe-avatar-storage.md`
- `Spec revision`: `5d50917db8773fcb221002bf28b1a2f77a7ac61a`
- внешний статус документа: `partial`
- локальный смысл: source of truth для следующего implementation stream по
  deployment-safe хранению аватарок уже зафиксирован и открыт в основном repo

### Ещё не открытый stream: Critical fixes v7

- `Spec repo`: `Etogerman/Project-1-specs`
- `Spec doc`: `streams/tz-critical-fixes-v7.md`
- `Spec revision`: `320c45169e52e1f39b1fe6de910c0701a8ebbb84`
- внешний статус документа: `partial`
- следующий активный slice после отдельного открытия этого stream-а:
  `Slice 2A: Auto-Reply Duplicate Re-Dispatch Guard`
- локальный смысл: внешний spec уже опубликован и готов к следующему
  существенному stream-у, но сам stream ещё не открыт в основном repo и не
  считается active, пока не закрыт текущий active stream и не принято
  отдельное решение пользователя

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
