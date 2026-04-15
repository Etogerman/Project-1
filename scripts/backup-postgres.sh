#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${BACKUP_ENV_FILE:-$ROOT_DIR/.env}"
BACKUP_DIR="${BACKUP_DIR:-$ROOT_DIR/storage/app/private/backups/postgres}"
OUTPUT_FILE=""

# shellcheck source=scripts/lib/load-dotenv.sh
source "$ROOT_DIR/scripts/lib/load-dotenv.sh"
# shellcheck source=scripts/lib/postgres-url.sh
source "$ROOT_DIR/scripts/lib/postgres-url.sh"

usage() {
    cat <<'USAGE'
Usage:
  scripts/backup-postgres.sh [--env-file PATH] [--dir PATH] [--output PATH]

Creates a timestamped PostgreSQL dump in pg_dump custom format.

Environment:
  BACKUP_ENV_FILE  Defaults to .env in the project root.
  BACKUP_DIR       Defaults to storage/app/private/backups/postgres.

The script reads DB_* variables from the env file when it exists. Current
PostgreSQL connection settings must describe a pgsql database.
USAGE
}

fail() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

sanitize_label() {
    local value="$1"
    local sanitized

    sanitized="$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9._-' '-' | sed 's/^-//; s/-$//')"
    if [[ -z "$sanitized" ]]; then
        sanitized="database"
    fi

    printf '%s' "$sanitized"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --env-file)
            [[ $# -ge 2 ]] || fail "--env-file requires a path"
            ENV_FILE="$2"
            shift 2
            ;;
        --dir)
            [[ $# -ge 2 ]] || fail "--dir requires a path"
            BACKUP_DIR="$2"
            shift 2
            ;;
        --output)
            [[ $# -ge 2 ]] || fail "--output requires a path"
            OUTPUT_FILE="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            fail "unknown argument: $1"
            ;;
    esac
done

command -v pg_dump >/dev/null 2>&1 || fail "pg_dump is not available"

load_dotenv_file "$ENV_FILE" "$ROOT_DIR/.env"

if [[ -n "${DB_CONNECTION:-}" && "${DB_CONNECTION}" != "pgsql" ]]; then
    fail "DB_CONNECTION must be pgsql, got: ${DB_CONNECTION}"
fi

if [[ -z "${DB_URL:-}" && -z "${DB_DATABASE:-}" ]]; then
    fail "DB_DATABASE or DB_URL is required"
fi

umask 077

timestamp="$(date +%Y%m%d-%H%M%S)"
app_label="$(sanitize_label "${APP_NAME:-abrikosoff-connector}")"
env_label="$(sanitize_label "${APP_ENV:-local}")"
db_label="$(sanitize_label "${DB_DATABASE:-database}")"

if [[ -z "$OUTPUT_FILE" ]]; then
    mkdir -p "$BACKUP_DIR"
    OUTPUT_FILE="$BACKUP_DIR/${app_label}-${env_label}-${db_label}-${timestamp}.dump"
else
    mkdir -p "$(dirname "$OUTPUT_FILE")"
fi

if [[ -e "$OUTPUT_FILE" ]]; then
    fail "output file already exists: $OUTPUT_FILE"
fi

tmp_file="${OUTPUT_FILE}.partial.$$"

cleanup() {
    if [[ -n "${tmp_file:-}" && -f "$tmp_file" ]]; then
        rm -f "$tmp_file"
    fi
}
trap cleanup EXIT

pg_dump_args=(
    --format=custom
    --no-owner
    --no-acl
    --file="$tmp_file"
)

if [[ -n "${DB_URL:-}" ]]; then
    load_postgres_url_parts "$DB_URL"
    [[ -n "${POSTGRES_URL_HOST:-}" ]] && pg_dump_args+=(--host="$POSTGRES_URL_HOST")
    [[ -n "${POSTGRES_URL_PORT:-}" ]] && pg_dump_args+=(--port="$POSTGRES_URL_PORT")
    [[ -n "${POSTGRES_URL_USERNAME:-}" ]] && pg_dump_args+=(--username="$POSTGRES_URL_USERNAME")
    [[ -n "${POSTGRES_URL_DATABASE:-}" ]] || fail "DB_URL must include a database name"
    pg_dump_args+=(--dbname="$POSTGRES_URL_DATABASE")

    if [[ -n "${POSTGRES_URL_PASSWORD:-}" ]]; then
        export PGPASSWORD="$POSTGRES_URL_PASSWORD"
    fi

    if [[ -n "${POSTGRES_URL_SSLMODE:-}" ]]; then
        export PGSSLMODE="$POSTGRES_URL_SSLMODE"
    fi
else
    [[ -n "${DB_HOST:-}" ]] && pg_dump_args+=(--host="$DB_HOST")
    [[ -n "${DB_PORT:-}" ]] && pg_dump_args+=(--port="$DB_PORT")
    [[ -n "${DB_USERNAME:-}" ]] && pg_dump_args+=(--username="$DB_USERNAME")
    pg_dump_args+=(--dbname="$DB_DATABASE")

    if [[ -n "${DB_PASSWORD:-}" && "${DB_PASSWORD}" != "null" ]]; then
        export PGPASSWORD="$DB_PASSWORD"
    fi
fi

pg_dump "${pg_dump_args[@]}"

if command -v pg_restore >/dev/null 2>&1; then
    pg_restore --list "$tmp_file" >/dev/null
fi

mv "$tmp_file" "$OUTPUT_FILE"
trap - EXIT

size="$(du -h "$OUTPUT_FILE" | awk '{print $1}')"

printf 'PostgreSQL backup created:\n'
printf '  %s\n' "$OUTPUT_FILE"
printf 'Size: %s\n' "$size"
