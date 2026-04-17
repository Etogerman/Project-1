# ТЗ

В этой папке могут временно лежать:

1. `active` — действующие ТЗ и rollout-документы по текущим stream-ам
2. `pre-existing` — документы, чьи статусы этот конкретный `docs-only` slice не нормализует
3. `reference / legacy` — прежние или superseded документы, которые уже явно признаны неактивными, но ещё не вынесены в архив

Закрытые ТЗ и cleanup superseded-документов нужно переносить в `docs/tz/archive/`
отдельным `docs-only` шагом после полного закрытия implementation stream по
регламенту проекта.

## Active

- `dialog-identity-anchor-rollout.md`

## Pre-existing

- `tz-inbound-improvements-plan.md`
- `tz-bitrix24-openlines-blocked-dialog-follow-up.md`
- `tz-collector-slash-command-during-active-flow.md`
- `tz-dialog-stage-kanban-and-automation.md`

Статус этих документов в рамках текущего slice не переопределяется.

## Appendix / inventory

- `dialog-identity-anchor-rollout-inventory.md`

## Архив

- архив ТЗ: `docs/tz/archive/`
