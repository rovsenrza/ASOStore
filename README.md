# Ru App Store

Monorepo for the customer web portal, the native iOS Storefront, the operator admin panel and the PHP backend.

- What and why: [docs/adr/FULL_PLAN.md](docs/adr/FULL_PLAN.md)
- Order of work, decisions, done criteria: [docs/adr/IMPLEMENTATION_PLAN.md](docs/adr/IMPLEMENTATION_PLAN.md)
- API contract: [docs/api/openapi.yaml](docs/api/openapi.yaml), with shared examples in [docs/api/examples/](docs/api/examples/)

## Layout

| Path | What |
|---|---|
| [backend/](backend/) | Laravel 12 API (`/api/v1`), MySQL, domain services, tests |
| [front/public/](front/public/) | Customer portal: static HTML/CSS/vanilla JS, Russian |
| [admin/public/](admin/public/) | Operator panel: static HTML/CSS/vanilla JS |
| [shared/js/](shared/js/) | API client, mock transport, i18n used by both web apps |
| [ios/](ios/) | Native SwiftUI Storefront (`Storefront.xcodeproj`) |
| [runner/](runner/) | Signing runner (Swift, Linux/Docker, zsign): leases signing jobs, re-signs per device — see its README |
| [docs/runbooks/](docs/runbooks/) | Operational runbooks; [docs/RELEASE_CHECKLIST.md](docs/RELEASE_CHECKLIST.md); [docs/security/](docs/security/) |
| [scripts/](scripts/) | `deploy.sh` production deploy; `backup.sh` / `restore-drill.sh` encrypted backups and the restore drill; `dev.sh` runs everything locally; `build-public.sh` publishes the completed Vite build and admin into Laravel's docroot; `web-smoke.mjs` checks them; `e2e.sh` runs the browser suite; `generate-api-examples.sh` rebuilds the API examples; `device-payload.php` signs a fake iPhone enrollment answer for tests |
| [tests/e2e/](tests/e2e/) | Playwright browser journeys across portal and admin |

Everything is served from one origin (IMPLEMENTATION_PLAN D1): portal at `/`, admin at `/admin/`, API at `/api/v1`.

## Quick start (macOS)

Requires PHP 8.2+, Composer, MySQL 8 (or compatible), Node 22 (for checks only) and Xcode 27.

```bash
# Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
# set STOREFRONT_UDID_HMAC_KEY in .env: php -r "echo base64_encode(random_bytes(32));"
mysql -uroot -e "CREATE DATABASE storefront; CREATE DATABASE storefront_test;"
php artisan migrate --seed          # prints the seeded admin password (or set SEED_ADMIN_PASSWORD)

# Everything at once, from the repo root: web build, API server, queue worker, scheduler
./scripts/dev.sh                    # http://127.0.0.1:8000 · rerun npm build in front/ after editing the website, then build-public.sh
open http://127.0.0.1:8000          # add ?mock=1 to use the example fixtures instead of the API

# iOS
open ios/Storefront.xcodeproj       # Debug uses mock data; launch argument `-apiMode live` uses the local API
```

**Admin panel** (`/admin/`): sign in as `admin@storefront.test`. The first sign-in enrols an authenticator app (TOTP); every later sign-in asks for its code. Operators are created from the Users page and receive an email to set their password (`MAIL_MAILER=log` writes it to `backend/storage/logs/laravel.log` locally).

**Customers** register at `/register.html`, then redeem an activation code issued from Admin → Users → Коды активации (or `php artisan activation:issue --count=5 --days=365`).

**Devices** are enrolled from `/activate.html` in Safari on the iPhone (an iOS Profile Service reports the UDID). Locally, `STOREFRONT_APPLE_DRIVER=fake` simulates the Apple registration; `php artisan db:seed --class=FakeAppleTeamSeeder` creates the fake team.

## Connecting the Apple Developer account

Until then the driver is `disabled` and enrolled devices wait with reason `APPLE_NOT_CONNECTED`. With a paid membership and an App Store Connect API key (Users and Access → Integrations):

