#!/bin/sh

set -eu

: "${TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_USERNAME:?Bridge username is required}"
: "${TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_PASSWORD:?Bridge password is required}"

printf '%s\n' "${TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_PASSWORD}" \
    | htpasswd -i -cB \
        /etc/nginx/.telegram-bot-api-files.htpasswd \
        "${TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_USERNAME}"

chown root:telegram-media /etc/nginx/.telegram-bot-api-files.htpasswd
chmod 640 /etc/nginx/.telegram-bot-api-files.htpasswd
