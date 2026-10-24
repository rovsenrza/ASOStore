#!/usr/bin/env bash
set -euo pipefail

tool_dir=/var/www/storefront/imports/unlimipa-tools
manifest="$tool_dir/.local/telegram/selection.json"
import_pid=${1:?Pass the import process ID}
cd /var/www/storefront/app/backend

until grep -q '^DONE imported=' "$tool_dir/import.log"; do
    if ! kill -0 "$import_pid" 2>/dev/null; then
        echo 'Import stopped before completion.' >&2
        exit 1
    fi
    sleep 20
done

sudo -u storefront php "$tool_dir/scripts/rescan-telegram-artifact.php" --list > "$tool_dir/rescan-ids.txt"
while IFS= read -r artifact_id; do
    sudo -u storefront php "$tool_dir/scripts/rescan-telegram-artifact.php" "$artifact_id" || true
done < "$tool_dir/rescan-ids.txt"

sudo -u storefront php "$tool_dir/scripts/publish-telegram-catalog.php" "$manifest" > "$tool_dir/publish.log" 2>&1
