#!/usr/bin/env bash

load_dotenv_file() {
    local env_file="$1"
    local default_env_file="${2:-}"
    local exports

    if [[ ! -f "$env_file" ]]; then
        if [[ -n "$default_env_file" && "$env_file" == "$default_env_file" ]]; then
            return 0
        fi

        printf 'Error: env file not found: %s\n' "$env_file" >&2
        exit 1
    fi

    command -v php >/dev/null 2>&1 || {
        printf 'Error: php is required to read env file: %s\n' "$env_file" >&2
        exit 1
    }

    exports="$(
        ENV_FILE_TO_LOAD="$env_file" php <<'PHP'
<?php

$path = getenv('ENV_FILE_TO_LOAD');

if ($path === false || ! is_file($path)) {
    fwrite(STDERR, "Env file not found.\n");
    exit(1);
}

$vars = array_merge($_SERVER, $_ENV);

foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    if (str_starts_with($line, 'export ')) {
        $line = trim(substr($line, 7));
    }

    $separatorPosition = strpos($line, '=');

    if ($separatorPosition === false) {
        continue;
    }

    $key = trim(substr($line, 0, $separatorPosition));

    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
        continue;
    }

    $value = trim(substr($line, $separatorPosition + 1));
    $quote = $value[0] ?? '';

    if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
        $value = substr($value, 1, -1);

        if ($quote === '"') {
            $value = str_replace(
                ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                ["\n", "\r", "\t", '"', '\\'],
                $value
            );
        }
    } else {
        $value = (string) preg_replace('/\s+#.*$/', '', $value);
    }

    $value = (string) preg_replace_callback(
        '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
        static fn (array $matches): string => (string) ($vars[$matches[1]] ?? getenv($matches[1]) ?: ''),
        $value
    );

    $vars[$key] = $value;

    printf("export %s=%s\n", $key, escapeshellarg($value));
}
PHP
    )"

    eval "$exports"
}
