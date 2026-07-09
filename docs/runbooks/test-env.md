# Runbook тестового окружения

Этот runbook фиксирует безопасный тестовый контур Laravel/PHPUnit.

## Инвариант

Тесты не запускаются против рабочей, recovery, staging или production database.
Разрешены только:

1. `sqlite` database `:memory:`;
2. отдельная database, имя которой явно содержит `test` или
   `testing`.

`tests/bootstrap.php` останавливает тесты, если effective database target не
похож на test database или совпадает с известным runtime-именем. Проверка
учитывает `DB_URL`: Laravel применяет его с приоритетом над `DB_DATABASE`.

## Текущие источники

1. `phpunit.xml` форсит `APP_ENV=testing`.
2. `phpunit.xml` форсит `DB_DATABASE=abrikosoff_connector_test`.
3. `phpunit.xml` задаёт `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array` и
   `CACHE_STORE=array`, но эти значения сейчас не помечены `force="true"`.
4. `.env.testing.example` содержит безопасные значения для локального тестового
   контура.
5. `tests/bootstrap.php` подгружает `.env.testing`, если файл существует. Если
   его нет, сначала загружается `.env.testing.example`, а затем недостающие
   значения из `.env` — в той же последовательности, которую увидит приложение.
6. Bootstrap разбирает effective `DB_URL` тем же Laravel parser-ом и проверяет
   итоговое имя database до запуска тестов, включая PostgreSQL-параметр
   `connect_via_database`.
7. Тесты не запускаются при существующем Laravel config cache. Перед запуском
   нужно выполнить `php artisan config:clear`.

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
4. Проверь `DB_URL` в том же shell/container. Если он задан, именно его database
   path имеет приоритет над `DB_DATABASE`.
5. Выполни `php artisan config:clear`, чтобы тесты не использовали сохранённую
   runtime-конфигурацию.
6. Не запускай `php artisan test`, если effective database target указывает на
   `abrikosoff_connector`, recovery/restored database, staging или production.

Для локального контура можно создать `.env.testing` из примера:

```bash
cp .env.testing.example .env.testing
```

## Срабатывание guard

Если bootstrap останавливает тесты с сообщением:

```text
Refusing to run tests against non-test database "<name>" resolved from <source>. Configure a dedicated *_test database.
```

это штатная защита. Нужно исправить `DB_DATABASE`, безопасно настроить или убрать
`DB_URL`, а не отключать guard.

## Что документировать в PR

Если PR меняет поведение, тест должен быть добавлен или обновлён. Если PR меняет
только документацию, достаточно author self-check и проверки внутренних ссылок.

Если PR меняет test-env, в описании PR нужно указать:

1. effective test database и наличие/отсутствие `DB_URL` без публикации URL или
   credentials;
2. какие env-переменные форсятся или наследуются;
3. какие тесты запускались;
4. не затронуты ли runtime/staging/prod database.
