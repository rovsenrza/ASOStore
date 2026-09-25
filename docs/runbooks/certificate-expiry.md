# Certificate expiry

**Trigger.** Alert `certificate.expiring` (30 days before), team status `EXPIRING` (membership ends
within 30 days), or profiles «истекают скоро» on the team card.

Installed apps stop launching when the signing certificate or profile expires (R2). Act early.

**Distribution certificate**
1. In the Apple developer portal create a new Apple Distribution certificate for the team (CSR made on the runner Mac).
2. Import it into the runner's signing Keychain; check `storefront-runner --list-identities`.
3. Within a minute the heartbeat registers it; the team card shows it «на Mac подписи».
4. New profiles use the newest valid certificate. Existing installs keep working until the old one
   expires. Builds signed with an expired certificate are never handed out again: the next install
   request signs a fresh build. To move users over early, `php artisan certificate:revoke <old SHA-1>
   --reason="Replaced before expiry"` and ask them to reinstall.
5. After the old certificate expires, remove it from the Keychain.

**Membership year**
1. Renew the Apple Developer membership.
2. Admin → Команды Apple → «Добавить год членства» with the new dates (slots are counted per year).
3. Set the team back to «Активна» (reason: "Membership renewed until …").
