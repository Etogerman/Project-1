#!/bin/sh

set -eu

image="${1:-project1/telegram-bot-api-file-bridge:contract-test}"
suffix="$$"
volume="telegram-file-bridge-contract-${suffix}"
failure_volume="telegram-file-bridge-contract-failure-${suffix}"
container="telegram-file-bridge-contract-${suffix}"
username="contract-reader"
password="contract-password-${suffix}"
authorization="Basic $(printf '%s' "${username}:${password}" | base64 | tr -d '\n')"
payload='telegram-file-bridge-contract-payload'
music_payload='telegram-file-bridge-music-payload'
unsupported_audio_payload='telegram-file-bridge-unsupported-audio-payload'
writer_payload='telegram-writer-media-payload'
range_output="$(mktemp)"
range_headers="$(mktemp)"

cleanup() {
    docker rm -f "${container}" >/dev/null 2>&1 || true
    docker volume rm "${volume}" >/dev/null 2>&1 || true
    docker volume rm "${failure_volume}" >/dev/null 2>&1 || true
    rm -f "${range_output}" "${range_headers}"
}

trap cleanup EXIT INT TERM

docker volume create "${volume}" >/dev/null

docker run --rm \
    --entrypoint sh \
    --volume "${volume}:/fixture" \
    "${image}" \
    -c '
        set -eu
        mkdir -p \
            /fixture/bot-contract/audio \
            /fixture/bot-contract/music \
            /fixture/bot-contract/tmp \
            /fixture/bot-contract/videos
        printf "%s" "$1" > /fixture/bot-contract/videos/file.mp4
        printf "%s" "$2" > /fixture/bot-contract/music/file.mp3
        printf "%s" "$3" > /fixture/bot-contract/audio/file.mp3
        printf "%s" "world-readable-database" > /fixture/bot-contract/db.sqlite
        printf "%s" "group-readable-temporary-file" > /fixture/bot-contract/tmp/partial.bin
        printf "%s" "private-state" > /fixture/bot-contract/td.binlog
        ln -s file.mp4 /fixture/bot-contract/videos/link.mp4
        chown -R 0:0 /fixture/bot-contract
        chown 0:20000 \
            /fixture/bot-contract/audio/file.mp3 \
            /fixture/bot-contract/tmp/partial.bin \
            /fixture/bot-contract/td.binlog
        chmod 0750 \
            /fixture/bot-contract \
            /fixture/bot-contract/audio \
            /fixture/bot-contract/music \
            /fixture/bot-contract/tmp \
            /fixture/bot-contract/videos
        chmod 0640 /fixture/bot-contract/videos/file.mp4
        chmod 0600 /fixture/bot-contract/music/file.mp3
        chmod 0640 \
            /fixture/bot-contract/audio/file.mp3 \
            /fixture/bot-contract/tmp/partial.bin \
            /fixture/bot-contract/td.binlog
        chmod 0644 /fixture/bot-contract/db.sqlite
    ' sh "${payload}" "${music_payload}" "${unsupported_audio_payload}"

docker run --rm \
    --entrypoint /usr/local/bin/repair-existing-media-permissions \
    --env TELEGRAM_LOCAL_BOT_API_FILES_ROOT=/var/lib/telegram-bot-api \
    --env TELEGRAM_MEDIA_GID=20000 \
    --volume "${volume}:/var/lib/telegram-bot-api" \
    "${image}" >/dev/null

docker run --rm \
    --user 0:20000 \
    --entrypoint sh \
    --volume "${volume}:/var/lib/telegram-bot-api" \
    "${image}" \
    -c '
        set -eu
        printf "%s" "$1" > /var/lib/telegram-bot-api/bot-contract/videos/new.mp4
        chmod 0640 /var/lib/telegram-bot-api/bot-contract/videos/new.mp4
    ' sh "${writer_payload}"

docker run -d \
    --name "${container}" \
    --add-host telegram-bot-api:127.0.0.1 \
    --env "TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_USERNAME=${username}" \
    --env "TELEGRAM_LOCAL_BOT_API_FILE_BRIDGE_PASSWORD=${password}" \
    --publish 127.0.0.1::8082 \
    --volume "${volume}:/var/lib/telegram-bot-api:ro" \
    "${image}" >/dev/null

port="$(docker port "${container}" 8082/tcp | awk -F: 'NR == 1 { print $NF }')"
[ -n "${port}" ]
base_url="http://127.0.0.1:${port}"

attempt=0
until curl --fail --silent --output /dev/null "${base_url}/healthz"; do
    attempt=$((attempt + 1))

    if [ "${attempt}" -ge 30 ]; then
        docker logs "${container}" >&2
        exit 1
    fi

    sleep 1
done

docker exec "${container}" sh -c '
    set -eu
    found=0

    for status in /proc/[0-9]*/status; do
        name="$(awk "/^Name:/{print \$2}" "$status")"
        uid="$(awk "/^Uid:/{print \$2}" "$status")"
        gid="$(awk "/^Gid:/{print \$2}" "$status")"

        if [ "$name" = nginx ] && [ "$uid" != 0 ]; then
            [ "$gid" = 20000 ]
            found=1
        fi
    done

    [ "$found" = 1 ]
    [ "$(stat -c "%u:%g:%a" /etc/nginx/.telegram-bot-api-files.htpasswd)" = "0:20000:640" ]
