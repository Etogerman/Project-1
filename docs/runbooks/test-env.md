# Runbook тестового окружения

Этот runbook фиксирует безопасный тестовый контур Laravel/PHPUnit.

## Инвариант

Тесты не запускаются против рабочей, recovery, staging или production database.
Разрешены только:

1. `sqlite` database `:memory:`;
2. отдельная database, имя которой явно содержит `test` или
   `testing`.

`tests/bootstrap.php` останавливает тесты, если effective `DB_DATABASE` не
похожа на test database или совпадает с известным runtime-именем.

## Текущие источники

1. `phpunit.xml` форсит `APP_ENV=testing`.
2. `phpunit.xml` форсит `DB_DATABASE=abrikosoff_connector_test`.
3. `phpunit.xml` задаёт `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array` и
   `CACHE_STORE=array`, но эти значения сейчас не помечены `force="true"`.
4. `.env.testing.example` содержит безопасные значения для локального тестового
   контура.
5. `tests/bootstrap.php` подгружает `.env.testing.example`, если
   `APP_ENV=testing` и локального `.env.testing` нет.

Важно: `.devcontainer/init.sql` сейчас создаёт `laravel_testing`, а `phpunit.xml`
использует `abrikosoff_connector_test`. Это отдельный code/config follow-up, а не
часть docs-only процесса.

## Перед запуском тестов

1. Проверь, что существует отдельная test database.
2. Если используешь Docker/devcontainer, проверь, что имя созданной test database
   совпадает с effective `DB_DATABASE` для PHPUnit.
3. Если тесты ведут себя как runtime, проверь effective `QUEUE_CONNECTION`,
   `SESSION_DRIVER` и `CACHE_STORE`: внешнее окружение может переопределить
   значения из `phpunit.xml`.
4. Не запускай `php artisan test`, если effective `DB_DATABASE` указывает на
   `abrikosoff_connector`, recovery/restored database, staging или production.

Для локального контура можно создать `.env.testing` из примера:

```bash
cp .env.testing.example .env.testing
```

## Срабатывание guard

Если bootstrap останавливает тесты с сообщением:

```text
Refusing to run tests against non-test database "<name>". Configure DB_DATABASE to a dedicated *_test database.
```

это штатная защита. Нужно исправить test database config, а не отключать guard.

## Что документировать в PR

Если PR меняет поведение, тест должен быть добавлен или обновлён. Если PR меняет
только документацию, достаточно author self-check и проверки внутренних ссылок.

Если PR меняет test-env, в описании PR нужно указать:

1. effective test database;
2. какие env-переменные форсятся или наследуются;
3. какие тесты запускались;
4. не затронуты ли runtime/staging/prod database.
