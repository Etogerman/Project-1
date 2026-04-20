# Активные внешние ТЗ

Этот файл — локальный реестр существенных stream-ов реализации, которые
реально открыты в основном репозитории и уже имеют зафиксированные:

1. `Spec repo`
2. `Spec doc`
3. `Spec revision`

Если активных существенных stream-ов сейчас нет, это должно быть написано явно.

## Текущее состояние

Сейчас в основном репозитории открыт один существенный stream с
зафиксированным внешним `Spec revision`.

### 1. Bitrix24 Open Lines manual reply service actor

- `Spec repo`: `Etogerman/Project-1-specs`
- `Spec doc`: `streams/tz-bitrix24-openlines-manual-reply-service-actor.md`
- `Spec revision`: `8d2858fa1cf119bc49a660826658f438c3eda3d8`
- внешний статус документа: `active`
- локальный статус stream-а в основном repo: `opened`
- issue tracker: [#381](https://github.com/Etogerman/Project-1/issues/381)

Что это означает сейчас:

1. versioned ТЗ продолжает уже открытый active spec stream;
2. frozen baseline follow-up stream-а:
   - `origin/main`
   - merge commit `1b2c39770894e9b42375d32ce1a89edcfb4b3e91`
   - merge PR `#392`
3. preparatory `Slice 0A` уже закрыт;
4. `Slice 1A` уже материализован и прошёл delivery path;
5. текущий активный implementation slice по spec — `Slice 1B`;
6. `Slice 1B` покрывает:
   - remote-chat reuse runtime change
   - targeted tests
   - author self-check
7. execution ceiling текущего active slice:
   - `draft PR` в `staging`
8. code stream по `Slice 1B` ещё не стартовал и требует отдельной явной команды пользователя.

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