'

actual="$(curl --fail --silent --show-error \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/videos/file.mp4")"
[ "${actual}" = "${payload}" ]

music_actual="$(curl --fail --silent --show-error \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/music/file.mp3")"
[ "${music_actual}" = "${music_payload}" ]

writer_actual="$(curl --fail --silent --show-error \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/videos/new.mp4")"
[ "${writer_actual}" = "${writer_payload}" ]

curl --fail --silent --show-error \
    --header "Authorization: ${authorization}" \
    --header 'Range: bytes=0-0' \
    --dump-header "${range_headers}" \
    --output "${range_output}" \
    "${base_url}/files/bot-contract/videos/file.mp4"
grep -Eq '^HTTP/[0-9.]+ 206' "${range_headers}"
[ "$(wc -c < "${range_output}" | tr -d ' ')" = 1 ]
[ "$(cat "${range_output}")" = 't' ]

assert_status() {
    expected="$1"
    shift
    actual_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "$@")"

    if [ "${actual_status}" != "${expected}" ]; then
        printf 'Expected HTTP %s, got %s\n' "${expected}" "${actual_status}" >&2
        exit 1
    fi
}

assert_status 401 "${base_url}/files/bot-contract/videos/file.mp4"
assert_status 404 \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/db.sqlite"
assert_status 404 \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/td.binlog"
assert_status 404 \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/tmp/partial.bin"
assert_status 404 \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/audio/file.mp3"
assert_status 404 \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/videos/missing.mp4"
assert_status 403 \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/videos/link.mp4"

assert_not_success() {
    actual_status="$(curl --path-as-is --silent --output /dev/null --write-out '%{http_code}' "$@")"

    case "${actual_status}" in
        2??)
            printf 'Expected a non-success HTTP status, got %s\n' "${actual_status}" >&2
            exit 1
            ;;
    esac
}

assert_not_success \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/videos/../db.sqlite"
assert_not_success \
    --header "Authorization: ${authorization}" \
    "${base_url}/files/bot-contract/videos/%2e%2e/td.binlog"

docker run --rm \
    --entrypoint sh \
    --volume "${volume}:/fixture:ro" \
    "${image}" \
    -c '
        set -eu
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract)" = "0:20000:710" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/audio)" = "0:20000:710" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/music)" = "0:20000:710" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/tmp)" = "0:20000:710" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/videos)" = "0:20000:710" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/audio/file.mp3)" = "0:20000:640" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/db.sqlite)" = "0:0:644" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/music/file.mp3)" = "0:20000:640" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/tmp/partial.bin)" = "0:20000:640" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/videos/file.mp4)" = "0:20000:640" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/videos/new.mp4)" = "0:20000:640" ]
        [ "$(stat -c "%u:%g:%a" /fixture/bot-contract/td.binlog)" = "0:20000:640" ]
    '

docker volume create "${failure_volume}" >/dev/null

docker run --rm \
    --entrypoint sh \
    --volume "${failure_volume}:/fixture" \
    "${image}" \
    -c '
        set -eu
        mkdir -p /fixture/fake-bin /fixture/media/bot-contract/videos
        printf "%s" "repair-must-fail" > /fixture/media/bot-contract/videos/failure.mp4
        chmod 0750 /fixture/media /fixture/media/bot-contract /fixture/media/bot-contract/videos
        chmod 0600 /fixture/media/bot-contract/videos/failure.mp4
        cat > /fixture/fake-bin/chgrp <<"SH"
#!/bin/sh
case "$2" in
    */videos/failure.mp4) exit 72 ;;
esac
exec /bin/chgrp "$@"
SH
        chmod 0755 /fixture/fake-bin/chgrp
    '

if docker run --rm \
    --entrypoint /usr/local/bin/repair-existing-media-permissions \
    --env TELEGRAM_LOCAL_BOT_API_FILES_ROOT=/fixture/media \
    --env TELEGRAM_MEDIA_GID=20000 \
    --env PATH=/fixture/fake-bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    --volume "${failure_volume}:/fixture" \
    "${image}" >/dev/null 2>&1; then
    printf '%s\n' 'Expected repair to fail when chgrp fails.' >&2
    exit 1
fi

docker run --rm \
    --entrypoint sh \
    --volume "${failure_volume}:/fixture" \
    "${image}" \
    -c '
        set -eu
        cat > /fixture/fake-bin/chgrp <<"SH"
#!/bin/sh
exit 0
SH
        chmod 0755 /fixture/fake-bin/chgrp
        chown 0:0 /fixture/media/bot-contract/videos/failure.mp4
    '

if docker run --rm \
    --entrypoint /usr/local/bin/repair-existing-media-permissions \
    --env TELEGRAM_LOCAL_BOT_API_FILES_ROOT=/fixture/media \
    --env TELEGRAM_MEDIA_GID=20000 \
    --env PATH=/fixture/fake-bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    --volume "${failure_volume}:/fixture" \
    "${image}" >/dev/null 2>&1; then
    printf '%s\n' 'Expected repair post-verification to reject a no-op chgrp.' >&2
    exit 1
fi

printf '%s\n' 'Telegram file bridge access contract verified.'
