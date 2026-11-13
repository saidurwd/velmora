#!/usr/bin/env bash
# Runs ON THE SERVER (SSH). Layout it keeps, all in your home folder:
#   ~/velmora-releases/<name>/   every uploaded release, kept so you can go back
#   ~/velmora-shared/{.env,storage}   the things that belong to the shop, not to a release
#   ~/velmora                     a LINK to the release in use (public_html/index.php points here)
# Usage:  bash deploy.sh install  velmora-release-abc1234.zip      first time or every upgrade
#         bash deploy.sh rollback                                   go back to the previous release
# Nothing here touches the database except `php artisan migrate`, which follows the expand/contract rules (the old code keeps working on the new schema).
set -euo pipefail
HOME_DIR="${VELMORA_HOME:-$HOME}"
REL="$HOME_DIR/velmora-releases"; SHARED="$HOME_DIR/velmora-shared"; LINK="$HOME_DIR/velmora"
PHP="${PHP_BIN:-php}"
mkdir -p "$REL" "$SHARED/storage/app/public" "$SHARED/storage/framework/cache" "$SHARED/storage/framework/sessions" "$SHARED/storage/framework/views" "$SHARED/storage/logs"

current() { [ -L "$LINK" ] && basename "$(readlink "$LINK")" || true; }

case "${1:-}" in
install)
  ZIP="${2:?give the release zip}"; NAME="$(basename "$ZIP" .zip)-$(date +%Y%m%d%H%M%S)"
  [ -f "$SHARED/.env" ] || { echo "Create $SHARED/.env first (copy .env.example from the release and fill it in)." >&2; exit 1; }
  mkdir -p "$REL/$NAME" && unzip -q "$ZIP" -d "$REL/$NAME"
  cd "$REL/$NAME"
  rm -rf storage && ln -s "$SHARED/storage" storage
  ln -sf "$SHARED/.env" .env
  mkdir -p bootstrap/cache
  echo "== preflight (nothing is switched yet)"
  $PHP artisan velmora:preflight || { echo "Preflight failed: the old release is still live. Fix the lines marked FAIL, then run install again." >&2; exit 1; }
  PREV="$(current)"
  echo "== maintenance on, migrate, switch, maintenance off"
  [ -n "$PREV" ] && $PHP "$LINK/artisan" down --retry=60 || true
  $PHP artisan migrate --force
  ln -sfn "$REL/$NAME" "$LINK"
  $PHP artisan optimize:clear >/dev/null
  $PHP artisan config:cache >/dev/null && $PHP artisan route:cache >/dev/null && $PHP artisan view:cache >/dev/null
  $PHP artisan up
  echo "Live: $NAME (previous: ${PREV:-none}). Keep the last 3 releases; delete older ones from $REL when you are sure."
  ;;
rollback)
  CUR="$(current)"; PREV="$(ls -1 "$REL" | grep -v "^$CUR\$" | tail -1 || true)"
  [ -n "$PREV" ] || { echo "No previous release to go back to." >&2; exit 1; }
  ln -sfn "$REL/$PREV" "$LINK"; $PHP "$LINK/artisan" optimize:clear >/dev/null; $PHP "$LINK/artisan" up || true
  echo "Back on $PREV. The database was NOT changed (migrations only add, so the old code works with it); see docs/runbooks/rollback.md if the release included a 'dropped' step."
  ;;
*) echo "usage: deploy.sh install <zip> | rollback" >&2; exit 2 ;;
esac
