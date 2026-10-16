#!/usr/bin/env bash
# Run one shard of the test suite, in processes small enough for 512 MB.
#
#   scripts/ci/test-shard.sh unit|api|livewire|offline|feature|all
#
# The whole suite does not fit in one 512 MB process (the PDF tests), so it
# runs a folder (or a file) per process. `all` runs every shard in turn, the
# way `composer test-shards` does locally. Exit code is non-zero if any part
# failed; every part runs regardless, so one failure does not hide another.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

PHP="${PHP:-php}"
shard="${1:-all}"
status=0

run() {
    echo "::group::$1"
    if ! "$PHP" -d memory_limit=512M vendor/bin/phpunit --no-progress "$1"; then
        status=1
        echo "FAILED: $1"
    fi
    echo "::endgroup::"
}

case "$shard" in
    unit) run tests/Unit ;;
    api) run tests/Feature/Api ;;
    livewire) run tests/Feature/Livewire ;;
    offline) run tests/Feature/Offline ;;
    feature)
        for d in tests/Feature/*/; do
            case "$d" in tests/Feature/Api/|tests/Feature/Livewire/|tests/Feature/Offline/) ;; *) run "$d" ;; esac
        done
        for f in tests/Feature/*Test.php; do run "$f"; done
        ;;
    all)
        for s in unit api livewire offline feature; do
            PHP="$PHP" bash "$0" "$s" || status=1
        done
        ;;
    *) echo "unknown shard: $shard" >&2; exit 2 ;;
esac

exit $status
