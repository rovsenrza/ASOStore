#!/usr/bin/env bash
# Browser tests against a throwaway database (tests/e2e).
#
#   MYSQL_USER=root [MYSQL_PASSWORD=] ./scripts/e2e.sh [playwright args]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8002}"
DB="storefront_e2e"
MYSQL=(mysql -h "${MYSQL_HOST:-127.0.0.1}" -u"${MYSQL_USER:-root}")
[[ -n "${MYSQL_PASSWORD:-}" ]] && MYSQL+=(-p"$MYSQL_PASSWORD")

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

(cd "$ROOT/front" && npm ci --no-audit --no-fund && npm run build) >/dev/null
MOCKS=0 "$ROOT/scripts/build-public.sh" >/dev/null

cd "$ROOT/backend"
export DB_DATABASE="$DB" SEED_ADMIN_PASSWORD="e2e-admin-password-1" APP_ENV=local APP_URL="http://127.0.0.1:$PORT" MAIL_MAILER=array \
  STOREFRONT_APPLE_DRIVER=fake STOREFRONT_APPLE_FAKE_PROCESSING_SECONDS=0 QUEUE_CONNECTION=sync
php artisan migrate:fresh --seed --force --no-interaction >/dev/null
php artisan serve --port="$PORT" >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS '"$DB"';"; MOCKS=1 "$ROOT/scripts/build-public.sh" >/dev/null' EXIT
sleep 2

cd "$ROOT/tests/e2e"
[[ -d node_modules ]] || npm install --silent
BASE_URL="http://127.0.0.1:$PORT" ADMIN_PASSWORD="$SEED_ADMIN_PASSWORD" npx playwright test "$@"
