# Signing runner offline

**Trigger.** Alert `runner-offline` (signing jobs waiting and no runner heartbeat for
5 minutes), or Admin → Задачи → Подпись shows «Нет связи». Customers see
installs stuck in «Подготовка».

**Safe by design.** Jobs stay `QUEUED`; leases of a dead runner expire after 10 minutes and go
back to the queue; the idempotency key prevents a second signed build when it returns.

1. On the runner server:
   ```sh
   docker ps -a --filter name=storefront-runner --format '{{.Status}}'
   docker logs --since 30m storefront-runner
   ```
2. Common causes:
   - Container stopped (server rebooted, Docker restarted) → `docker start storefront-runner`; it runs with
     `--restart unless-stopped`, so check why Docker did not start it.
   - `identity …: the key does not belong to the certificate` or an empty identity list → the
     identities folder is not mounted or not readable by uid 10001.
   - Clock skew > 5 minutes → requests are rejected as unsigned; fix NTP.
   - `401 Invalid runner signature` → key rotated or runner disabled in Admin → Задачи → Подпись.
3. `storefront-runner --list-identities` (runner/README.md) must show the team's distribution identity; otherwise
   provisioning fails with `NO_SIGNING_CERTIFICATE`.
4. Once the card shows «На связи», queued jobs are leased within the polling interval. Verify one
   installation reaches «Готово к установке» (Admin → Задачи → Установки).
5. If the server is lost: start the runner on another Linux host (runner/README.md), `php artisan runner:create`,
   restore the identities folder from the encrypted backup, and disable the old runner with a reason.
