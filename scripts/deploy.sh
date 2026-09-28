#!/usr/bin/env bash
# Production deploy on the host chosen in P0-05 (IMPLEMENTATION_PLAN P8-OPS-03).
# Run from a fresh checkout of the release tag on the server:
#
#   ./scripts/deploy.sh
#
# Assumes backend/.env is already in place (APP_ENV=production, APP_DEBUG=false, …).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/backend"

grep -q '^APP_ENV=production' .env || { echo "backend/.env is not APP_ENV=production"; exit 1; }
grep -q '^APP_DEBUG=false' .env || { echo "APP_DEBUG must be false in production"; exit 1; }
grep -q '^STOREFRONT_APPLE_DRIVER=appstoreconnect' .env || echo "warning: STOREFRONT_APPLE_DRIVER is not appstoreconnect"

php artisan down --retry=60 || true
trap 'php artisan up' EXIT

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer audit --no-interaction
php artisan migrate --force

# Static portal and admin without mock fixtures (IMPLEMENTATION_PLAN D1, D13).
MOCKS=0 "$ROOT/scripts/build-public.sh"

php artisan config:cache
php artisan route:cache
# The UI is static HTML, so there may be no Blade views to compile.
if [[ -d resources/views ]]; then php artisan view:cache; fi
php artisan event:cache
php artisan queue:restart

echo "Deployed. Smoke test: curl -fsS \"\$APP_URL/api/v1/health\" and open /admin/."
