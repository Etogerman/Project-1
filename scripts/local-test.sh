#!/bin/sh
# Локальный прогон тестов с CI-подобным окружением.
#
# Зачем: env живого dev-контейнера (APP_ENV=local, QUEUE_CONNECTION=database,
# CACHE_STORE=database) перекрывает phpunit.xml и ломает сценарные тесты
# (джобы не выполняются синхронно; ScenarioRegistry не видит database-сценарии).
# Этот скрипт выравнивает окружение с CI (php-artisan-test.yml).
#
# Использование:
#   sh scripts/local-test.sh                          # весь набор
#   sh scripts/local-test.sh tests/Feature/FooTest.php
#   sh scripts/local-test.sh --filter=some_test_name
set -eu

CONTAINER="${LOCAL_TEST_CONTAINER:-abrikosoff-connector-recovery-dev-1}"
TEST_DB="${LOCAL_TEST_DB:-abrikosoff_connector_recovery_test}"

exec docker exec \
    -e APP_ENV=testing \
    -e DB_DATABASE="$TEST_DB" \
    -e CACHE_STORE=array \
    -e QUEUE_CONNECTION=sync \
    -e SESSION_DRIVER=array \
    "$CONTAINER" php artisan test "$@"
