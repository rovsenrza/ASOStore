#!/usr/bin/env bash
# Regenerate docs/api/examples from a real API run (IMPLEMENTATION_PLAN D11).
# Uses a throwaway database so development data is untouched.
#
#   MYSQL_USER=root [MYSQL_PASSWORD=] ./scripts/generate-api-examples.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8001}"
DB="storefront_examples"
MYSQL=(mysql -h "${MYSQL_HOST:-127.0.0.1}" -u"${MYSQL_USER:-root}")
[[ -n "${MYSQL_PASSWORD:-}" ]] && MYSQL+=(-p"$MYSQL_PASSWORD")

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cd "$ROOT/backend"
export DB_DATABASE="$DB" SEED_ADMIN_PASSWORD="examples-admin-password-1" APP_ENV=local APP_URL="http://127.0.0.1:$PORT" \
  STOREFRONT_APPLE_DRIVER=fake STOREFRONT_APPLE_FAKE_PROCESSING_SECONDS=0 QUEUE_CONNECTION=sync MAIL_MAILER=array
php artisan migrate:fresh --seed --force --no-interaction >/dev/null
php artisan serve --port="$PORT" >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS '"$DB"';"' EXIT
sleep 2

ROOT="$ROOT" BASE_URL="http://127.0.0.1:$PORT" ADMIN_PASSWORD="$SEED_ADMIN_PASSWORD" python3 "$ROOT/scripts/generate-api-examples.py" "$ROOT/docs/api/examples"
