#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${BACKUP_ENV_FILE:-$ROOT_DIR/.env}"
DUMP_FILE=""
RESTORE_SMOKE=0
KEEP_RESTORE_DB=0
RESTORE_DB=""
RESTORE_HOST=""
RESTORE_PORT="5432"
RESTORE_USERNAME=""
MAINTENANCE_DB="${POSTGRES_MAINTENANCE_DB:-postgres}"

# shellcheck source=scripts/lib/load-dotenv.sh
source "$ROOT_DIR/scripts/lib/load-dotenv.sh"
# shellcheck source=scripts/lib/postgres-url.sh
source "$ROOT_DIR/scripts/lib/postgres-url.sh"

usage() {
    cat <<'USAGE'
Usage:
  scripts/verify-postgres-backup.sh DUMP_FILE [--env-file PATH]
  scripts/verify-postgres-backup.sh DUMP_FILE --restore-smoke [--env-file PATH]

Checks that a pg_dump custom-format backup is readable. With --restore-smoke
the script creates a temporary PostgreSQL database, restores the dump into it,
counts public tables, and drops the temporary database unless --keep-restore-db
is provided.

Options:
  --env-file PATH       Read PostgreSQL connection settings from this file.
  --restore-smoke      Run a real restore into a temporary database.
  --restore-host HOST   Explicit local PostgreSQL host for smoke restore.
  --restore-port PORT   Explicit PostgreSQL port for smoke restore.
  --restore-username USER
                        Explicit PostgreSQL user for smoke restore.
  --restore-db NAME    Use a specific restore database name; implies smoke.
  --keep-restore-db    Keep the restore database after a successful smoke.
  --maintenance-db DB  Maintenance database used by createdb/dropdb.
USAGE
}

fail() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --env-file)
            [[ $# -ge 2 ]] || fail "--env-file requires a path"
            ENV_FILE="$2"
            shift 2
            ;;
        --restore-smoke)
            RESTORE_SMOKE=1
            shift
            ;;
        --restore-host)
            [[ $# -ge 2 ]] || fail "--restore-host requires a host"
            RESTORE_HOST="$2"
            shift 2
            ;;
        --restore-port)
            [[ $# -ge 2 ]] || fail "--restore-port requires a port"
            RESTORE_PORT="$2"
            shift 2
            ;;
        --restore-username)
            [[ $# -ge 2 ]] || fail "--restore-username requires a user"
            RESTORE_USERNAME="$2"
            shift 2
            ;;
        --restore-db)
            [[ $# -ge 2 ]] || fail "--restore-db requires a database name"
            RESTORE_DB="$2"
            RESTORE_SMOKE=1
            shift 2
            ;;
        --keep-restore-db)
            KEEP_RESTORE_DB=1
            shift
            ;;
        --maintenance-db)
            [[ $# -ge 2 ]] || fail "--maintenance-db requires a database name"
            MAINTENANCE_DB="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            if [[ -z "$DUMP_FILE" ]]; then
                DUMP_FILE="$1"
                shift
            else
                fail "unknown argument: $1"
            fi
            ;;
    esac
done

[[ -n "$DUMP_FILE" ]] || fail "DUMP_FILE is required"
[[ -f "$DUMP_FILE" ]] || fail "dump file not found: $DUMP_FILE"

command -v pg_restore >/dev/null 2>&1 || fail "pg_restore is not available"

pg_restore --list "$DUMP_FILE" >/dev/null
printf 'Backup catalog is readable: %s\n' "$DUMP_FILE"

if [[ "$RESTORE_SMOKE" -eq 0 ]]; then
    exit 0
fi

for command_name in createdb dropdb psql; do
    command -v "$command_name" >/dev/null 2>&1 || fail "$command_name is not available"
done

load_dotenv_file "$ENV_FILE" "$ROOT_DIR/.env"

if [[ -n "${DB_CONNECTION:-}" && "${DB_CONNECTION}" != "pgsql" ]]; then
    fail "DB_CONNECTION must be pgsql, got: ${DB_CONNECTION}"
fi

configured_database="${DB_DATABASE:-}"

if [[ -n "${DB_URL:-}" ]]; then
    load_postgres_url_parts "$DB_URL"
    configured_database="${POSTGRES_URL_DATABASE:-$configured_database}"
fi

if [[ -z "$RESTORE_HOST" ]]; then
    fail "--restore-smoke requires an explicit --restore-host local target"
fi

if [[ -z "$RESTORE_USERNAME" ]]; then
    fail "--restore-smoke requires an explicit --restore-username"
fi

if [[ ! "$RESTORE_PORT" =~ ^[0-9]+$ ]]; then
    fail "--restore-port must be a number"
fi

case "$RESTORE_HOST" in
    localhost|127.0.0.1|::1|/*)
        ;;
    *)
        fail "--restore-host must be a local target: localhost, 127.0.0.1, ::1, or a Unix socket directory"
        ;;
esac

if [[ -z "$RESTORE_DB" ]]; then
    RESTORE_DB="abrikosoff_restore_check_$(date +%Y%m%d%H%M%S)_$$"
fi

if [[ ! "$RESTORE_DB" =~ ^[A-Za-z0-9_]+$ ]]; then
    fail "restore database name must contain only letters, digits, and underscores"
fi

if [[ -n "$configured_database" && "$RESTORE_DB" == "$configured_database" ]]; then
    fail "restore database must not be the configured working database"
fi

if [[ "$RESTORE_DB" == "$MAINTENANCE_DB" ]]; then
    fail "restore database must not be the maintenance database"
fi

conn_args=()
conn_args+=(--host="$RESTORE_HOST")
conn_args+=(--port="$RESTORE_PORT")
conn_args+=(--username="$RESTORE_USERNAME")
psql_maintenance_args=("${conn_args[@]}" --dbname="$MAINTENANCE_DB")
maintenance_tool_args=("${conn_args[@]}" --maintenance-db="$MAINTENANCE_DB")

existing_db="$(
    psql "${psql_maintenance_args[@]}" \
        --tuples-only \
        --no-align \
        --command="select 1 from pg_database where datname = '$RESTORE_DB';" \
        | tr -d '[:space:]'
)"

if [[ -n "$existing_db" ]]; then
    fail "restore database already exists: $RESTORE_DB"
fi

restore_db_created=0

cleanup_restore_db() {
    if [[ "$restore_db_created" -eq 1 && "$KEEP_RESTORE_DB" -eq 0 ]]; then
        dropdb "${maintenance_tool_args[@]}" "$RESTORE_DB" >/dev/null 2>&1 || true
    fi
}
trap cleanup_restore_db EXIT

createdb "${maintenance_tool_args[@]}" "$RESTORE_DB"
restore_db_created=1

pg_restore "${conn_args[@]}" \
    --dbname="$RESTORE_DB" \
    --exit-on-error \
    --no-owner \
    --no-acl \
    "$DUMP_FILE"

table_count="$(
    psql "${conn_args[@]}" \
        --dbname="$RESTORE_DB" \
        --tuples-only \
        --no-align \
        --command="select count(*) from information_schema.tables where table_schema = 'public';" \
        | tr -d '[:space:]'
)"

printf 'Restore smoke succeeded in database: %s\n' "$RESTORE_DB"
printf 'Public tables restored: %s\n' "$table_count"

if [[ "$KEEP_RESTORE_DB" -eq 0 ]]; then
    dropdb "${maintenance_tool_args[@]}" "$RESTORE_DB"
    restore_db_created=0
    printf 'Temporary restore database dropped.\n'
else
    printf 'Temporary restore database kept for inspection.\n'
fi
