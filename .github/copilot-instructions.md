# Инструкции Copilot для Project-1

## Release process

- Для любых кодовых или runtime-изменений соблюдай staging-first маршрут:
  локальная реализация -> операторская приемка -> draft PR в `staging` -> зеленые проверки -> merge в `staging` -> staging smoke -> draft PR в `main` -> зеленые проверки -> merge в `main` -> ручной production deploy -> production smoke.
- Не предлагай прямой merge в `main` для кодовых или runtime-изменений без явного исключения от владельца проекта.
- Merge в `main` не является production deploy. Production deploy выполняется отдельно и вручную.
- Если PR направлен в `main` и меняет код, тесты, маршруты, миграции, сборку, Docker, frontend/backend runtime или сценарии, проверь, что описание PR содержит:
  - `Staging PR: #NNN`
  - `Staging smoke: https://...`
- Для `main` PR с runtime-изменениями проверь, что указанный `Staging PR` уже смержен, его merge commit входит в текущий `main` PR, а ссылка `Staging smoke` ведет на успешный GitHub Actions run для этого staging merge commit.
- Если staging-доказательств нет, явно напиши, что PR нарушает release process и должен сначала пройти через `staging`.
- При ревью всегда проверяй статус GitHub Actions job `release-process-guard`.

## Review focus

- Сначала ищи риски поведения, регрессии, пропущенные проверки и нарушения процесса релиза.
- Отмечай только конкретные проблемы с указанием файла и причины.
- Не ставь формальное одобрение процессу, если PR обходит `staging`.
- Для документационных или process-only изменений проверяй ясность формулировок и отсутствие противоречий с `AGENTS.md`.
