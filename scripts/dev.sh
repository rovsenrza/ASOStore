#!/usr/bin/env bash
# Local development in one command: publish the web apps, apply migrations,
# then run the API server, the queue worker (device registrations with Apple)
# and the scheduler (Apple status polling, token cleanup). Ctrl+C stops all.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8000}"

"$ROOT/scripts/build-public.sh"
cd "$ROOT/backend"
php artisan migrate --no-interaction

trap 'kill 0' EXIT
php artisan serve --port="$PORT" &
php artisan queue:work --sleep=1 &
php artisan schedule:work &
echo "Portal http://127.0.0.1:$PORT · Admin http://127.0.0.1:$PORT/admin/ · Ctrl+C to stop"
wait
