# Ru AppStore

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
| [scripts/](scripts/) | `deploy.sh` production deploy; `backup.sh` / `restore-drill.sh` encrypted backups and the restore drill; `dev.sh` runs everything locally; `build-public.sh` publishes the web apps into Laravel's docroot; `web-smoke.mjs` checks them; `e2e.sh` runs the browser suite; `generate-api-examples.sh` rebuilds the API examples; `device-payload.php` signs a fake iPhone enrollment answer for tests |
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
./scripts/dev.sh                    # http://127.0.0.1:8000 · rerun build-public.sh after editing front/, admin/ or shared/
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
| `php artisan admin:reset-totp EMAIL --reason=…` | Break-glass reset of a staff authenticator |
| `php artisan apple:store-key PATH` / `apple:connect …` | Store the Apple key encrypted; connect the primary team (more teams: Admin → Команды Apple) |
| `php artisan runner:create NAME` | Register a signing runner; prints its worker key once (runner/README.md) |
| `php artisan certificate:revoke SHA1 --reason=…` | Revoke a signing certificate and every build signed with it |
| `BACKUP_PASSPHRASE=… ./scripts/backup.sh` | Encrypted DB + storage backup, verified; cron daily ([runbook](docs/runbooks/database-restore.md)) |
| `BACKUP_PASSPHRASE=… ./scripts/restore-drill.sh` | Restore the newest backup into a throwaway DB and verify it |

Cron on the server: `* * * * * cd backend && php artisan schedule:run` — runs the queue worker (D6), lease recovery, link expiry, quota reconciliation, metrics, alerts and retention.

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
