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
