# Clean Stream Release Flow

> Этот документ не является вторым canonical workflow-документом.
> Основной source of truth по delivery-level, control points и publish-path
> находится в [docs/task-delivery-workflow.md](/Users/abrikosov/Documents/Проект-1/docs/task-delivery-workflow.md).
> Здесь остаётся только узкий appendix по clean-stream extraction, mixed-state
> audit и release hygiene.

## Цель

Зафиксировать рабочий процесс, по которому изменения режутся из mixed state
в отдельные clean streams, публикуются независимыми ветками и проходят
релизную проверку без смешения scope.

## Базовые правила

- одновременно допускается только один активный implementation stream
- источником для новых extraction считается `origin/main`
- старая mixed-ветка не считается безопасной базой для новых потоков
- residual diff audit обязателен только когда extraction идёт из mixed или
  reference-only контекста
- каждый clean stream публикуется отдельной веткой и отдельным draft PR
- `staging` является основным интеграционным gate
- `main` не считается staging candidate сам по себе
- integration branch нужна только когда реально требуется общий staging candidate
- auto-deploy не закрывает релиз без post-deploy smoke-check

## One active stream rule

Новый development step не начинается, пока предыдущий change-set не закрыт
до конца.

Новый stream запрещён, если существует хотя бы один незавершённый хвост:

- локальный незапубликованный diff по текущему шагу
- открытый draft PR или обычный PR в `staging` или `main`
- смерженный PR в `staging`, у которого ещё не закрыты staging deploy или staging smoke
- staging smoke завершён, но тот же validated diff ещё не проведён отдельным PR в `main`
- смерженный PR в `main`, который ещё не выкачен в production, если production входит в release flow
- завершившийся production deploy без закрытого production smoke-check
- production smoke (code/release) или merge (docs/process) без выполненного
  основания закрытия
- выполненное основание закрытия без `Issue Closure` или применимого `Spec Closure`
- незакрытый branch hygiene tail: merged remote/local ветки, stale worktree или локальные ветки без upstream, не классифицированные как допустимый остаток

Пока такой хвост существует, допустимы только четыре действия:

- доводить тот же самый шаг до завершения
- делать read-only анализ без новых изменений
- выполнять docs-only/spec/admin follow-up, если он не меняет runtime/code diff
  и не маскирует незакрытый release-хвост
- по явной команде пользователя закрыть, отменить или отложить текущий шаг

Перед запуском нового clean stream обязателен preflight-check:

1. проверить, есть ли активный PR по предыдущему шагу
2. проверить, есть ли незавершённый staging deploy или staging smoke
3. проверить, есть ли незавершённый production deploy или production smoke
4. проверить основание закрытия, `Issue Closure` и применимый `Spec Closure`
5. проверить branch hygiene tail: merged remote/local ветки, stale worktree и локальные ветки без upstream
6. если хвост найден, остановить новую реализацию и явно сообщить об этом
   пользователю

Переход к следующему code implementation step разрешён только если branch hygiene
tail закрыт cleanup-ом или явно принят пользователем как временное исключение
с перечислением веток, и выполнено одно из условий:

- предыдущий code/release шаг прошёл весь release flow, production smoke и
  принятие результата/риска; оба closure-checkpoint имеют
  `completed | not_required`
- предыдущий docs/process PR смержен, результат проверен, оба closure-checkpoint
  имеют `completed | not_required`
- предыдущий PR закрыт без merge
- пользователь явно подтвердил, что предыдущий шаг отменяется или откладывается

Docs-only/spec/admin follow-up не считается новым code implementation step,
если он не затрагивает runtime/code diff и не расширяет текущий release scope.

## Residual diff audit

Residual diff audit нужен не для каждого нового шага, а для случаев, когда
новый поток режется из mixed/reference контекста и есть риск спутать живой
scope с drift относительно `origin/main`.

Перед таким extraction остаток в старой mixed-ветке раскладывается на 4 корзины:

- `rollback drift`
- `real residual changes`
- `env/docs reconcile`
- `ops/tooling leftovers`

Смысл аудита:

- не спутать реально живые изменения с отставанием старой ветки от `origin/main`
- не вытащить в новый stream то, что уже попало в `main`
- не публиковать accidental rollback как новую фичу

## Clean stream extraction

Каждый новый поток проходит одинаковую последовательность:

1. Зафиксировать source, base и target branch.
2. Согласовать точный include list.
3. Явно отметить exclude list и deferred files.
4. Создать новую ветку от свежего `main`.
5. Использовать отдельный `worktree` только если он реально нужен технически.
6. Проверить, что git scope ветки совпадает с include list.
7. Локальную verification запускать только по явной команде пользователя.
8. Зафиксировать поток отдельным коммитом.

