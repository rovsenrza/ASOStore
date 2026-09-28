# Certificate expiry

**Trigger.** Alert `certificate.expiring` (30 days before), team status `EXPIRING` (membership ends
within 30 days), or profiles «истекают скоро» on the team card.

Installed apps stop launching when the signing certificate or profile expires (R2). Act early.

**Distribution certificate**
1. On the runner server create a key and CSR in the identities folder, then a new Apple Distribution
   certificate for the team from that CSR ([runner/README.md](../../runner/README.md#identities-folder)).
2. Save it next to the key as `<name>.cer` (owner uid 10001, mode 0400); check
   `docker run --rm -v /etc/storefront/signing:/run/secrets/signing:ro storefront-runner --list-identities`.
3. Within a minute the heartbeat registers it; the team card shows it «на сервере подписи».
4. New profiles use the newest valid certificate. Existing installs keep working until the old one
   expires. Builds signed with an expired certificate are never handed out again: the next install
   request signs a fresh build. To move users over early, `php artisan certificate:revoke <old SHA-1>
   --reason="Replaced before expiry"` and ask them to reinstall.
5. After the old certificate expires, remove its `.key` and `.cer` from the identities folder
   (keep the offline backup until no build signed with it is in use).

**Membership year**
1. Renew the Apple Developer membership.
2. Admin → Команды Apple → «Добавить год членства» with the new dates (slots are counted per year).
3. Set the team back to «Активна» (reason: "Membership renewed until …").
