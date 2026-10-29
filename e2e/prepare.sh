#!/usr/bin/env bash
# Builds a throw-away database (name must end in _e2e) with the demo catalog, for the browser tests.
# Never touches the development (velmora) or test (velmora_test) databases.
set -euo pipefail

DB="${E2E_DB:-velmora_e2e}"
case "$DB" in *_e2e) ;; *) echo "refusing: E2E_DB must end in _e2e (got $DB)" >&2; exit 1 ;; esac

export MYSQL_PWD="${DB_PASSWORD:-root}"
mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USERNAME:-root}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;"

# `php artisan serve` does not hand DB settings to the server it starts, so the e2e settings live in .env.e2e (loaded when APP_ENV=e2e).
# That keeps every e2e process, server included, on the throw-away database and off the development one.
{
  grep -E '^(APP_KEY|APP_NAME|DB_CONNECTION|DB_HOST|DB_PORT)=' .env 2>/dev/null || true
  echo "APP_ENV=e2e"
  echo "APP_DEBUG=true"
  echo "APP_URL=http://127.0.0.1:${E2E_PORT:-8010}"
  echo "DB_DATABASE=$DB"
  echo "DB_USERNAME=${DB_USERNAME:-root}"
  echo "DB_PASSWORD=${DB_PASSWORD:-root}"
  echo "CACHE_STORE=file"
  echo "SESSION_DRIVER=file"
  echo "QUEUE_CONNECTION=sync"
  echo "CATALOG_MEDIA_DISK=public"
  echo "FILESYSTEM_DISK=local"
} > .env.e2e
[ -n "$(grep '^APP_KEY=' .env.e2e)" ] || { php artisan key:generate --show | sed 's/^/APP_KEY=/' >> .env.e2e; }
export APP_ENV=e2e
php artisan storage:link --quiet || true
php artisan migrate --force --quiet
php artisan db:seed --force --quiet
php artisan db:seed --class='App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder' --force --quiet
php artisan db:seed --class='App\Modules\Cms\Database\Seeders\DemoCmsSeeder' --force --quiet
# a known discount code for the cart coupon test (10% off, any cart)
mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USERNAME:-root}" "$DB" -e "INSERT INTO coupons (public_id, code, type, value, scope, is_active, min_spend_minor, used_count, created_at, updated_at) VALUES ('01JE2E0000000000000000COUP', 'E2E10', 'percent', 1000, 'all', 1, 0, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));"
php artisan catalog:reindex --quiet
php artisan search:reindex --quiet
php artisan cache:clear --quiet
echo "e2e database $DB ready"
