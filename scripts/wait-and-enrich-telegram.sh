#!/usr/bin/env bash
set -euo pipefail

tool_dir=/var/www/storefront/imports/unlimipa-tools
lookup_pid=${1:?Pass the metadata lookup process ID}
import_pid=${2:?Pass the import process ID}

while kill -0 "$lookup_pid" 2>/dev/null || kill -0 "$import_pid" 2>/dev/null; do
    sleep 20
done

php "$tool_dir/scripts/apply-telegram-metadata.php" "$tool_dir/.local/telegram/appstore-metadata.json" > "$tool_dir/enrich.log" 2>&1
