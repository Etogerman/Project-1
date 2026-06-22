# Резервное копирование

## Цель

Этот runbook описывает базовый backup-контур для AB Connector.
Первый слой покрывает PostgreSQL через внешние `pg_dump` / `pg_restore`
и не зависит от работоспособности Laravel runtime.

## Что покрывает первый слой

- данные PostgreSQL: контакты, диалоги, сообщения, очереди, сессии, cache-таблицы
- runtime-состояние интеграций, которое хранится в базе
- `Channel.credentials`, если они есть в текущей базе

## Что не покрывает первый слой

- удалённое offsite-хранилище
- автоматическое расписание на сервере
- managed backup Laravel Cloud или другого провайдера
- файлы `.env` и секреты вне базы
- durable-файлы, если позже появятся загрузки в `storage/app`

Offsite-хранилище и шифрование добавляются отдельным шагом после выбора backend.

## Требования

- `php` для чтения `.env` без shell-исполнения secret-значений
- `pg_dump` и `pg_restore` для создания и проверки dump
- `psql`, `createdb` и `dropdb` для проверки с `--restore-smoke`

## Создание локального dump

Скрипт читает PostgreSQL-настройки из `.env` проекта и создаёт custom-format dump:

```bash
scripts/backup-postgres.sh
```

По умолчанию файл сохраняется в:

```text
storage/app/private/backups/postgres/
```

Директория с backup-файлами игнорируется git-ом. Dump содержит персональные данные
и интеграционные credentials, поэтому его нельзя отправлять в нешифрованное
хранилище или коммитить в репозиторий.

Для другого env-файла:

```bash
scripts/backup-postgres.sh --env-file .env.staging
```

Для явной директории:

```bash
BACKUP_DIR=/Volumes/EncryptedBackups/abrikosoff/postgres scripts/backup-postgres.sh
```

## Проверка dump

Минимальная проверка читает catalog dump-файла:

```bash
scripts/verify-postgres-backup.sh storage/app/private/backups/postgres/<file>.dump
```

Более сильная локальная проверка создаёт временную PostgreSQL database,
восстанавливает dump, считает public-таблицы и удаляет временную database:

```bash
scripts/verify-postgres-backup.sh \
  storage/app/private/backups/postgres/<file>.dump \
  --restore-smoke \
  --restore-host 127.0.0.1 \
  --restore-username "$USER"
```

`--restore-smoke` требует явный локальный target и не берёт host/port/user из
app `.env`. Это защищает от случайного restore на staging/prod-кластере.
Скрипт также читает `DB_URL` / `DB_DATABASE` из env-файла, чтобы запретить
restore в configured working database.

## Ручное восстановление

Восстановление всегда выполняется в новую database. Рабочую database проекта
не использовать как target для проверки.

```bash
createdb abrikosoff_restore_manual
pg_restore \
  --dbname=abrikosoff_restore_manual \
  --exit-on-error \
  --no-owner \
  --no-acl \
  storage/app/private/backups/postgres/<file>.dump
```

После проверки можно временно указать Laravel на restored database через отдельный
env-файл и выполнить smoke только на этой копии.

## Когда делать backup

- ежедневно для активной среды
- перед migrations, deploy и другими risky-операциями
- перед ручными изменениями данных
- перед экспериментами с интеграциями Telegram, MAX или Bitrix24

## Следующий слой

Следующий безопасный шаг — выбрать encrypted offsite backend:

- `restic` или `kopia`
- Backblaze B2, S3-compatible storage или другой удалённый backend
- отдельное хранение `.env` / secret material в password manager
- регулярная restore-проверка на отдельной test database
