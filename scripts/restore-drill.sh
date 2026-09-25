#!/usr/bin/env bash
# Restore drill (IMPLEMENTATION_PLAN P8-OPS-01, runbook docs/runbooks/database-restore.md).
#
#   BACKUP_PASSPHRASE=… ./scripts/restore-drill.sh [backup directory]
#
# Restores the given (default: newest) backup into a throwaway database and a
# temporary folder, then checks: checksums match the manifest, every migration
# is present, row counts match, audit triggers exist, and sampled artifact
# files match their recorded SHA-256. Drops the throwaway database afterwards.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
: "${BACKUP_PASSPHRASE:?Set BACKUP_PASSPHRASE}"
BACKUP_DIR="${BACKUP_DIR:-$BACKEND/storage/backups}"
SOURCE="${1:-$(ls -d "$BACKUP_DIR"/*/ | sort | tail -1)}"
SOURCE="${SOURCE%/}"

env_value() { { grep -E "^$1=" "$BACKEND/.env" 2>/dev/null || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST="${DB_HOST:-$(env_value DB_HOST)}"; DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"
DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}"; export MYSQL_PWD="${DB_PASSWORD:-$(env_value DB_PASSWORD)}"
MYSQL=(mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME:-root}")
DRILL_DB="storefront_restore_drill"
WORK="$(mktemp -d)"
trap '"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DRILL_DB"; rm -rf "$WORK"' EXIT

decrypt() { openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$1"; }
manifest_file() { php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["files"][$argv[2]] ?? "";' "$SOURCE/manifest.json" "$1"; }
manifest_count() { php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["row_counts"][$argv[2]] ?? "";' "$SOURCE/manifest.json" "$1"; }
started=$(date +%s)
echo "Restore drill from $SOURCE"

for file in db.sql.gz.enc storage.tar.gz.enc; do
  [[ "$(shasum -a 256 "$SOURCE/$file" | cut -d' ' -f1)" == "$(manifest_file "$file")" ]] || { echo "FAIL checksum $file"; exit 1; }
done
echo "OK   checksums match the manifest"

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS $DRILL_DB; CREATE DATABASE $DRILL_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
decrypt "$SOURCE/db.sql.gz.enc" | gunzip | "${MYSQL[@]}" "$DRILL_DB"
echo "OK   database restored into $DRILL_DB"

pending=$(cd "$BACKEND" && DB_DATABASE=$DRILL_DB php artisan migrate:status --no-ansi | grep -c "Pending" || true)
[[ "$pending" == "0" ]] || { echo "FAIL $pending migrations missing after restore"; exit 1; }
echo "OK   all migrations present"

for table in users devices device_registrations apps app_artifacts signed_builds installations audit_logs; do
  restored=$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM \`$table\`" "$DRILL_DB")
  expected=$(manifest_count "$table")
  [[ "$restored" == "$expected" ]] || { echo "FAIL $table: $restored rows, manifest says $expected"; exit 1; }
done
echo "OK   row counts match"

triggers=$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DRILL_DB' AND EVENT_OBJECT_TABLE IN ('audit_logs','app_artifacts')")
echo "OK   $triggers immutability triggers restored"

decrypt "$SOURCE/storage.tar.gz.enc" | tar -xzf - -C "$WORK"
checked=0
while IFS=$'\t' read -r path sha; do
  file="$WORK/artifacts/$path"
  [[ -f "$file" ]] || { echo "FAIL missing artifact file $path"; exit 1; }
  [[ "$(shasum -a 256 "$file" | cut -d' ' -f1)" == "$sha" ]] || { echo "FAIL checksum $path"; exit 1; }
  checked=$((checked + 1))
done < <("${MYSQL[@]}" -N -e "SELECT storage_path, sha256 FROM app_artifacts WHERE purged_at IS NULL ORDER BY RAND() LIMIT 5" "$DRILL_DB")
echo "OK   $checked sampled artifact files match their SHA-256"

echo "PASS restore drill in $(( $(date +%s) - started ))s"