```bash
cd backend
php artisan apple:store-key ~/Downloads/AuthKey_KEYID.p8     # prints encrypted-file:…; then delete the .p8
# .env: STOREFRONT_APPLE_DRIVER=appstoreconnect
php artisan apple:connect TEAMID --name="Company" --issuer-id=ISSUER-UUID --key-id=KEYID \
  --key=encrypted-file:secrets/apple/KEYID.p8.enc --membership-ends=YYYY-MM-DD
```

`apple:connect` verifies the key with Apple before activating the team. Waiting devices are registered within five minutes (the scheduler must be running).

## Enrolling a real iPhone locally

iOS only posts the device answer to an HTTPS address it can reach. Expose the local server through a tunnel, e.g. `cloudflared tunnel --url http://127.0.0.1:8000`, set `APP_URL` to the tunnel URL and `TRUSTED_PROXIES=*` in `backend/.env`, then open `https://<tunnel>/activate.html` in Safari on the iPhone. No Apple account is needed for this step; with the fake driver the device then shows as registered.

## Operations

| Command | What |
|---|---|
| `php artisan activation:issue --count=N [--days=D] [--note=…]` | Issue activation codes (printed once) |
| `php artisan telegram:store-bot` | Run the Telegram subscription bot (configure `TELEGRAM_STORE_*` first) |
| `php artisan admin:reset-totp EMAIL --reason=…` | Break-glass reset of a staff authenticator |
| `php artisan apple:store-key PATH` / `apple:connect …` | Store the Apple key encrypted; connect the primary team (more teams: Admin → Команды Apple) |
| `php artisan runner:create NAME` | Register a signing runner; prints its worker key once (runner/README.md) |
| `php artisan certificate:revoke SHA1 --reason=…` | Revoke a signing certificate and every build signed with it |
| `BACKUP_PASSPHRASE=… ./scripts/backup.sh` | Encrypted DB + storage backup, verified; cron daily ([runbook](docs/runbooks/database-restore.md)) |
| `BACKUP_PASSPHRASE=… ./scripts/restore-drill.sh` | Restore the newest backup into a throwaway DB and verify it |

Cron on the server: `* * * * * cd backend && php artisan schedule:run` — runs the queue worker (D6), lease recovery, link expiry, quota reconciliation, metrics, alerts and retention.

### IPA storage

IPAs (`originals/`, `signed/`) live on the `artifacts` disk. `ARTIFACTS_DRIVER=local` keeps them in `storage/app/artifacts`; `ARTIFACTS_DRIVER=s3` uses the bucket in `AWS_*` (Contabo Object Storage in production, endpoint `https://eu2.contabostorage.com`). Rows always say `storage_disk = artifacts` (the column is immutable), so moving storage means copying the files with the same paths (`rclone copy`) and then switching the driver. From object storage, IPA downloads are relayed with HTTP Range support, and inspection and signature checks work on a temporary local copy (`LocalArtifactFile`).

### Email confirmation

A website sign-up gets a six-digit code by email (15 minutes, 5 attempts, resend once a minute) and confirms it on `/verify-email.html`. Until then, website sessions receive `EMAIL_NOT_VERIFIED` from activation, device and install endpoints, and `/storefront/status` reports `email_verification_required`. Token sessions (the iOS app) are not affected. Accounts invited by staff or completing a password reset count as confirmed. Mail goes out over SMTP (Brevo in production, see `backend/.env.example`).

### Telegram store bot

`php artisan telegram:store-bot` runs the Ru App Store sales bot (long polling; systemd unit in [ops/systemd/storefront-telegram-bot.service](ops/systemd/storefront-telegram-bot.service)). Code: [backend/app/Services/TelegramStore](backend/app/Services/TelegramStore).

