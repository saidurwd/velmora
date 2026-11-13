#!/usr/bin/env bash
# Builds ONE upload for shared hosting, on your own computer: the code of the current commit, production dependencies only, the built assets, no tests/docs/dev tools, no secrets.
# Usage: bash deploy/cpanel/build-release.sh        -> velmora-release-<commit>.zip in the current folder
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
[ -z "$(git status --porcelain)" ] || { echo "Uncommitted changes: commit first, so the upload matches a commit you can come back to." >&2; exit 1; }
SHA="$(git rev-parse --short HEAD)"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
git archive HEAD | tar -x -C "$WORK"
cd "$WORK"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
npm ci --silent && npm run build --silent
rm -rf node_modules tests e2e docs .github deploy/cpanel/build-release.sh playwright.config.js phpunit.xml pint.json phpstan.neon .env .env.e2e .env.example.bak
rm -rf storage/logs/* storage/framework/cache/data/* storage/framework/sessions/* storage/framework/views/* 2>/dev/null || true
echo "$SHA" > RELEASE
OUT="$OLDPWD/velmora-release-$SHA.zip"
rm -f "$OUT"
zip -qr "$OUT" . -x '.git/*'
echo "Built $OUT ($(du -h "$OUT" | cut -f1)). It holds no .env and no secrets."
