#!/usr/bin/env bash
set -euo pipefail

source_root="${1:?source root is required}"
expected_bot_api_commit="${2:?expected Bot API commit is required}"
expected_td_commit="${3:?expected TDLib commit is required}"

pinned_bot_api_commit="adfd7f6a8e990272851777eeb3ae0def4216f161"
pinned_td_commit="a9966eb3704a3351568c28013fed67d797c17828"

if [[ "$expected_bot_api_commit" != "$pinned_bot_api_commit" ]]; then
    echo "error: Telegram Bot API upgrade requires a new storage optimizer audit" >&2
    exit 1
fi

if [[ "$expected_td_commit" != "$pinned_td_commit" ]]; then
    echo "error: TDLib upgrade requires a new storage optimizer audit" >&2
    exit 1
fi

actual_bot_api_commit="$(git -C "$source_root" rev-parse HEAD)"
actual_td_commit="$(git -C "$source_root/td" rev-parse HEAD)"

if [[ "$actual_bot_api_commit" != "$expected_bot_api_commit" ]]; then
    echo "error: unexpected Telegram Bot API commit" >&2
    exit 1
fi

if [[ "$actual_td_commit" != "$expected_td_commit" ]]; then
    echo "error: unexpected TDLib commit" >&2
    exit 1
fi

client_source="$source_root/telegram-bot-api/Client.cpp"
gc_parameters_source="$source_root/td/td/telegram/files/FileGcParameters.cpp"
file_stats_source="$source_root/td/td/telegram/files/FileStatsWorker.cpp"
storage_manager_header="$source_root/td/td/telegram/StorageManager.h"

grep -Fq '"ignore_inline_thumbnails", "reuse_uploaded_photos_by_hash", "use_storage_optimizer"}) {' "$client_source"
grep -Fq 'send_request(make_object<td_api::setOption>(option, make_object<td_api::optionValueBoolean>(true)),' "$client_source"
grep -Fq '//request->use_file_database_ = false;' "$client_source"

if grep -Eq '^[[:space:]]*request->use_file_database_[[:space:]]*=[[:space:]]*true' "$client_source"; then
    echo "error: audited direct-filesystem GC mode changed" >&2
    exit 1
fi

grep -Fq 'if (!G()->use_file_database()) {' "$file_stats_source"
grep -Fq 'scan_fs(token_, [&](FsFileInfo &fs_info) {' "$file_stats_source"
grep -Fq 'G()->get_option_integer("storage_max_files_size", 100 << 10) << 10' "$gc_parameters_source"
grep -Fq 'G()->get_option_integer("storage_max_time_from_last_access", 60 * 60 * 23)' "$gc_parameters_source"
grep -Fq 'G()->get_option_integer("storage_max_file_count", 40000)' "$gc_parameters_source"
grep -Fq 'G()->get_option_integer("storage_immunity_delay", 60 * 60)' "$gc_parameters_source"
grep -Fq 'GC_EACH = 60 * 15' "$storage_manager_header"
grep -Fq 'GC_DELAY = 5' "$storage_manager_header"
grep -Fq 'GC_RAND_DELAY = 55' "$storage_manager_header"

echo 'Telegram Bot API storage optimizer contract verified.'
