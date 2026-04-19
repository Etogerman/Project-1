# Активные внешние ТЗ

Этот файл — локальный реестр существенных stream-ов реализации, которые
реально открыты в основном репозитории и уже имеют зафиксированные:

1. `Spec repo`
2. `Spec doc`
3. `Spec revision`

Если активных существенных stream-ов сейчас нет, это должно быть написано явно.

## Текущее состояние

Сейчас в основном репозитории зафиксированы два открытых существенных stream-а:

### Deployment-safe avatar storage

- `Spec repo`: `Etogerman/Project-1-specs`
- `Spec doc`: `streams/tz-deployment-safe-avatar-storage.md`
- `Spec revision`: `4c596b1dc3b662f28bc015ceeed5c806d48f65d2`
- внешний статус документа: `planned`
- локальный смысл: source of truth для следующего implementation stream по
  deployment-safe хранению аватарок уже зафиксирован и открыт в основном repo

### Critical fixes v7

- `Spec repo`: `Etogerman/Project-1-specs`
- `Spec doc`: `streams/tz-critical-fixes-v7.md`
- `Spec revision`: `320c45169e52e1f39b1fe6de910c0701a8ebbb84`
- внешний статус документа: `partial`
- текущий активный slice при продолжении stream-а:
  `Slice 2A: Auto-Reply Duplicate Re-Dispatch Guard`
- локальный смысл: stream открыт в основном repo как следующий active stream;
  запуск implementation path требует отдельной явной делегации пользователя и
  согласования execution ceiling

## Как использовать этот файл

1. Если нужно понять, какой внешний spec реально открыт сейчас, сначала смотри этот файл.
2. Если здесь указано, что активных stream-ов нет, новый существенный stream нужно сначала открыть во внешнем `specs`-контуре и зафиксировать `Spec repo / Spec doc / Spec revision`.
3. После открытия нового существенного stream-а этот файл обновляется отдельным коротким `docs-only` шагом.

## Связанные документы

1. [docs/reference/specs-pointer.md](/Users/abrikosov/Documents/Проект-1/docs/reference/specs-pointer.md)
2. [docs/task-delivery-workflow.md](/Users/abrikosov/Documents/Проект-1/docs/task-delivery-workflow.md)
3. [docs/tz/README.md](/Users/abrikosov/Documents/Проект-1/docs/tz/README.md)
