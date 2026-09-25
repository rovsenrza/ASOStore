#!/usr/bin/env bash
# Encrypted backup of the database and private storage (IMPLEMENTATION_PLAN P8-OPS-01).
#
#   BACKUP_PASSPHRASE=… [BACKUP_DIR=…] [BACKUP_REMOTE=user@host:/path] ./scripts/backup.sh
#
# Writes <BACKUP_DIR>/<timestamp>/{db.sql.gz.enc, storage.tar.gz.enc, manifest.json},
# verifies that both archives decrypt and decompress, optionally copies them
# off-site with rsync, prunes old backups, and records the result in
# backend/storage/app/backup-status.json (read by the backup alert).
# Keep BACKUP_PASSPHRASE outside the server (password manager / secrets store):
# without it the backups cannot be restored.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
: "${BACKUP_PASSPHRASE:?Set BACKUP_PASSPHRASE}"
BACKUP_DIR="${BACKUP_DIR:-$BACKEND/storage/backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STATUS_FILE="${STOREFRONT_BACKUP_STATUS_FILE:-$BACKEND/storage/app/backup-status.json}"

env_value() { { grep -E "^$1=" "$BACKEND/.env" 2>/dev/null || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST="${DB_HOST:-$(env_value DB_HOST)}"; DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"
DB_DATABASE="${DB_DATABASE:-$(env_value DB_DATABASE)}"; DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_value DB_PASSWORD)}"
ARTIFACTS_PATH="${ARTIFACTS_PATH:-$(env_value ARTIFACTS_PATH)}"; ARTIFACTS_PATH="${ARTIFACTS_PATH:-$BACKEND/storage/app/artifacts}"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="$BACKUP_DIR/$STAMP"
mkdir -p "$TARGET"
chmod 700 "$BACKUP_DIR" "$TARGET"

MYSQL_ARGS=(-h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME:-root}")
export MYSQL_PWD="${DB_PASSWORD:-}"

encrypt() { openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE -out "$1"; }
decrypt() { openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$1"; }

status() {
  mkdir -p "$(dirname "$STATUS_FILE")"
  printf '{"finished_at":"%s","verified":%s,"backup":"%s","detail":"%s"}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$STAMP" "$2" > "$STATUS_FILE"
}
trap 'status false "backup script failed at line $LINENO"' ERR

# 1. Database: consistent snapshot, triggers included (audit immutability). Without
# --set-gtid-purged=OFF a MySQL dump cannot be restored into a server that has its
# own GTID history (found by the restore drill).
DUMP_ARGS=(--single-transaction --routines --triggers --hex-blob --no-tablespaces)
# (Read the help text first: grep -q in a pipe would fail under pipefail.)
DUMP_HELP="$(mysqldump --help 2>/dev/null || true)"
[[ "$DUMP_HELP" == *"--set-gtid-purged"* ]] && DUMP_ARGS+=(--set-gtid-purged=OFF)
mysqldump "${MYSQL_ARGS[@]}" "${DUMP_ARGS[@]}" "$DB_DATABASE" \
  | gzip -9 | encrypt "$TARGET/db.sql.gz.enc"

# 2. Private storage: original IPAs, signed builds, provenance documents.
mkdir -p "$ARTIFACTS_PATH"
tar -C "$(dirname "$ARTIFACTS_PATH")" -czf - "$(basename "$ARTIFACTS_PATH")" 2>/dev/null | encrypt "$TARGET/storage.tar.gz.enc"

# 3. Row counts of the tables a restore must bring back, for the drill to compare.
COUNTS=$(for table in users devices device_registrations apps app_artifacts signed_builds installations audit_logs; do
  printf '"%s":%s,' "$table" "$(mysql "${MYSQL_ARGS[@]}" -N -e "SELECT COUNT(*) FROM \`$table\`" "$DB_DATABASE")"
done)
cat > "$TARGET/manifest.json" <<JSON
{"created_at":"$STAMP","database":"$DB_DATABASE",
 "files":{"db.sql.gz.enc":"$(shasum -a 256 "$TARGET/db.sql.gz.enc" | cut -d' ' -f1)","storage.tar.gz.enc":"$(shasum -a 256 "$TARGET/storage.tar.gz.enc" | cut -d' ' -f1)"},
 "row_counts":{${COUNTS%,}}}
JSON

# 4. Verify: both archives must decrypt and decompress.
decrypt "$TARGET/db.sql.gz.enc" | gzip -t
decrypt "$TARGET/storage.tar.gz.enc" | tar -tzf - > /dev/null

# 5. Off-site copy (FULL_PLAN §13): any rsync target the server can reach.
if [[ -n "${BACKUP_REMOTE:-}" ]]; then
  rsync -a "$TARGET" "$BACKUP_REMOTE/"
fi

# 6. Retention.
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf {} +

status true "ok"
echo "Backup $STAMP written to $TARGET and verified."
