# Database restore

Backups: `scripts/backup.sh` (cron, daily) writes an encrypted dump of MySQL (with triggers) and
of private storage, a manifest with checksums and row counts, verifies both archives, copies them
off-site (`BACKUP_REMOTE`), and records the result in `storage/app/backup-status.json`. The alert
`backup` fires when the latest backup is older than 26 hours or failed verification.

The passphrase (`BACKUP_PASSPHRASE`) is **not** on the server; it is in the team password manager.

## Drill (monthly, and before launch)

```sh
BACKUP_PASSPHRASE=… ./scripts/restore-drill.sh            # newest backup
BACKUP_PASSPHRASE=… ./scripts/restore-drill.sh /path/to/backup/20261001T030000Z
```

The drill restores into a throwaway database and checks checksums, migrations, row counts, the
audit immutability triggers, and sampled artifact files. It drops the throwaway database at the end.

### Drill log

| Date | Backup | Result | Notes |
|---|---|---|---|
| 2026-09-25 | local dev DB `storefront` | **PASS** (1 s) | First drill found that dumps from a GTID-enabled MySQL could not be restored into another server with its own GTID history; `backup.sh` now adds `--set-gtid-purged=OFF`. A wrong passphrase makes the drill fail as expected. The dev DB had no artifact files, so file sampling checked 0 files — repeat on staging with real uploads. |

## Real restore

1. Put the site in maintenance: `php artisan down --retry=60`. Stop the scheduler cron and the runner.
2. Decide the restore point; restore into a **new** database first:
   ```sh
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in db.sql.gz.enc | gunzip | mysql storefront_restored
   ```
3. Run the drill checks against it (`scripts/restore-drill.sh <backup>` shows the expected counts).
4. Point `DB_DATABASE` at the restored database, `php artisan migrate --force` (applies anything newer),
   `php artisan config:cache`.
5. Restore storage if needed ([storage restore](storage-restore.md)).
6. `php artisan up`. Record in the audit ticket what time range was lost; pipeline jobs that were
   in flight will be retried or can be retried from Admin → Задачи.