- **Funnel:** plans (1 / 6 / 12 months, 590 / 1770 / 2360 ₽ by default) with per-month price and saving → order with a 30-minute window → balance and/or payment method → activation code in the chat, always available again under Profile → My orders. Opening a new order cancels the previous unpaid one; expired orders are swept every minute, held balance is refunded and the customer gets a one-tap "order again" message.
- **Referrals:** every customer has a `t.me/<bot>?start=ref_<code>` link. The referrer earns a share (15 % by default) of the money each invited customer pays, credited to an internal balance that pays for orders fully or partly. Every balance change has a ledger row (`telegram_store_balance_transactions`).
- **Promo codes:** percentage or fixed discount, optional use limit, expiry and plans; one use per customer. Customers enter a code on the order, or open `t.me/<bot>?start=promo_<CODE>` and the code goes on their next order. Admins create codes in one line (`/promo BLOGER20 20% лимит 100 до 31.12.2026 тариф 6,12 заметка …`) and see paid uses, revenue and discounts per code. The referral share is taken from the discounted amount.
- **Admin panel** (`/admin`, for `TELEGRAM_STORE_ADMIN_IDS`): sales stats by period (mock payments listed separately), review queue with confirm / reject, price and referral-share editing, broadcasts (preview first, sent by `TelegramStoreBroadcastJob` on the queue, blocked users are marked), customer lookup (`/user <id|@name>`) and balance adjustments. `/paid <ORDER_ID>` confirms a payment; `/cancel` leaves a multi-step input.
- **Payments (Platega):** with `PLATEGA_MERCHANT_ID` and `PLATEGA_SECRET` set, every customer pays by card or SBP on Platega's page («💳 Картой или через СБП» in the bot, «Оплатить» on the website), and mock mode is ignored. Platega's dashboard must send callbacks to `https://<site>/api/v1/payments/platega/callback`; the callback only names the transaction, whose state is always read back from Platega's API. «Проверить оплату» in the bot, the website's result page and a scheduled sweep (`platega:reconcile`, every two minutes, orders of the last 3 hours) ask Platega too, so a lost callback only delays an order. A payment that is short, repeated or arrives after a closed order that held balance is not completed automatically: the admins get a message. Code: `Payments\PlategaClient`, `Payments\PlategaPayments`, `Payments\PlategaGateway`.
- **Website purchase:** `POST /api/v1/store/checkout` opens an order for the signed-in, email-confirmed account (`telegram_store_orders.user_id`, no chat) and returns Platega's page. Platega sends the payer back to `/payment.html?order=<id>`, which polls `GET /api/v1/store/orders/{id}`. Once paid, the order's activation code is redeemed on the account (`OrderFulfillment`), so the access is on without typing a code. Signed-out visitors go through sign-up or sign-in and come back to the purchase page (`?next=`).
- **Without Platega:** `TELEGRAM_STORE_MOCK_PAYMENTS=true` shows payment methods only to admins and `TELEGRAM_STORE_TESTER_IDS`; a simulated payment completes the order for real, so the whole funnel can be tested. Everyone else sees that online payment opens soon. With mock off, each configured `TELEGRAM_STORE_*_URL` is a payment method and an admin confirms the payment.

Broadcasts run on the `default` queue (the `storefront-queue-apple@` workers also serve it). After a deploy, restart the bot: `systemctl restart storefront-telegram-bot`. Only one process may poll a bot token: stop the server bot before running it locally. Rotate the bot token if it has ever been pasted into chat or committed.

## Checks

```bash
cd backend && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse && ./vendor/bin/pest
npx @stoplight/spectral-cli@6 lint docs/api/openapi.yaml --ruleset .spectral.yaml
BASE_URL=http://127.0.0.1:8000 CHROME_BIN=/path/to/chrome node scripts/web-smoke.mjs
./scripts/e2e.sh                    # Playwright browser journeys on a throwaway database (storefront_e2e)
xcodebuild test -project ios/Storefront.xcodeproj -scheme Storefront -destination "platform=iOS Simulator,name=iPhone Air"
(cd runner && swift test)          # signing runner
```

After changing an endpoint, regenerate the shared examples from a real run (throwaway database `storefront_examples`) and let the contract tests confirm them:

```bash
./scripts/generate-api-examples.sh
```

CI runs the same checks: [.github/workflows/ci.yml](.github/workflows/ci.yml).
