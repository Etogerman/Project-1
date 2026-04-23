# Указатель на внешние ТЗ

Существенные stream-ы используют внешний `specs`-контур. Этот документ нужен,
чтобы внешний источник истины был discoverable без чата.

## Где живёт внешний specs-репозиторий

Текущий внешний репозиторий:

1. GitHub: `Etogerman/Project-1-specs`
2. локальный checkout на этой машине: [/Users/abrikosov/Documents/Project-1-specs](/Users/abrikosov/Documents/Project-1-specs)

## Что означают обязательные поля

Для существенного stream-а должны быть явно зафиксированы:

1. `Spec repo` — какой внешний репозиторий используется
2. `Spec doc` — какой конкретный документ является источником истины
3. `Spec revision` — какой commit/hash этого репозитория зафиксирован для implementation stream

Минимальный блок:

```md
Spec repo: Etogerman/Project-1-specs
Spec doc: streams/<document>.md
Spec revision: <commit-hash>
```

## Где что искать

Структура [Project-1-specs](/Users/abrikosov/Documents/Project-1-specs):

1. `streams/` — текущие stream-документы; их канонический текущий статус фиксируется во внешнем [streams/README.md](/Users/abrikosov/Documents/Project-1-specs/streams/README.md)
2. `archive/` — закрытые ТЗ
3. `reference/` — перенесённые или historical документы
4. `templates/` — шаблоны для новых ТЗ

Практическое правило:

1. сначала смотреть `streams/`
2. если нужен старый контекст — `reference/`
3. закрытые документы искать в `archive/`

## Как проверить, что реально открыто в основном repo

Внешний `specs`-repo хранит общий контур stream-документов, но вопрос
`что действительно открыто сейчас в основном репозитории` решается через
локальный реестр:

1. [docs/reference/active-specs.md](/Users/abrikosov/Documents/Проект-1/docs/reference/active-specs.md)

Если в этом файле явно указано, что активных существенных stream-ов нет, значит
в основном repo сейчас нет открытого существенного stream-а реализации с
зафиксированным `Spec revision`.

## Как найти active Spec doc

Сначала открой внешний [streams/README.md](/Users/abrikosov/Documents/Project-1-specs/streams/README.md).

Практический порядок такой:

1. сначала смотри статус документа в `streams/README.md`
2. если уже открыт существенный stream, ориентируйся на документ, который там явно помечен как текущий рабочий
3. если открывается новый stream, сначала выбери документ и только потом фиксируй новый `Spec revision`

На этой машине можно начать так:

```bash
cd /Users/abrikosov/Documents/Project-1-specs
sed -n '1,200p' streams/README.md
find streams -maxdepth 2 -type f
```

Если документ уже согласован, но находится не в `streams/`, это должно быть
явно проговорено в задаче.

## Как фиксировать Spec revision

После согласования документа зафиксируй точный revision внешнего repo:

```bash
git -C /Users/abrikosov/Documents/Project-1-specs rev-parse HEAD
```

Полученный hash и есть текущий `Spec revision`.

Если по ходу реализации ТЗ меняется:

1. сначала меняется документ во внешнем `specs`-repo
2. потом фиксируется новый `Spec revision`
3. только после этого продолжается implementation stream

## Текущий опубликованный spec-only handoff

На `2026-04-23` во внешнем spec-repo уже опубликован следующий substantial
stream-family, но implementation stream в основном repo по нему ещё не открыт:

```md
Spec repo: Etogerman/Project-1-specs
Spec doc: streams/telegram-account/tz-core-connection-model-and-telegram-account-1to1-v1.md
Spec revision: fe8dacaa4f645a53fd4d7729c6cfd9b069913826
```

Практический смысл этой фиксации:

1. внешний `Spec doc` уже versioned и может использоваться как source of truth
   для будущего stream-а
2. сам implementation stream в основном repo ещё не открыт, пока не выполнен
   отдельный stream-level handoff и не принято process-решение по sequencing
3. если в [docs/reference/active-specs.md](/Users/abrikosov/Documents/Проект-1/docs/reference/active-specs.md)
   уже есть другой `opened` substantial stream, новый implementation stream по
   этому документу не начинается автоматически

## Как закрывать внешний spec

После закрытия acceptance существенного stream-а нужно синхронизировать весь
spec-контур, а не только остановить code/runtime delivery.

Практический порядок такой:

1. сверить фактический runtime и delivery-результат с текущим `Spec doc`
2. если stream всё ещё открыт, обновить статус документа до `active` или `partial`
3. если acceptance документа закрыт полностью, перевести документ как минимум в `implemented`
4. отдельно обновить канонический статус в `streams/README.md`
5. синхронизировать локальный [docs/reference/active-specs.md](/Users/abrikosov/Documents/Проект-1/docs/reference/active-specs.md): в нём должны оставаться только реально открытые существенные stream-ы
6. если архивирование не делается в том же шаге, оставить явный follow-up `archive pending` и не возвращать документ в `planned`
7. отдельным `docs-only` шагом перенести полностью закрытый документ в `archive/`

Жёсткое правило:

- статус `planned` недопустим для документа, acceptance которого уже
  материализован в основном repo

## Как ссылаться на внешний spec в работе

В чате, commit/PR и delivery-контуре полезно использовать один и тот же блок:

```md
Spec repo: Etogerman/Project-1-specs
Spec doc: streams/<document>.md
Spec revision: <commit-hash>
```

Если stream маленький и несущественный, ТЗ может фиксироваться в чате. Для
существенного stream-а chat сам по себе не заменяет внешний `specs`-контур.

## Связанные документы

1. [AGENTS.md](/Users/abrikosov/Documents/Проект-1/AGENTS.md)
2. [docs/task-delivery-workflow.md](/Users/abrikosov/Documents/Проект-1/docs/task-delivery-workflow.md)
3. [docs/tz/README.md](/Users/abrikosov/Documents/Проект-1/docs/tz/README.md)
4. [docs/reference/active-specs.md](/Users/abrikosov/Documents/Проект-1/docs/reference/active-specs.md)
