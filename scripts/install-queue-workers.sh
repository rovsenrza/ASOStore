#!/usr/bin/env bash
# Replaces the cron-started queue worker with supervised ones (ops/systemd).
# Run as root on the server; safe to run again, e.g. to change the worker counts:
#
#   APPLE_WORKERS=3 FILES_WORKERS=2 ./scripts/install-queue-workers.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APPLE_WORKERS="${APPLE_WORKERS:-3}"
FILES_WORKERS="${FILES_WORKERS:-2}"
ENV_FILE="$ROOT/backend/.env"

[[ $EUID -eq 0 ]] || { echo "run as root"; exit 1; }

install -m 0644 "$ROOT"/ops/systemd/storefront-queue-{apple,files}@.service /etc/systemd/system/
systemctl daemon-reload

# The scheduler must stop starting its own worker once these run.
if grep -q '^STOREFRONT_SCHEDULED_QUEUE_WORKER=' "$ENV_FILE"; then
    sed -i 's/^STOREFRONT_SCHEDULED_QUEUE_WORKER=.*/STOREFRONT_SCHEDULED_QUEUE_WORKER=false/' "$ENV_FILE"
else
    echo 'STOREFRONT_SCHEDULED_QUEUE_WORKER=false' >> "$ENV_FILE"
fi
sudo -u storefront php "$ROOT/backend/artisan" config:cache

for kind in apple files; do
    count=$([[ $kind == apple ]] && echo "$APPLE_WORKERS" || echo "$FILES_WORKERS")
    for i in $(seq 1 "$count"); do
        systemctl enable --now "storefront-queue-$kind@$i"
    done
    # Workers above the new count.
    for unit in $(systemctl list-units --plain --no-legend "storefront-queue-$kind@*" | awk '{print $1}'); do
        i="${unit#*@}"; i="${i%.service}"
        if (( i > count )); then systemctl disable --now "$unit"; fi
    done
done

systemctl --no-pager --plain list-units 'storefront-queue-*'
