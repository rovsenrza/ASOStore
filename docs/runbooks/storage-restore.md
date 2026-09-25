# Object storage restore

Private storage (`ARTIFACTS_PATH`, default `storage/app/artifacts`) holds original IPAs
(`originals/`), signed builds (`signed/`) and provenance documents (`provenance/`). It is in the
same encrypted backup as the database (`storage.tar.gz.enc`).

1. Find which files are missing: download attempts fail with 5xx, inspection finds `SIGNED_FILE_MISSING`,
   or the artifact detail download fails.
2. Restore the folder from the latest backup into a temporary location:
   ```sh
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in storage.tar.gz.enc | tar -xzf - -C /tmp/restore
   ```
3. Copy back only what is missing (`rsync -a --ignore-existing /tmp/restore/artifacts/ "$ARTIFACTS_PATH"/`).
4. Verify originals against the database: every `app_artifacts.sha256` must match its file
   (the restore drill does this for a sample).
5. **Signed builds do not need a backup to be correct:** if a signed file is missing, revoke nothing —
   ask affected customers to install again; a new build is signed from the original.
6. Files created after the backup are gone: re-upload originals from the publisher and let inspection run.
