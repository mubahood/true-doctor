#!/usr/bin/env bash
# HMS_PLAN.md constraint A4 — fail the build on empty/stub PHP class files.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
"${PHP:-php}" scripts/ci/check-empty-files.php
