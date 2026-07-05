#!/usr/bin/env bash
set -euo pipefail

shard_index="${CI_TEST_SHARD_INDEX:?CI_TEST_SHARD_INDEX is required}"
shard_total="${CI_TEST_SHARD_TOTAL:?CI_TEST_SHARD_TOTAL is required}"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

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

list_phpunit_test_files() {
    local phpunit_output="$tmpdir/phpunit-test-files"
    local parsed_output="$tmpdir/phpunit-test-files.parsed"

    if php vendor/bin/phpunit --list-test-files > "$phpunit_output" 2> "$tmpdir/phpunit-test-files.err"; then
        sed -n 's/^ - //p' "$phpunit_output" | sort > "$parsed_output"

        if [[ -s "$parsed_output" ]]; then
            cat "$parsed_output"
            return 0
        fi

        echo "phpunit --list-test-files returned no parseable test files; falling back to file discovery" >&2
    else
        echo "phpunit --list-test-files is unavailable; falling back to file discovery" >&2
    fi

    find tests/Unit tests/Feature -type f -name '*Test.php' | sort
}

while IFS= read -r test_file; do
    if (( file_position % shard_total == shard_index )); then
        selected_files+=("$test_file")
    fi

    file_position=$((file_position + 1))
done < <(list_phpunit_test_files)

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