## Publish flow

Публикация clean stream в `staging` по умолчанию выполняется после достижения локально тестируемого feature increment-а текущего stream-а и явного операторского решения о выкладке. Завершение отдельного micro-step, внутреннего slice или самого локального MVP без операторского решения само по себе не считается достаточным основанием для выхода в `staging`.

Для каждого clean stream:

1. push отдельной ветки
2. открытие отдельного draft PR в `staging`
3. контрольная точка: агент сообщает ссылку на PR, целевую ветку и статус
4. GitHub checks и review внутри PR проверяет пользователь самостоятельно или с помощью Copilot / другого ревьюера
5. агент проверяет CI или выполняет внутренний review только по отдельной команде пользователя
6. перевод staging PR в `Ready for review` только по отдельному выбору пользователя
7. merge staging PR только по отдельному выбору пользователя
8. staging deploy и staging smoke
9. отдельный PR в `main` из проверенного diff
10. GitHub checks и review в PR в `main` проверяет пользователь самостоятельно или с помощью Copilot / другого ревьюера
11. merge в `main` только по отдельному выбору пользователя

Правило оформления:

- заголовок PR на GitHub пишется на русском языке
- описание PR на GitHub пишется на русском языке
- сопроводительные GitHub-комментарии по change-set тоже пишутся на русском языке
- сообщения коммитов пишутся на русском языке
- названия веток формулируются в русской `ASCII`-транслитерации с префиксом `codex/`

## Delegated publish mode

По умолчанию агент работает в консервативном режиме:

- `commit`, `push` и создание `draft PR` требуют отдельной явной команды

Если пользователь заранее явно делегировал эти права в текущем диалоге, агент
может без отдельного подтверждения на каждый шаг:

- сделать `commit`
- сделать `push`
- создать `draft PR`

Такая делегация не распространяется на:

- `Ready for review`
- `merge`
- `rebase`
- `force-push`
- действия после конфликтов
- локальные тесты и verification, если они не разрешены отдельно

Не рекомендуется:

- собирать несколько уже очищенных потоков в один общий PR
- публиковать mixed scope под видом одного change-set

## Integration branch

`integration/*` используется только если нужен временный общий staging candidate
поверх обычной ветки `staging`.

Когда integration branch уместна:

- нужно проверить совместимость нескольких уже очищенных потоков
- staging должен видеть итоговый combined scope до обычного продвижения
  validated diff в `main`

Когда integration branch не нужна:

- поток один и он уже проверяется отдельно
- staging сам уже является интеграционным gate
- production остаётся отдельным ручным шагом

## Post-deploy rule

Если staging deploy уходит автоматически после push в `staging`:

- релиз не считается закрытым, пока не пройден post-deploy smoke-check
- production smoke ведёт к принятию результата, затем к closure-route из
  `docs/task-delivery-workflow.md`; cleanup до `Issue Closure` и применимого
  `Spec Closure` недоступен
- smoke-check проводится по реально рабочим окружениям текущего release flow
- если production не автодеплоится, production smoke запускается только после
  фактического production deploy
- automatic smoke по production без нового deploy не должен считаться
  подтверждением релиза
- destructive maintenance-команды не запускаются просто ради проверки

Для docs/process stream тот же route начинается после merge и проверки результата.

Подробный checklist см. в `docs/post-deploy-smoke.md`.

## Mixed branch policy

После выделения clean streams старая mixed-ветка:

- не используется как прямой source для новых release-веток
- служит только как `reference-only`
- может быть повторно проаудирована, если нужно понять остаток

Если в mixed-ветке остаётся новый полезный scope, он сначала должен пройти
новый audit against `origin/main`, а не переноситься автоматически по инерции.

## Практический принцип

Сначала:

- read-only audit
- findings
- ТЗ
- локально тестируемый feature increment
- операторское решение о выкладке
- отдельный commit
- отдельный push
- отдельный draft PR
- пользовательская проверка GitHub checks и review самостоятельно или с помощью Copilot / другого ревьюера

Только потом:

- `Ready for review`
- внутренний review агентом, если пользователь отдельно поручил его выполнить
- merge
- deploy
- post-deploy smoke-check
- принятие результата -> Issue Closure -> Spec Closure -> cleanup

Это дешевле и безопаснее, чем пытаться тащить всю mixed-ветку до конца как
один большой пакет.
