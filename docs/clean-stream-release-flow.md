# Clean Stream Release Flow

## Цель

Зафиксировать рабочий процесс, по которому изменения режутся из mixed state
в отдельные clean streams, публикуются независимыми ветками и проходят
релизную проверку без смешения scope.

## Базовые правила

- источником для новых extraction считается `origin/main`
- старая mixed-ветка не считается безопасной базой для новых потоков
- перед новым extraction сначала делается residual diff audit против `origin/main`
- каждый clean stream публикуется отдельной веткой и отдельным draft PR
- integration branch нужна только когда реально требуется общий staging candidate
- auto-deploy не закрывает релиз без post-deploy smoke-check

## Residual diff audit

Перед новым extraction остаток в старой mixed-ветке раскладывается на 4 корзины:

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
4. Сделать технический extraction в отдельный worktree от `origin/main`.
5. Проверить, что git scope ветки совпадает с include list.
6. Прогнать локальную verification ровно на том слое, который менялся.
7. Зафиксировать поток отдельным коммитом.

## Publish flow

Для каждого clean stream:

1. push отдельной ветки
2. открытие отдельного draft PR
3. review и локальная/CI verification внутри этого PR

Не рекомендуется:

- собирать несколько уже очищенных потоков в один общий PR
- публиковать mixed scope под видом одного change-set

## Integration branch

`integration/*` используется только если нужен общий staging candidate.

Когда integration branch уместна:

- нужно проверить совместимость нескольких уже очищенных потоков
- staging должен видеть итоговый combined scope до merge в `main`

Когда integration branch не нужна:

- поток один и он уже проверяется отдельно
- staging и production auto-deploy идут прямо из отдельных PR после merge

## Post-deploy rule

Даже если deploy уходит автоматически в staging и production:

- релиз не считается закрытым, пока не пройден post-deploy smoke-check
- smoke-check проводится по реально рабочим окружениям текущего release flow
- если staging отсутствует или не поддерживает нужный интеграционный контур,
  production-only smoke считается корректным временным режимом
- destructive maintenance-команды не запускаются просто ради проверки

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

- small clean stream
- отдельная verification
- отдельный draft PR

Только потом:

- общий release decision
- staging/prod verification

Это дешевле и безопаснее, чем пытаться тащить всю mixed-ветку до конца как
один большой пакет.
