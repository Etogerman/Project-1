#!/bin/sh

set -eu

root="${TELEGRAM_LOCAL_BOT_API_FILES_ROOT:-/var/lib/telegram-bot-api}"
media_gid="${TELEGRAM_MEDIA_GID:-20000}"

case "${media_gid}" in
    ''|*[!0-9]*|0)
        printf 'TELEGRAM_MEDIA_GID must be a positive numeric GID.\n' >&2
        exit 1
        ;;
esac

while [ "${root}" != / ] && [ "${root%/}" != "${root}" ]; do
    root="${root%/}"
done

case "${root}" in
    /*) ;;
    *)
        printf 'Telegram media root must be an absolute path: %s\n' "${root}" >&2
        exit 1
        ;;
esac

if [ "${root}" = / ] || [ -L "${root}" ] || [ ! -d "${root}" ]; then
    printf 'Telegram media root must be an existing non-symlink directory other than /: %s\n' "${root}" >&2
    exit 1
fi

root="$(cd "${root}" && pwd -P)"

# The bridge gets traversal only. Removing group read/write from directories
# keeps their names private and prevents the bridge group from modifying them.
find "${root}" -xdev -type d -exec sh -eu -c '
    media_gid="$1"
    shift

    for path do
        chgrp "${media_gid}" "${path}"
        chmod g=x "${path}"
    done
' sh "${media_gid}" '{}' +

# Only application-supported TDLib media directories receive group read.
# FileType::Audio is stored in "music" by the pinned TDLib revision.
find "${root}" -xdev -type f \( \
    -path '*/animations/*' -o \
    -path '*/documents/*' -o \
    -path '*/music/*' -o \
    -path '*/photos/*' -o \
    -path '*/profile_photos/*' -o \
    -path '*/stickers/*' -o \
    -path '*/thumbnails/*' -o \
    -path '*/video_notes/*' -o \
    -path '*/videos/*' -o \
    -path '*/voice/*' \
\) -exec sh -eu -c '
    media_gid="$1"
    shift

    for path do
        chgrp "${media_gid}" "${path}"
        chmod g=r "${path}"
    done
' sh "${media_gid}" '{}' +

# A command failure already stops the script. The second pass also catches a
# command that returned success without applying the requested ownership/mode.
find "${root}" -xdev -type d -exec sh -eu -c '
    media_gid="$1"
    shift

    for path do
        actual_gid="$(stat -c %g "${path}")"
        actual_mode="$(stat -c %a "${path}")"

        if [ "${actual_gid}" != "${media_gid}" ] || [ $((0${actual_mode} & 0070)) -ne $((0010)) ]; then
            printf "Telegram media directory repair verification failed: %s\n" "${path}" >&2
            exit 1
        fi
    done
' sh "${media_gid}" '{}' +

find "${root}" -xdev -type f \( \
    -path '*/animations/*' -o \
    -path '*/documents/*' -o \
    -path '*/music/*' -o \
    -path '*/photos/*' -o \
    -path '*/profile_photos/*' -o \
    -path '*/stickers/*' -o \
    -path '*/thumbnails/*' -o \
    -path '*/video_notes/*' -o \
    -path '*/videos/*' -o \
    -path '*/voice/*' \
\) -exec sh -eu -c '
    media_gid="$1"
    shift

    for path do
        actual_gid="$(stat -c %g "${path}")"
        actual_mode="$(stat -c %a "${path}")"

        if [ "${actual_gid}" != "${media_gid}" ] || [ $((0${actual_mode} & 0070)) -ne $((0040)) ]; then
            printf "Telegram media file repair verification failed: %s\n" "${path}" >&2
            exit 1
        fi
    done
' sh "${media_gid}" '{}' +

printf 'Existing Telegram media permissions repaired for GID %s.\n' "${media_gid}"
