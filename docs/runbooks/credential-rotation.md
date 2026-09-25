# Compromised credential rotation

Act immediately; record everything in one incident ticket.

**App Store Connect API key (`.p8`)**
1. In App Store Connect → Users and Access → Integrations, **revoke** the key.
2. Create a new key with the same role. On the server: `php artisan apple:store-key AuthKey_XXXX.p8`
   (prints the vault reference; delete the file afterwards).
3. Admin → Команды Apple → «Ключ API» with issuer ID, key ID and reference, then «Проверить подключение».
4. Review the audit log and Apple's device list for actions you did not make (`quota.mismatch` helps).

**Signing identity (distribution certificate private key)**
1. Revoke the certificate in the Apple developer portal. **All apps signed with it stop launching** (R2):
   notify customers first if you can.
2. `php artisan certificate:revoke <SHA-1> --reason="Key leaked, incident #…"` — marks the certificate
   revoked and revokes every build signed with it, so no one is handed a build that will not launch.
3. Create a new certificate on the runner Mac ([certificate expiry](certificate-expiry.md) steps 1–3) and
   remove the old identity from the Keychain.
4. Customers reinstall from the Storefront (or `/install.html` for the Storefront itself); each request
   signs a fresh build with the new identity.

**Runner worker key**
1. Admin → Задачи → Подпись → disable the runner (reason). Its requests are refused at once.
2. `php artisan runner:create mac-mini-1b`, put the new key in the LaunchAgent, restart it.

**`APP_KEY` / `STOREFRONT_UDID_HMAC_KEY` / backup passphrase**
- `APP_KEY` encrypts UDIDs, TOTP secrets, profiles and runner secrets: rotating it requires
  re-encrypting those columns — plan it, do not just replace it. Invalidate sessions after.
- The UDID HMAC key must not change (uniqueness depends on it); if it leaked, UDID hashes can be
  brute-forced only with the UDIDs themselves — treat as a privacy incident, not a key swap.
- A leaked backup passphrase: create a new one, take a fresh backup, delete old backups off-site.

**Staff account**: Admin → Пользователи → suspend, reset TOTP, then check the audit log for that actor.
