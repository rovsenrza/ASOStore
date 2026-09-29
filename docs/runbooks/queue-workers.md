# Queue workers

**Trigger.** Installs stuck in «Подготовка» while the runner is online, or alert `queue_backlog`.

**Layout.** systemd runs two kinds of workers (`ops/systemd`, installed by
`scripts/install-queue-workers.sh`):

| Units | Queue | Jobs | Timeout |
|---|---|---|---|
| `storefront-queue-apple@1..3` | `apple`, `default` | Profiles (`PrepareSigningJob`), device registrations | 5 min |
| `storefront-queue-files@1..2` | `files` (connection `database_files`) | `InspectArtifactJob`, `VerifySignatureJob` | 30 min |

A large IPA being verified never delays a customer's profile, and one slow app does not hold up
the others. Deploys run `php artisan queue:restart`; workers finish their job and systemd starts
them on the new code.

1. Status and logs:
   ```sh
   systemctl --no-pager list-units 'storefront-queue-*'
   journalctl -u 'storefront-queue-apple@*' --since '30 min ago'
   ```
2. A job left `RUNNING` by a killed worker is closed as a failed attempt and run again when the
   queue hands it out next (`PipelineJobService::abandonStale`). If its queue row is gone (e.g. from
   before this mechanism), dispatch it again:
   ```sh
   sudo -u storefront php artisan tinker --execute='App\Jobs\PrepareSigningJob::dispatch(<pipeline job id>);'
   ```
3. More workers: `APPLE_WORKERS=4 ./scripts/install-queue-workers.sh` (as root). Each prepare also
   starts one short-lived process per new extension profile (`Concurrency::run`).
