#!/usr/bin/env bash
set -euo pipefail

shard_index="${CI_TEST_SHARD_INDEX:?CI_TEST_SHARD_INDEX is required}"
shard_total="${CI_TEST_SHARD_TOTAL:?CI_TEST_SHARD_TOTAL is required}"

if ! [[ "$shard_index" =~ ^[0-9]+$ ]] || ! [[ "$shard_total" =~ ^[0-9]+$ ]]; then
    echo "CI_TEST_SHARD_INDEX and CI_TEST_SHARD_TOTAL must be non-negative integers" >&2
    exit 2
fi

if (( shard_total < 1 )); then
    echo "CI_TEST_SHARD_TOTAL must be greater than zero" >&2
    exit 2
fi

if (( shard_index >= shard_total )); then
    echo "CI_TEST_SHARD_INDEX must be less than CI_TEST_SHARD_TOTAL" >&2
    exit 2
fi

selected_files=()
file_position=0

while IFS= read -r test_file; do
    if (( file_position % shard_total == shard_index )); then
        selected_files+=("$test_file")
    fi

    file_position=$((file_position + 1))
done < <(php vendor/bin/phpunit --list-test-files | sed -n 's/^ - //p' | sort)

if (( ${#selected_files[@]} == 0 )); then
    echo "No PHPUnit test files selected for shard $((shard_index + 1))/$shard_total" >&2
    exit 1
fi

echo "Running PHPUnit shard $((shard_index + 1))/$shard_total with ${#selected_files[@]} test files"

if [[ "${CI_TEST_SHARD_DRY_RUN:-0}" == "1" ]]; then
    printf '%s\n' "${selected_files[@]}"
    exit 0
fi

php vendor/bin/phpunit "${selected_files[@]}"
