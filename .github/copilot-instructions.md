# Инструкции Copilot для Project-1

## Release process

- Для любых кодовых или runtime-изменений соблюдай staging-first маршрут:
  локальная реализация -> операторская приемка -> draft PR в `staging` -> зеленые проверки -> merge в `staging` -> staging smoke -> draft PR в `main` -> зеленые проверки -> merge в `main` -> ручной production deploy -> production smoke.
- Не предлагай прямой merge в `main` для кодовых или runtime-изменений без явного исключения от владельца проекта.
- Merge в `main` не является production deploy. Production deploy выполняется отдельно и вручную.
- Если PR направлен в `main` и меняет код, тесты, маршруты, миграции, сборку, Docker, frontend/backend runtime или сценарии, проверь, что описание PR содержит:
  - `Staging PR: #NNN`
  - `Staging smoke: https://...`
- Для `main` PR с runtime-изменениями проверь, что указанный `Staging PR` уже смержен, текущий `main` PR содержит validated diff из этого staging PR, а не накопленное состояние ветки `staging`, и ссылка `Staging smoke` ведет на успешный GitHub Actions run для staging merge commit.
- Если staging-доказательств нет, явно напиши, что PR нарушает release process и должен сначала пройти через `staging`.
- При ревью всегда проверяй статус GitHub Actions job `release-process-guard`.
- После принятия production-результата/риска либо проверки merged docs/process PR следуй
  `docs/task-delivery-workflow.md`: `Issue Closure` -> `Spec Closure` -> cleanup.
- Closing references/keywords в PR или commits блокируют pre-merge verdict. Для
  `#NNN` без совпавших live-состояния и merged-PR record checkpoint pending;
  `не требуется` даёт `not_required`, `left_open` после cleanup — неблокирующий tail.
- Close/reopen Issue выполняет пользователь.

## Language policy

- Заголовки PR, описания PR и человекочитаемые GitHub-комментарии должны быть на русском языке.
- Review-комментарии Copilot, summary и финальные выводы по PR пиши на русском языке, если GitHub-интерфейс позволяет.
- Технические исключения допустимы только для кода, команд, имён веток, путей файлов, названий workflow, API, классов, методов, дословных логов, текстов ошибок и обязательных внешних меток вроде `Staging PR` и `Staging smoke`.
- Если технический фрагмент нужно оставить на английском, объяснение риска и рекомендацию всё равно пиши на русском языке.
- Не используй английские секции PR вроде `Summary`, `Why`, `Validation`, `Testing`, `Delivery note`; пиши `Что изменено`, `Почему`, `Проверки`, `Примечание по доставке`.

## Review focus

- Сначала ищи риски поведения, регрессии, пропущенные проверки и нарушения процесса релиза.
- Отмечай только конкретные проблемы с указанием файла и причины; не оставляй общие замечания без actionable вывода.
- Формулируй замечания как конкретный риск: что может сломаться, где именно и почему.
- Не ставь формальное одобрение процессу, если PR обходит `staging`.
- Для документационных или process-only изменений проверяй ясность формулировок,
  отсутствие противоречий с `AGENTS.md` и полный маршрут
  `проверка результата -> Issue Closure -> применимый Spec Closure -> cleanup`.
