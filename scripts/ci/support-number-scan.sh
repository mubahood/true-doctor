#!/usr/bin/env bash
# The support WhatsApp number is stored once (config/support.php, the
# SUPPORT_* env values and Settings → Site) and read through
# App\Support\SupportContact. Typing it into a view, an email, a PDF or a
# source file means one place gets forgotten when it changes. Fail the build
# if the digits appear anywhere else.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

pattern='256[ -]?783[ -]?204[ -]?665|783[ -]?204[ -]?665'

hits=$(git ls-files \
    | grep -vE '^(vendor|node_modules|_legacy|docs|public/build)/' \
    | grep -vE '^(config/support\.php|\.env\.example|scripts/ci/support-number-scan\.sh)$' \
    | grep -vE '\.(lock|min\.js|min\.css|png|jpg|jpeg|webp|pdf|woff2?)$' \
    | xargs -I{} grep -lE "$pattern" {} 2>/dev/null || true)

if [ -n "$hits" ]; then
    echo "FAIL: the support number is typed outside config/support.php and .env.example:"
    echo "$hits"
    echo "Read it from App\\Support\\SupportContact instead."
    exit 1
fi

echo "support-number-scan: OK"
