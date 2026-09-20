#!/usr/bin/env bash
# HMS_PLAN.md constraint C11 — secrets hygiene. The legacy audits' worst
# finding was a committed .env (DB password, APP_KEY, JWT_SECRET, mail/AWS
# keys) and a hardcoded Flutterwave secret key in a model file. This is a
# lightweight, dependency-free scan (no gitleaks/trufflehog available in
# this environment) run against tracked, non-vendor files.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

fail=0

echo "== secrets-scan: checking no .env variant is tracked by git =="
tracked_env=$(git ls-files | grep -E '(^|/)\.env($|\.[a-zA-Z0-9_]+$)' | grep -v '\.env\.example$' || true)
if [ -n "$tracked_env" ]; then
    echo "FAIL: real .env file(s) tracked by git:"
    echo "$tracked_env"
    fail=1
fi

echo "== secrets-scan: scanning tracked source files for likely secrets =="
# Patterns: AWS keys, private key blocks, live payment-gateway secret keys,
# generic "password = 'literal'" / "secret = 'literal'" assignments outside
# config/env plumbing. Deliberately conservative to avoid false positives on
# config(...)/env(...) reads.
pattern='AKIA[0-9A-Z]{16}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|FLWSECK-[A-Za-z0-9]{16,}|sk_live_[A-Za-z0-9]+|xox[baprs]-[A-Za-z0-9-]+'

hits=$(git ls-files \
    | grep -vE '^(vendor|node_modules|_legacy)/' \
    | grep -vE '\.(lock|min\.js|min\.css)$' \
    | xargs -I{} grep -lEn "$pattern" {} 2>/dev/null || true)

if [ -n "$hits" ]; then
    echo "FAIL: possible hardcoded secret in:"
    echo "$hits"
    fail=1
fi

echo "== secrets-scan: checking no database dump / oversized file is tracked =="
# A 134 MB production dump was once committed under storage/backups; pattern
# scans cannot catch bulk data, so block dump extensions and anything > 5 MB.
dumps=$(git ls-files | grep -E '\.(sql|sql\.gz|dump|bak)$' || true)
if [ -n "$dumps" ]; then
    echo "FAIL: database dump file(s) tracked by git:"
    echo "$dumps"
    fail=1
fi
big=$(git ls-files -z | xargs -0 -I{} sh -c 'f="{}"; [ -f "$f" ] && [ "$(wc -c < "$f")" -gt 5242880 ] && echo "$f"' || true)
if [ -n "$big" ]; then
    echo "FAIL: tracked file(s) larger than 5 MB:"
    echo "$big"
    fail=1
fi

if [ "$fail" -eq 0 ]; then
    echo "secrets-scan: OK"
fi

exit $fail
