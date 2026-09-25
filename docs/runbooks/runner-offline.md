# Signing runner offline

**Trigger.** Alert `runner-offline` (signing jobs waiting and no runner heartbeat for
5 minutes), or Admin → Задачи → Подпись shows «Нет связи». Customers see
installs stuck in «Подготовка».

**Safe by design.** Jobs stay `QUEUED`; leases of a dead runner expire after 10 minutes and go
back to the queue; the idempotency key prevents a second signed build when it returns.

1. On the Mac (as the `storefront` user):
   ```sh
   launchctl print gui/$(id -u)/az.storefront.runner | grep -E 'state|last exit'
   log show --last 30m --predicate 'subsystem == "storefront.runner"'
   ```
2. Common causes:
   - Mac asleep / rebooted without login → enable automatic login for the runner user or restart the agent:
     `launchctl kickstart -k gui/$(id -u)/az.storefront.runner`.
   - Signing Keychain locked → `security unlock-keychain ~/Library/Keychains/signing.keychain-db`.
   - Clock skew > 5 minutes → requests are rejected as unsigned; fix NTP.
   - `401 Invalid runner signature` → key rotated or runner disabled in Admin → Задачи → Подпись.
3. `storefront-runner --list-identities` must show the team's distribution identity; otherwise
   provisioning fails with `NO_SIGNING_CERTIFICATE`.
4. Once the card shows «На связи», queued jobs are leased within the polling interval. Verify one
   installation reaches «Готово к установке» (Admin → Задачи → Установки).
5. If the Mac is lost: rebuild a second runner (runner/README.md), `php artisan runner:create`, import
   the distribution identity from the secured backup, and disable the old runner with a reason.
