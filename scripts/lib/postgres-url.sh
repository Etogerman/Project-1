#!/usr/bin/env bash

load_postgres_url_parts() {
    local database_url="$1"
    local exports

    command -v php >/dev/null 2>&1 || {
        printf 'Error: php is required to parse DB_URL.\n' >&2
        exit 1
    }

    exports="$(
        POSTGRES_DATABASE_URL="$database_url" php <<'PHP'
<?php

$url = getenv('POSTGRES_DATABASE_URL');

if ($url === false || $url === '') {
    fwrite(STDERR, "DB_URL is empty.\n");
    exit(1);
}

$parts = parse_url($url);

if ($parts === false) {
    fwrite(STDERR, "DB_URL is not a valid URL.\n");
    exit(1);
}

$scheme = strtolower((string) ($parts['scheme'] ?? ''));

if (! in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true)) {
    fwrite(STDERR, "DB_URL must use a PostgreSQL scheme.\n");
    exit(1);
}

$query = [];
parse_str((string) ($parts['query'] ?? ''), $query);

$values = [
    'POSTGRES_URL_HOST' => (string) ($parts['host'] ?? ($query['host'] ?? '')),
    'POSTGRES_URL_PORT' => isset($parts['port']) ? (string) $parts['port'] : (string) ($query['port'] ?? ''),
    'POSTGRES_URL_USERNAME' => isset($parts['user']) ? rawurldecode((string) $parts['user']) : (string) ($query['user'] ?? ''),
    'POSTGRES_URL_PASSWORD' => isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '',
    'POSTGRES_URL_DATABASE' => rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/')),
    'POSTGRES_URL_SSLMODE' => (string) ($query['sslmode'] ?? ''),
];

if ($values['POSTGRES_URL_DATABASE'] === '' && isset($query['dbname'])) {
    $values['POSTGRES_URL_DATABASE'] = (string) $query['dbname'];
}

foreach ($values as $key => $value) {
    printf("export %s=%s\n", $key, escapeshellarg($value));
}
PHP
    )"

    eval "$exports"
}
