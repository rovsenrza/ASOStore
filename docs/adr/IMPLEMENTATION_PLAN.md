# Implementation Plan — Web Portal, Native Storefront, Admin Panel, PHP Backend

> **Derived from:** [FULL_PLAN.md](FULL_PLAN.md) v1.0  
> **Date:** 2026-09-24  
> **Status:** Draft. Confirm the decisions in §3 and the questions in §10 during Phase 0.

FULL_PLAN defines **what** we build and the rules we can't break. This document defines **the order of work, who does it, how each part is built, and when it counts as done**. When this plan settles something FULL_PLAN leaves unclear, the item is marked **[G#]** (see §2). Phase numbers match FULL_PLAN §16 so the two documents map directly onto each other.

---

## 0. Summary

- **Five tracks:** Backend (BE), Web portal (WEB), Admin panel (ADM), iOS Storefront (IOS), macOS signing runner (RUN), plus Ops/Docs (OPS).
- **Critical path:** Phase 0 decisions → backend skeleton → auth → device enrollment + single-team Apple adapter → IPA upload/inspection → signing runner + OTA handoff → **physical iPhone install gate** (Phase 6). iOS catalog UI and admin screens run in parallel against the OpenAPI contract and a mock transport.
- **Rough duration:** 15–18 weeks with 1 backend, 1 iOS, and 1 web/admin engineer, plus part-time compliance and ops (see §7). This is an estimate. Re-plan after Phase 1.
- **Biggest risk:** whether Apple's program terms allow the intended distribution channel for the intended audience (R1 in §9). This is a Phase 0 blocking decision because it decides whether Phases 6–7 work as designed.

### 0.1 Status (updated 2026-09-25)

**Phases 1–8 implemented as far as possible without the Apple Developer account, a test iPhone and the production host; Phase 0 Apple/compliance decisions remain open.** Release status: [RELEASE_CHECKLIST.md](../RELEASE_CHECKLIST.md). What remains is listed per phase below. The Apple integration sits behind a driver switch (`disabled` / `fake` / `appstoreconnect`); connecting the account is `apple:store-key` + `apple:connect` (README).

Phase 5 is in progress. P5-BE-01 is implemented: authorized 8 MiB resumable uploads, received/missing chunk discovery, idempotent chunk replay, streamed SHA-256 assembly, duplicate/corruption handling, immutable artifact creation on the private `artifacts` disk, provenance capture, RBAC, audit records and OpenAPI coverage.

P5-BE-02 is implemented: completing an upload queues `InspectArtifactJob` (an operator-visible `pipeline_jobs` row with attempts, retry with backoff up to `max_attempts`, then `FAILED_PERMANENT`). The job re-verifies the stored SHA-256 (`HASHING`), then `IpaInspector` runs the §5.7 checks: ZIP safety (entry count, total size, compression-ratio zip-bomb guard, absolute/`..`/backslash paths, case-folded duplicates, escaping symlinks, password-protected entries, nothing outside `Payload/` and Apple's side folders, exactly one `Payload/*.app`), Info.plist (XML or binary) metadata, Mach-O thin/fat parsing of the main executable, every `.appex`, framework and dylib, **`cryptid ≠ 0` → `REJECTED` (`ENCRYPTED_BINARY`)**, entitlements from the code signature, the `embedded.mobileprovision` summary (type, team, expiry, device *count* only — no UDIDs are stored), and a ClamAV scan when `STOREFRONT_CLAMDSCAN_PATH` is set (otherwise `SCAN_UNAVAILABLE`; a hit → `QUARANTINED`). Catalog checks then enforce the linked version, one bundle ID per app, and version uniqueness (`VERSION_EXISTS`). The full report is stored in `app_artifacts.inspection`; the failure code in `status_reason`. Non-blocking findings (`ARM64_MISSING`, `SIMULATOR_BUILD`, `IPHONE_UNSUPPORTED`, watch app / App Clip, nested bundle ID prefix) are recorded as `compatibility_issues` for the reviewer and for `COMPATIBILITY_CHECK`. Synthetic fixtures (`tests/Support/IpaBuilder.php`) cover the encrypted, zip-bomb, traversal and malformed cases.

Deviations: `artifact_reviews` and `provenance_documents` moved to P5-BE-03 with the review actions that use them. The real `fixtures/DemoApp` IPA (P5-OPS-01) is still to do.

P5-BE-03 is implemented (admin API; the screens are P5-ADM-01/02):
- `POST /admin/artifacts/{id}/review` — approve needs every item of the configured provenance checklist (`storefront.artifacts.review_checklist`, versioned; wording pending P0-02) and, unless the scan reported `CLEAN`, `acknowledge_scan_result`. Approval runs `COMPATIBILITY_CHECK` in the same transaction → `READY`, or `REJECTED` with the first blocking finding (`ARM64_MISSING`, `SIMULATOR_BUILD`, watch app / App Clip, nested bundle-ID prefix, `SOURCE_TYPE_MISMATCH` against the listing, `SOURCE_TYPE_NOT_PUBLISHABLE`). Reject needs a reason → `PROVENANCE_FAILED`. Quarantined files can be released (reason required) back to `PROVENANCE_REVIEW`, never straight to `READY`, or rejected. Each decision is an append-only `artifact_reviews` row plus audited transitions. Optional four-eyes rule: `STOREFRONT_INDEPENDENT_REVIEW=true`.
- `POST /admin/artifacts/{id}/publish` — only `READY` → `PUBLISHED`, re-checks the publishable source types (`STOREFRONT_PUBLISHABLE_SOURCE_TYPES`, all six by default; narrow it to the P0-01 decision, e.g. `OWN_BUILD` for the pilot), creates or links the catalog version, and moves the app's previous published build to `EXPIRED` (`SUPERSEDED`), so each app has one installable build.
- `POST /admin/artifacts/{id}/revoke` (reason required), `POST /admin/artifacts/{id}/inspect` (re-inspection from `INSPECTION_FAILED`), provenance documents (upload + audited download, private disk), `GET /admin/artifacts[/{id}]` with `available_actions`, and `GET /admin/jobs[/{id}]` + `POST /admin/jobs/{id}/retry` (new abilities `jobs.view` / `jobs.manage`). Mutating calls accept `Idempotency-Key`.
- Team eligibility (`team_app_eligibilities`) is recorded as a `TEAM_ELIGIBILITY_NOT_CHECKED` warning until Phase 7 enforces it at `COMPATIBILITY_CHECK`.

P5-ADM-01 and P5-OPS-01 are done (2026-09-26): `admin/artifacts.html` uploads a batch of IPAs, two at a time, in 8 MiB chunks with a SHA-256 per chunk, per-file progress, pause/resume (the server's missing-chunk list drives the resume, so no chunk is sent twice) and an independent result per file (its own upload session, artifact, job and audit trail). The provenance declaration must be ticked first (text is a draft pending P0-02). Tabs for the provenance review queue, quarantine and all artifacts open a detail with the inspection report, compatibility result, documents, reviews, jobs and history, and offer only the moves `available_actions` allows (approve with the checklist and scan acknowledgement, reject, release, publish, revoke, re-inspect). Playwright covers a batch with one broken file, review and publish, a multi-chunk upload, and pause/resume. `fixtures/DemoApp` + `scripts/export-demo-ipa.sh` build the real, unsigned test IPA with `swiftc` (no Xcode project or Apple account needed); it passes inspection as a clean arm64 iOS 18 app (`php artisan ipa:inspect`), and CI builds it. P5-BE-04's cleanup runs in the Phase 8 retention job; signed, logged downloads were built in Phase 6.

### 0.2 Phase 6 status (2026-09-25)

Implemented and tested without an Apple account (fake Apple driver + simulated runner in Pest; the Swift runner builds and its signing, identity and entitlement logic is unit-tested):

- **Backend.** `runners`, `certificates` (metadata only), `signing_profiles` (encrypted .mobileprovision), `signed_builds`, `installations`, `install_authorizations`, `installation_events`. `ProfileProvisioner` creates one ad hoc profile per (team, bundle ID, device) through the Apple adapter (App Store Connect: certificates, bundleIds, profiles). `PrepareSigningJob` → runner `SignArtifactJob` → `VerifySignatureJob`, all tracked as pipeline jobs sharing `PipelineQueueJob` (attempts, backoff, `RetryLater` for Apple rate limits). The verifier re-hashes the upload, re-runs the IPA checks and requires an unchanged bundle ID and version, the provisioned profile UUID and team, and the device's UDID in the embedded profile (compared in memory only).
- **Worker API** `/api/worker/v1` (HMAC key + timestamp + single-use nonce + body hash): heartbeat with Keychain identities, lease (`FOR UPDATE SKIP LOCKED`, 10-minute lease, only jobs whose certificate that runner holds), lease heartbeat, source download, streamed upload, result. Expired leases return to the queue every minute; the job's idempotency key prevents a second build.
- **Customer API.** `POST /apps/{id}/prepare`, `POST /storefront/install`, `GET /installations/{id}`, `POST /installations/{id}/authorize` (single-use, device-bound, 10 minutes), `GET /install/{token}/manifest.plist`, `GET /downloads/installations/{id}` (10-minute signed URL, HTTP Range, tampered/expired links answer 403 and are audited), `GET /library`. Every step re-checks device eligibility, published artifact and deliverable build; revoking or superseding an artifact revokes/expires its builds and fails open installations. `install_state` in the catalog now reflects the device's real state (`get`, `preparing` with progress, `ready_to_install`, `delivered`, `update_available`, `failed`).
- **Runner** (`runner/`, Swift package, no dependencies): lease loop, source hash check, profile embedding, entitlements from the profile, inside-out `codesign`, `codesign --verify --strict`, repack, upload, per-job folder wiped; `--list-identities`; launchd agent and README. CI job added.
- **Portal** `install.html`: prepare → progress with backoff (resumes after reload) → authorize → `itms-services`; trust-developer and recovery instructions; iPhone-only guard.
- **iOS.** `PreparationRepository`, `InstallationCoordinator` (prepare → poll → authorize → `openURL`, in-flight installations persisted and resumed on launch), CTA driven by live installation state, real Library screen.
- **Admin** `jobs.html`: runner health (online/offline, identities, current leases, queue length), pipeline jobs with attempts and audited retry, installation timelines with request IDs. New abilities: `installations.view`, `teams.view`, `teams.manage`.

Deviations: clients poll `GET /installations/{id}` instead of `GET /jobs/{id}` (an installation spans several jobs). Apps with extensions, watch apps or App Clips fail signing with `NESTED_PROFILE_REQUIRED` until per-bundle profiles are provisioned. Device-bound native tokens fall back to the account's latest device when a token has no claim binding (one device per account by default).

Still open for the Phase 6 gate: the P6-SPIKE ADR and every physical check (real profile creation, real `codesign` with the team's distribution identity, OTA install on an iPhone) need the paid Apple Developer account and a test iPhone.

### 0.3 Phase 7 status (2026-09-25)

Implemented and tested:

- **Quotas.** `team_quotas` (one lockable row per team × membership year × family; remaining is computed) and `quota_reservations` (RESERVED → CONSUMED / RELEASED, 30-minute TTL, expired ones released every five minutes). Every reservation takes `SELECT … FOR UPDATE` on the quota row. **Exit-gate test** (`tests/Concurrency`): 20 real PHP processes race for 1 remaining slot against MySQL — exactly 1 is reserved, 19 are blocked, counters stay consistent (passes repeatedly).
- **Team selection** (FULL_PLAN §6.2). New devices still go to the primary team. When it is exhausted, `TeamSelector` looks for another team that is active, connected, inside a membership year, has free slots for the family and is approved for the Storefront's bundle ID. If one exists, the device becomes `QUOTA_BLOCKED` / `AWAITING_TEAM_APPROVAL` with a PENDING `team_assignments` row explaining the choice; otherwise `NO_ELIGIBLE_TEAM`, with an audit event saying nothing was switched. Nothing registers with another team until an admin approves (reason required, audited, re-checked at decision time).
- **App/team eligibility** (`team_app_eligibilities`, evidence + approver). Enforced at `COMPATIBILITY_CHECK` (`TEAM_NOT_ELIGIBLE`) and before a profile is created (`STOREFRONT_REQUIRE_TEAM_ELIGIBILITY`, on by default).
- **Reconciliation** (`QuotaReconciler`, nightly and on demand): Apple's device list per family vs local counters; mismatches are audited and logged as alerts, never corrected. Membership years ending within 30 days move the team to `EXPIRING`; expiring certificates alert once a day.
- **Admin API + `teams.html`**: team onboarding (record → key reference → test connection → membership year → activate; activation is refused before a successful test), quota bars per family with Apple's count, eligibilities, certificates and profiles, pending approvals with approve/reject, blocking banner, dashboard quota widget. Abilities `teams.view` / `teams.manage` are admin-only.
- **Portal and app** already show `blocked` with `QUOTA_EXHAUSTED` / `NO_ELIGIBLE_TEAM` copy («Регистрация временно недоступна»); the iOS install coordinator maps both.

Behaviour change: at the limit with no eligible team a device is now `NO_ELIGIBLE_TEAM` (Phase 3 used `QUOTA_BLOCKED` for every exhausted case). Blocked registrations may move between the blocked states on retry but never to `ELIGIBLE` without Apple.

Open: the membership limits and real device counts must be confirmed against the live Apple account; the fake driver counts every device as an iPhone.

### 0.4 Phase 8 status (2026-09-25)

Implemented and verified locally; the release gate itself needs the owners' sign-off and the items that depend on the Apple account, a device and the host (see [RELEASE_CHECKLIST.md](../RELEASE_CHECKLIST.md)).

- **P8-SEC-01.** CSP enforced (no inline code; Google Fonts allowed) in the middleware and in `.htaccess` for static files, HSTS on HTTPS responses, `Secure` session cookies by default outside local/testing, dotfiles denied, `composer audit` in CI (clean today). A Playwright journey loads every page under the enforced CSP and fails on any violation. Rate limits cover login, register, password reset, activation, enrollment, claims, prepare/authorize, manifest/download, support and data export. Self-review against ASVS L2: [docs/security/asvs-l2-review.md](../security/asvs-l2-review.md) (open: independent pentest, breached-password check, `APP_KEY` re-encryption tool).
- **P8-SEC-02.** Data export (`GET /account/export`, UDID masked), deletion request, admin erasure (sessions revoked, registrations disabled, personal data replaced; UDID purged by retention after the Apple membership year ends). Audit rows are immutable, so from now on customers appear in them by public ID and anonymous emails are masked. Retention job: abandoned uploads (P5-BE-04), files of rejected artifacts and dead signed builds, installation events, UDIDs of erased accounts. Spike alerts for downloads per user and enrollments per IP. `certificate:revoke` revokes builds signed with a compromised certificate, and builds are only reused while their certificate is valid.
- **P8-OPS-01.** `scripts/backup.sh` (encrypted dump with triggers + private storage, manifest with checksums and row counts, verification, off-site rsync, retention, status file) and `scripts/restore-drill.sh`. **Drill passed** on the local database; the first run found that GTID-enabled dumps could not be restored and the backup now uses `--set-gtid-purged=OFF`.
- **P8-OPS-02.** All ten FULL_PLAN §14 metrics every five minutes (`metric_snapshots`, dashboard panel); alerts (credentials, quota, repeated signing failures, queue backlog, runner offline, storage, spikes, backup) to the `alerts` log channel and Slack when configured, once per hour each, also audited. The D6 cron queue worker is now scheduled.
- **P8-DOC-01.** Nine runbooks in [docs/runbooks/](../runbooks/). Tabletop exercises not yet held.
- **P8-WEB-01.** Support form (works signed out; admin tab for tickets), data section on the account page, factual privacy draft marked as not legally approved. Terms and pricing stay placeholders.
- **P8-IOS-01.** Logging audit: the two log statements carry privacy annotations and no tokens or identifiers. The physical-device matrix needs devices.
- **P8-OPS-03.** `scripts/deploy.sh` (refuses non-production `.env`, `composer audit`, migrations, `MOCKS=0` web build, caches, queue restart); route and config caching verified. Deployment itself waits for the host (P0-05).

### 0.5 Brand and native redesign (2026-09-26)

- **Brand: Ru AppStore.** Source artwork and generated assets in `branding/` (transparent round logo, opaque 1024 px app icon without black corners, favicons). Applied to the portal and admin (header logo, favicons, titles), the API title, `APP_NAME`, and the iOS app (AppIcon, `BrandLogo`, display name, brand-blue AccentColor with light/dark variants).
- **Trademark risk (new, for the owners):** "App Store" and the A-shaped glyph in the logo are Apple trademarks/trade dress. Apple can object to the name or the icon, and this business depends on Apple Developer accounts (R1). Get a legal opinion before launch; a changed name or glyph means replacing the files in `branding/`.
- **Native redesign** after the stakeholder's reference screens, deliberately not identical to Apple's App Store: tabs Главная / Игры / Приложения / Менеджер / Поиск; logo + glass capsule (notifications, account) on every tab; brand glow behind the header; hero carousel with the app's first screenshot as banner; sections as paged columns of three rows; install capsule «Установить» (green when the build for this iPhone is ready); Менеджер = this device's installations with filters; Поиск = search field plus «Обновлено / Новое» with real totals; account as a sheet (membership, device, subscriptions, store settings, data and storage). Light and dark mode both use semantic colours.
- **Backend for it:** `app_categories.kind` (APPS / GAMES, editable in admin), `GET /storefront/feed?kind=`, `GET /apps?kind=&sort=featured|updated|new`, `feature_image_url` on app cards, and feed sections «Самые загружаемые» / «Тенденции» computed from delivered installations (omitted while there is no data — no invented rankings). Demo seed adds three game categories with fictional games.
- **Not built from the reference:** the «Подписать IPA / Источники / Импортировать IPA по ссылке» section of the reference Менеджер. Letting customers import and sign arbitrary IPAs on their phones is a product and compliance decision (FULL_PLAN §1.2, §5.1.1; P0-01), not a design change. The notification bell shows only real events (builds ready to install).

Phase 4 verified locally: catalog CRUD, taxonomy, versions and normalized media uploads are covered by Pest; the native Today/Browse/Search/AppDetail screens use `CatalogRepository` against the API with an offline cache. The iOS suite covers content, navigation, search, empty, offline, unauthorized, expired and server-error states. Russian UI strings now have a String Catalog. `MockCatalog` remains Debug-only. The remaining Phase 4 exit-gate check is the live admin → API → Simulator journey in CI/local integration mode.

Phase 3 verified: 229 Pest tests (signed enrollment answers, challenge reuse and expiry, device ownership and limits, slot reservation at the per-family limit, Apple retry/permanent/processing paths, claims, admin reveal, App Store Connect driver against recorded HTTP responses, UDID privacy), 11 Playwright journeys including iPhone enrollment, 41 iOS tests (deep links, claim sign-in). The `storefront://` scheme opens the app in the Simulator.

Deviations and follow-ups from Phase 3:

- **Apple device CA check is off by default.** The device answer's signature is always verified; set `STOREFRONT_ENROLLMENT_DEVICE_CA` to require a genuine Apple device chain once a real iPhone has been tested (stops invented UDIDs from using team slots).
- **The .mobileconfig is unsigned** until `STOREFRONT_PROFILE_SIGNING_CERT/KEY` point at the site's TLS certificate; iOS then shows it as verified.
- **One device per account** (`STOREFRONT_MAX_DEVICES_PER_ACCOUNT`), pending the product decision.
- **Slot counting covers devices registered through this system only.** Reconciling with Apple's own device list is Phase 7.
- **Registration jobs are not yet listed in `pipeline_jobs`**; the operator jobs screen arrives with Phase 5. Device history is visible on the admin devices page.

Phase 2 verified: 174 Pest tests (role matrix, token rotation and reuse, TOTP, activation codes, idempotency, rate limits, contract checks for every endpoint), Larastan and Pint clean, 7 Playwright browser journeys (`scripts/e2e.sh`), 36 iOS tests (single-flight refresh, retry after 401, Keychain, session store).

Deviations and follow-ups from Phase 2:

- **TOTP secrets live on `users`** (encrypted columns) rather than a separate `totp_secrets` table.
- **No TOTP recovery codes yet.** A lost authenticator is reset by another admin (audited, reason required).
- **Email verification is not required at registration** (open question §10 Q5).
- **Two bugs found only by browser tests**, now fixed and covered: admin sign-in lost its CSRF token after `session()->invalidate()`, and route-model binding ran before the staff check (404 instead of 403 for probes).

Phase 1 verified locally: 112 Pest tests, Larastan level 6 and Pint clean; Spectral clean; web smoke (40 URLs plus rendered pages in headless Chromium); 19 iOS Swift Testing tests; the Simulator app reaches the local API in live mode.

Deviations and follow-ups from Phase 1:

- **Laravel 12, not 13.** The local PHP is 8.2 (D15). Laravel 12 receives security fixes until early 2027; move to PHP 8.3+ and Laravel 13 once the host is chosen (P0-05).
- **No Sail yet (D14).** Docker was not running, so development used the local MySQL server (9.6). CI tests against MySQL 8.0. Still open: add Sail or pin MySQL 8 locally.
- ~~Validation messages are English.~~ Russian messages for the rules in use were added in Phase 2 (`backend/lang/ru`).
- **Web smoke uses headless Chrome directly** (`scripts/web-smoke.mjs`); interactive flows are covered by Playwright since Phase 2.
- **Catalog screens now use the API through `CatalogRepository`.** `MockCatalog` is Debug-only. The Library tab intentionally remains an empty-state shell until the installation API arrives in Phase 6; the Account tab uses real data since Phase 2.
- **Bundle ID and API hosts are placeholders** (`ios/Config/*.xcconfig`) pending P0-04.
- **CI runs on GitHub Actions.** Simulator signing must remain enabled for Keychain tests; it uses local ad-hoc Simulator signing and does not require an Apple Developer account.

---

## 1. Starting point (as of 2026-09-24)

| Area | What exists | Gap vs FULL_PLAN |
|---|---|---|
| Repository | Plain folder, **not a git repo**. A 258 MB `IMG_0377.MP4` sits in the root, along with `.build/`, `.playwright-cli/`, `.impeccable/` | Needs `git init`, `.gitignore`, and a decision on video and design artifacts |
| `docs/` | `adr/FULL_PLAN.md` only | `APPSTORE.md`, `APP_PLAN.md`, and `PLAN.md` are referenced by FULL_PLAN and `PRODUCT.md` but missing. `docs/api/openapi.yaml` and `docs/runbooks/` don't exist |
| `backend/` | `.gitkeep` only | Everything |
| `admin/` | Does not exist | Everything |
| `front/` | One-page demo: `index.html`, `styles.css`, `app.js` (~340 lines), a setup dialog, and a fake status check (`DEMO-24`) | Needs the `front/public/` layout, 10 pages (§10 of FULL_PLAN), an API client, session handling, and an i18n dictionary |
| `ios/` | `Untitled Project` SwiftUI app (~1,270 lines). Tabs: Today / Apps / Search / Library / Account, plus Detail. `MockCatalog`, design system, components. Swift 6, file-system-synchronized groups | No networking, auth, Keychain, routing, or Preparation feature. `AppActionButton` flips its title locally, which breaks the rule that the CTA comes from backend state. Project-level deployment target is **27.0** and target-level is **18.0**. Bundle ID is a placeholder. The mock catalog shows ratings and rating counts that no data source provides |
| Runner | Nothing | Everything, and it isn't in the FULL_PLAN repo layout **[G11]** |
| Research | `research/diyorde-*.md`: reference analysis of the bootstrap → UDID → signed-build flow | Use as flow reference only |

---

## 2. Gaps and contradictions in FULL_PLAN, and how this plan resolves them

| # | Issue | Resolution |
|---|---|---|
| G1 | FULL_PLAN and `PRODUCT.md` link `APPSTORE.md`, `APP_PLAN.md`, `PLAN.md`, which are not in the repo | Treat FULL_PLAN as the only source of truth. In Phase 0, restore those files or remove the links |
| G2 | The web page list in §3 (5 pages) differs from §10 (10 pages) | Use the **§10 list**, placed under `front/public/` as §3 shows |
| G3 | iOS folder names in §3 (`Browse`, `AppDetail`) differ from the existing code (`Apps`, `Detail`) | Adopt the §3 names during the Phase 1 restructure. The tab label stays «Приложения» |
| G4 | The domain table `jobs` collides with Laravel's queue table `jobs`. `sessions` is also Laravel's web session table | Domain tables become `pipeline_jobs` and `pipeline_job_attempts`. Laravel keeps `jobs`, `failed_jobs`, `job_batches`, `sessions`. iOS tokens use separate tables (D3) |
| G5 | §7 asks for unique `(apple_team_id, device_udid, membership_year_id)` **and** encrypted UDIDs. Encrypted values are non-deterministic, so a unique index on them never matches | Store `udid_encrypted` (Laravel `encrypted` cast) plus `udid_hash` = HMAC-SHA256(UDID, dedicated key). Unique indexes and lookups use `udid_hash` |
| G6 | The §5.2 lifecycle mixes the **source artifact** (one per upload) with **signed builds** (one per device or profile). Ad hoc signing depends on the device set, so a source artifact can be `PUBLISHED` before any device's build exists | Two state machines (§5.1). "Publishable" means source is `READY`. "Installable for device X" means source is `PUBLISHED` **and** a signed build covering X is `DELIVERABLE`. This also matches FULL_PLAN §18 step 5 ("ready flow with fake signing") |
| G7 | Registering a real iPhone (Phase 3 gate) needs the Apple API, but Apple team work sits in Phase 7 | Move a **single-team** Apple adapter into Phase 3. Phase 7 keeps multi-team, quotas, and eligibility |
| G8 | Nothing tells the native app which registered device it is running on (iOS apps can't read the UDID) | **Claim-code flow:** the portal, which knows the device from enrollment, issues a one-time code and opens `storefront://claim?code=…`. The app exchanges the code for device-bound tokens. A Secure Enclave key binding is optional Phase 8 hardening |
| G9 | §9 is missing endpoints the flows need | Added in §5.8: enrollment profile/callback, refresh, password reset, claim, feed, OTA manifest, download, chunked uploads, review/reject/revoke, categories/publishers, activation codes, installations, and the worker API |
| G10 | §13 wants short-lived access tokens plus refresh rotation. Laravel Sanctum has no refresh tokens | Custom `refresh_tokens` table with rotation and reuse detection (D3) |
| G11 | No home for the macOS signing runner in the repo layout | Add `runner/` (§4) |
| G12 | IPAs can be hundreds of MB to several GB. Shared hosting caps `upload_max_filesize` and execution time | Chunked, resumable upload API. SHA-256 is computed incrementally while chunks are assembled |
| G13 | The Library "installed" state can't actually be observed. iOS doesn't report OTA install success to the server or to other apps | Library shows server-known states. `DELIVERED` (IPA fully downloaded) is the terminal state, labeled honestly («Загружено — проверьте экран «Домой»») |
| G14 | iOS deployment target conflict (27.0 project vs 18.0 target). Bundle ID is a placeholder, and it becomes permanent once registered with Apple | Set 18.0 everywhere (the `Tab` API needs iOS 18). Pick the final bundle ID prefix in Phase 0, **before** any Apple registration |
| G15 | Mock data shows ratings and review counts. The data model has none, and `PRODUCT.md` forbids fabricated metrics | Drop ratings from the API model and the UI. Ratings are post-MVP |
| G16 | No Today/feed endpoint, so the native Today tab has nothing to load | `GET /storefront/feed` returns curated sections |

---

## 3. Technical decisions (defaults to confirm in Phase 0)

Record each confirmed decision as a short ADR (`docs/adr/0001-….md`).

| # | Decision | Default | Why / alternative |
|---|---|---|---|
| D1 | Deployment topology | **Single origin.** Portal at `/`, admin at `/admin/`, API at `/api/v1`, worker API at `/api/worker/v1`. Laravel `backend/public` is the docroot. Static sites are copied in at deploy time. `.htaccess` sets `DirectoryIndex index.html` so `/` serves the portal | Simplest cookie/CSRF story on shared hosting. Alternative: subdomains with `SESSION_DOMAIN=.example.com` |
| D2 | Web and admin auth | **Laravel Sanctum SPA cookie sessions** (HttpOnly, Secure, SameSite=Lax, `XSRF-TOKEN` CSRF) | Laravel's built-in option; no tokens stored in browser JS |
| D3 | iOS auth | Sanctum personal access token with **15-minute** expiry, plus an opaque **refresh token** (30 days, stored hashed, rotated on every use; reusing an old one revokes the whole token family). Tokens live in the Keychain | Covers FULL_PLAN §13. Alternative: Laravel Passport, which is heavier |
| D4 | Admin 2FA | **TOTP required** for every non-customer role *(addition to FULL_PLAN)* | Admins control publishing and Apple credentials |
| D5 | IDs | Internal `BIGINT` primary keys. Public IDs are **ULIDs** in every API response and URL | No enumerable IDs |
| D6 | Queue | MVP: `database` driver, with cron `schedule:run` every minute and `queue:work --stop-when-empty --max-time=50` scheduled `withoutOverlapping()`. Production: Redis plus a supervised worker | Matches FULL_PLAN §2.2 |
| D7 | Artifact storage | Laravel disk `artifacts` (private, outside the docroot) behind the `Storage` abstraction. Downloads use `URL::temporarySignedRoute`, stream with HTTP Range support, and use X-Sendfile where the host allows it. Later: S3-compatible storage with presigned URLs | Swapping disks later changes no code |
| D8 | Apple secrets | The App Store Connect `.p8` key is stored encrypted **outside the docroot** (key from env). `apple_credentials.vault_reference` points to it. In production, move it to a secrets manager. **Signing-certificate private keys exist only in the runner's macOS Keychain**; the backend stores only metadata (serial, fingerprint, expiry) | FULL_PLAN §6.1 and §13 |
| D9 | Runner connectivity | **Pull model.** The runner leases jobs from the worker API over HTTPS with HMAC-signed requests (key ID + timestamp + nonce), behind an IP allowlist or VPN. No inbound ports on the Mac | The Mac can sit behind NAT; the backend never pushes secrets |
| D10 | Signing granularity | **One ad hoc profile per (team, bundle ID, device)** for the MVP, so each signed build is per device. Confirm Apple profile-count limits and re-provisioning behavior in the Phase 6 spike | Matches device-bound authorization and avoids re-signing everyone when a device is added. Alternative: shared multi-device profiles |
| D11 | API contract | **OpenAPI 3.1, contract-first.** Shared JSON examples in `docs/api/examples/` are used by Laravel response tests **and** iOS decoding tests. Linted with Spectral in CI | Keeps the three clients aligned (FULL_PLAN §9) |
| D12 | iOS baseline | iOS **18.0** minimum, Swift 6 strict concurrency, `@Observable` stores, no third-party dependencies in the MVP, Swift Testing plus XCUITest | The existing code already uses the iOS 18 `Tab` API |
| D13 | Shared web code | `shared/js/` (API client, envelope, errors, i18n loader) is copied into both `front/public` and `admin/public` by `scripts/build-public.sh`. No bundler | FULL_PLAN §18.2: "shared API client patterns", no framework |
| D14 | Local environment | **Laravel Sail** (Docker: PHP, MySQL 8, Mailpit). Static sites are served by the same Laravel app locally | Exact MySQL 8 parity. Alternative: Laravel Herd plus DBngin |
| D15 | Framework version | The current supported Laravel release at project start. **Check its minimum PHP version against the host**; FULL_PLAN says PHP 8.2+, and newer Laravel releases may need newer PHP | Avoids discovering a hosting mismatch in Phase 8 |

---

## 4. Target repository layout

```text
.
├── backend/                     # Laravel app (API, admin API, worker API, jobs)
│   ├── app/
│   │   ├── Enums/               # all state enums + error codes
│   │   ├── Http/Controllers/Api/V1/{Customer,Admin,Worker}/
│   │   ├── Http/Middleware/     # RequestId, Envelope, RequireRole, WorkerHmac, SecurityHeaders
│   │   ├── Models/
│   │   ├── Policies/
│   │   ├── Services/{Auth,Activation,Devices,Apple,Quotas,Catalog,Artifacts,Inspection,Signing,Installations,Notifications,Audit}/
│   │   ├── StateMachines/       # ArtifactStateMachine, SignedBuildStateMachine, …
│   │   └── Jobs/
│   ├── database/{migrations,seeders,factories}/
│   ├── routes/{api.php,worker.php,web.php}
│   └── tests/{Unit,Feature,Fixtures/ipa}/
├── front/public/                # customer portal (FULL_PLAN §10 pages)
│   ├── *.html  css/  js/pages/  js/i18n/ru.js  assets/
├── admin/public/                # operator panel (FULL_PLAN §3 pages)
│   ├── *.html  css/  js/pages/  js/components/  js/i18n/ru.js
├── shared/js/                   # api-client.js, envelope.js, errors.js, i18n.js, mock-transport.js
├── ios/
│   ├── Storefront.xcodeproj
│   ├── Storefront/{App,Features/{Today,Browse,Search,Library,Account,AppDetail,Preparation},Core/{Networking,Auth,Keychain,Routing,Models,Persistence},DesignSystem,Components,Resources}
│   ├── Config/{Local,Staging,Production}.xcconfig
│   └── StorefrontTests/  StorefrontUITests/
├── runner/                      # macOS signing runner (Swift Package executable) [G11]
│   ├── Package.swift  Sources/Runner/  Tests/  README.md
├── fixtures/DemoApp/            # tiny Swift app used as the authorized test IPA (FULL_PLAN §18.4)
├── docs/
│   ├── adr/  api/openapi.yaml  api/examples/*.json  runbooks/
├── scripts/                     # build-public.sh, deploy.sh, export-demo-ipa.sh
└── .github/workflows/           # or the CI system chosen in Phase 0
```

---

## 5. Cross-cutting design

### 5.1 State machines [G6]

Each machine is a PHP enum plus a transition map in `app/StateMachines/`. `transition($model, $to, $actor, $reason)` runs inside a DB transaction, rejects illegal moves with `IllegalTransition`, and writes the audit event **in the same transaction**. Every allowed and forbidden pair gets a unit test.

```text
Source artifact
  UPLOADED → HASHING → INSPECTING → PROVENANCE_REVIEW → COMPATIBILITY_CHECK → READY → PUBLISHED
  failure:  REJECTED | INSPECTION_FAILED | PROVENANCE_FAILED | QUARANTINED
  later:    READY|PUBLISHED → REVOKED ;  PUBLISHED → EXPIRED

Signed build (per artifact × device profile)
  SIGNING_PENDING → SIGNING → SIGNED → SIGNATURE_VERIFIED → DELIVERABLE
  failure:  SIGNING_FAILED | VALIDATION_FAILED
  later:    DELIVERABLE → EXPIRED (profile/cert) | REVOKED

Device registration
  ENROLLED → APPLE_PENDING → ELIGIBLE
  failure:  APPLE_FAILED | QUOTA_BLOCKED | NO_ELIGIBLE_TEAM
  later:    ELIGIBLE → DISABLED

Installation
  PREPARING → READY_TO_INSTALL → AUTHORIZED → MANIFEST_FETCHED → DELIVERED
  failure:  FAILED(code) | EXPIRED

Pipeline job
  QUEUED → LEASED → RUNNING → SUCCEEDED
  failure:  FAILED_RETRYABLE → QUEUED (backoff) | FAILED_PERMANENT | CANCELLED
```

Pipeline job columns, per FULL_PLAN §8.3: `public_id`, `type`, `idempotency_key` (unique), `attempt`, `actor_type/actor_id`, `correlation_id`, `payload` (JSON, no secrets), `lease_owner`, `lease_expires_at`, `started_at`, `finished_at`, `result_code`, `error_class`, `error_message_redacted`.

### 5.2 API envelope and error codes

- Envelope `{data, meta: {request_id}, error: null | {code, message, details}}` is applied by middleware. `X-Request-Id` is accepted from the client or generated, sent back in the response, and added to the log context.
- Error `code` is a stable machine string. `message` is a Russian fallback. Clients map `code` → copy through their i18n dictionaries.

Initial catalog: `VALIDATION_FAILED`, `UNAUTHENTICATED`, `SESSION_EXPIRED`, `FORBIDDEN`, `NOT_FOUND`, `RATE_LIMITED`, `ACTIVATION_INVALID`, `ACTIVATION_ALREADY_USED`, `ENROLLMENT_CHALLENGE_EXPIRED`, `DEVICE_NOT_ELIGIBLE`, `DEVICE_PENDING_APPLE`, `QUOTA_EXHAUSTED`, `NO_ELIGIBLE_TEAM`, `ARTIFACT_NOT_INSTALLABLE`, `INCOMPATIBLE_DEVICE`, `INSTALL_TOKEN_EXPIRED`, `DUPLICATE_ARTIFACT`, `VERSION_EXISTS`, `ENCRYPTED_BINARY`, `IDEMPOTENCY_CONFLICT`, `APPLE_UNAVAILABLE`, `INTERNAL`.

### 5.3 Audit log

- `AuditService::record(actor, action, subject, before, after, reason, correlation_id)`. Actions are namespaced (`artifact.published`, `team.assignment.approved`, `device.udid.revealed`, …).
- **Immutable:** MySQL `BEFORE UPDATE` and `BEFORE DELETE` triggers `SIGNAL` an error on `audit_logs` and on the original rows in `app_artifacts`. If the host forbids triggers, fall back to a DB user without UPDATE/DELETE on those tables, plus a hash chain (`prev_hash`, `hash`) checked nightly.
- UDIDs, tokens, and secrets are never written to audit payloads. Use masked values (`00008030-…-4A2E`).

### 5.4 Idempotency

Every mutating endpoint that starts work (`/apps/{id}/prepare`, `/installations/{id}/authorize`, upload `complete`, `/activation/redeem`, admin publish/retry) accepts `Idempotency-Key`. The `idempotency_keys` table stores (key, user, route, request hash, response snapshot) for 24 hours. The same key with a different body returns `IDEMPOTENCY_CONFLICT`. Jobs carry their own `idempotency_key` (e.g. `sign:{artifact}:{device}:{profile}`).

### 5.5 Device enrollment flow (Phase 3)

```text
iPhone Safari (logged in)            Backend                                  Apple ASC API
activate.html «Установить профиль»
GET /devices/enrollment-profile ──► enrollment_challenge (single use, 15 min)
                                ◄── signed .mobileconfig (Profile Service payload:
                                    URL + Challenge; DeviceAttributes = UDID, PRODUCT, VERSION only)
Settings → user reviews and installs (explicit iOS action; JS cannot do this)
POST /devices/enrollment/callback ─► verify challenge; parse PKCS#7 plist
  (sent by iOS)                      upsert device: udid_encrypted + udid_hash, model, iOS version
                                     device_registration = ENROLLED; audit
                                     dispatch RegisterDeviceJob ───────────► POST /v1/devices
                                ◄── 301 → /activate.html?enrollment={ulid}
activate.html polls GET /storefront/status (backoff 3s→30s) and shows the honest pending state
```

- The `.mobileconfig` is signed with the site's TLS certificate (`openssl_pkcs7_sign`) so iOS shows it as verified. Collect the minimum attributes only: no SERIAL, IMEI, or ICCID.
- `RegisterDeviceJob` is idempotent: it looks up the UDID in Apple's device list before registering. New devices can stay pending on Apple's side. The UI must say so and must not promise a time.
- Device family comes from `PRODUCT` (`iPhone…` → IPHONE, `iPad…` → IPAD) and is used for quotas.

### 5.6 Install handoff flow (Phase 6)

```text
Storefront app                        Backend                                   Runner (macOS)
POST /apps/{id}/prepare ───────────► checks: session, device ELIGIBLE, source PUBLISHED, compatible
                                     reuse DELIVERABLE build for (artifact, device) if present,
                                     else signed_build SIGNING_PENDING + pipeline_job
                        ◄─────────── 202 {installation_id, job_id}
GET /jobs/{id} (poll; resumes after relaunch)                              lease → download original
                                                                           → ensure device profile
                                                                           → re-sign inside-out
                                                                           → codesign --verify --strict
                                                                           → upload derivative + result
                                     VerifySignatureJob: hash, bundle ID unchanged, embedded
                                     profile contains the device, team matches → DELIVERABLE
POST /installations/{id}/authorize ► single-use, device-bound token (10 min); audit
                        ◄─────────── itms-services://?action=download-manifest&url=https://…/install/{token}/manifest.plist
openURL(...) → iOS fetches manifest → fetches IPA via signed URL → system install prompt
                                     installation_events: MANIFEST_FETCHED, DOWNLOAD_STARTED,
                                     DOWNLOAD_COMPLETED → installation DELIVERED
```

The native Storefront **is itself a catalog artifact** (`OWN_BUILD`, flagged `is_storefront`). The portal's `install.html` uses the same prepare → authorize → manifest path for it. The first Phase 6 end-to-end test is therefore the Storefront installing itself.

### 5.7 IPA inspection checks (Phase 5)

| Check | How | On failure |
|---|---|---|
| Upload integrity | Client-declared size and optional SHA-256 compared with the server hash computed incrementally during chunk assembly | `REJECTED` (`UPLOAD_CORRUPT`) |
| Duplicate | Unique `app_artifacts.sha256` | `REJECTED` (`DUPLICATE_ARTIFACT`), linking to the existing artifact |
| Archive safety | `ZipArchive`: entry-count cap, uncompressed/compressed ratio cap, no absolute or `..` paths, exactly one `Payload/*.app` | `INSPECTION_FAILED` |
| Metadata | `Info.plist` (binary or XML) via CFPropertyList: bundle ID, short version, build, `MinimumOSVersion`, `UIDeviceFamily`, executable | `INSPECTION_FAILED` |
| Architectures | Mach-O / fat-header parse; `arm64` required | Compatibility failure |
| **Encryption** | `LC_ENCRYPTION_INFO(_64).cryptid ≠ 0` in the main executable, any `.appex`, or any framework | **`REJECTED` (`ENCRYPTED_BINARY`).** Enforces FULL_PLAN §1.2 automatically |
| Nested bundles | `PlugIns/*.appex`, `Frameworks/*`, `Watch/*` recorded with their bundle IDs | Unsupported nesting → compatibility failure |
| Entitlements | Parse the `LC_CODE_SIGNATURE` entitlements blob. The runner re-checks with `codesign -d --entitlements` | Capabilities the team profile can't grant → compatibility failure |
| Embedded profile | XML plist extracted from `embedded.mobileprovision`: team ID, type, expiry | Recorded |
| Malware | `clamdscan` if available; otherwise status `SCAN_UNAVAILABLE`, which the reviewer must acknowledge | `QUARANTINED` |
| Version uniqueness | Unique `(app_id, version, build_number)` | `REJECTED` (`VERSION_EXISTS`) |
| Team relationship | Bundle ID registered to an eligible team (`team_app_eligibilities`) | Compatibility failure, with a reason telling the operator what's missing |

Technical inspection is not provenance certification (FULL_PLAN §5.1.1). A human `PROVENANCE_REVIEW` step is always required before `READY`.

### 5.8 Data model and endpoint additions

**Tables added to FULL_PLAN §7:** `pipeline_jobs`, `pipeline_job_attempts` [G4], `refresh_tokens` [G10], `enrollment_challenges`, `storefront_claims` [G8], `upload_sessions` + `upload_chunks` [G12], `signed_builds` [G6], `install_authorizations`, `team_app_eligibilities`, `quota_reservations`, `idempotency_keys`, `totp_secrets` (encrypted), `metric_snapshots`.

**Column rules:** `devices.udid_encrypted` + `devices.udid_hash` [G5]; unique `(apple_team_id, udid_hash, membership_year_id)` on `device_registrations`. `team_quotas.remaining_count` is computed (`limit − registered − reserved`), not stored.

**Customer endpoints added:** `GET /auth/me`, `POST /auth/refresh`, `POST /auth/password/forgot`, `POST /auth/password/reset`, `GET /devices/enrollment-profile`, `POST /devices/enrollment/callback`, `POST /storefront/claims` (web session → code), `POST /storefront/claims/redeem` (app → tokens), `GET /storefront/feed` [G16], `GET /apps?q=&category=`, `GET /install/{token}/manifest.plist`, `GET /downloads/{signed}`.

`POST /devices/register` from FULL_PLAN §9 becomes the enrollment-callback pair above.

**Admin endpoints added:** `/admin/auth/*` (login + TOTP), `/admin/dashboard`, `/admin/activation-codes`, `/admin/categories`, `/admin/publishers`, `/admin/uploads` (`POST` init, `PUT /{id}/chunks/{n}`, `POST /{id}/complete`), `POST /admin/artifacts/{id}/review` (approve/reject with reason), `POST /admin/artifacts/{id}/revoke`, `/admin/installations`, `/admin/team-eligibilities`, `POST /admin/quota-assignments/{id}/approve`, `GET /admin/audit-logs/export`.

**Worker API (runner only):** `POST /worker/v1/leases`, `POST /worker/v1/jobs/{id}/heartbeat`, `GET /worker/v1/jobs/{id}/source` (signed), `PUT /worker/v1/jobs/{id}/artifact`, `POST /worker/v1/jobs/{id}/result`, `POST /worker/v1/heartbeat` (runner health).

### 5.9 Roles (RBAC)

FULL_PLAN asks for role-based access but doesn't define the roles. Proposed:

| Role | Can |
|---|---|
| `customer` | Own account, devices, installations |
| `support` | Read users, devices (masked), installations, and jobs; create tickets. No publishing, no teams |
| `catalog_manager` | Apps, categories, publishers, uploads, reviews, publish/reject |
| `admin` | Everything above, plus Apple teams, credential references, quota approvals, roles, and UDID reveal (audited) |

Laravel Policies enforce object-level access. The admin UI hides what a role can't do, but the server is the only enforcement point.

---

## 6. Phase-by-phase plan

Each phase lists its tasks by track and ends with an **exit gate**, the FULL_PLAN gate expanded into checks someone can verify. Task IDs (`P3-BE-02`) are for tracking.

### Phase 0 — Product and compliance lock *(≈1 week, overlaps Phase 1)*

**Goal:** no unresolved decision about what is installable, by whom, or through which Apple channel.

| ID | Track | Task |
|---|---|---|
| P0-01 | Compliance | **Written determination of the distribution channel per source type**: which audiences may receive ad hoc-signed builds under the Apple Developer Program terms, and which need App Store / TestFlight / Custom Apps / Alternative Marketplace instead. Record it as an ADR (R1) |
| P0-02 | Compliance | Approve the source-type list (FULL_PLAN §5.1), the upload responsibility declaration text (versioned), and the provenance review checklist |
| P0-03 | Product | Confirm the Apple Developer team for testing: **paid membership**, and an App Store Connect API key with the needed role. Identify the first physical test iPhone |
| P0-04 | Product | Final **bundle ID prefix** and domain (permanent once registered, even while the brand stays `[BRAND]`) [G14] |
| P0-05 | Ops | Hosting survey: PHP version vs D15, cron every minute, SSH, MySQL triggers, upload limits, X-Sendfile, disk quota, TLS. Pick the MVP host |
| P0-06 | Product | Russian copy inventory and the `[BRAND]` placeholder rules. Error-code copy owner |
| P0-07 | All | Confirm or amend decisions D1–D15. Record them as ADRs. Resolve G1 (missing docs) |

**Exit gate:** P0-01 signed off. Test Apple team and device identified. Bundle ID fixed. Host chosen. D1–D15 accepted or amended.

### Phase 1 — Repository and local environment *(≈1.5 weeks)*

**Goal:** website, admin, API, and iOS app all run locally against one contract.

| ID | Track | Task |
|---|---|---|
| P1-OPS-01 | OPS | `git init`. `.gitignore`: `IMG_0377.MP4` (or Git LFS), `.build/`, `**/.playwright-cli/`, `front/output/`, `backend/vendor`, `.env`, `storage/`, `xcuserdata`, DerivedData. Decide whether `.impeccable/` and `research/` are versioned |
| P1-OPS-02 | OPS | CI pipeline: backend (Pint, Larastan, Pest with a MySQL 8 service), OpenAPI lint (Spectral), iOS (`xcodebuild test`, simulator), web (HTML validation + Playwright smoke) |
| P1-BE-01 | BE | Laravel skeleton in `backend/`, Sail (MySQL 8, Mailpit), `.env.example`, `config/hashing.php` → `argon2id` |
| P1-BE-02 | BE | Middleware: `RequestId`, envelope response macro, exception → error-code mapper, `SecurityHeaders` (report-only CSP for now). `GET /api/v1/health` |
| P1-BE-03 | BE | Migrations (FULL_PLAN §18.3, with this plan's names): `users`, `roles`, `user_roles`, `app_categories`, `app_publishers`, `apps`, `app_versions`, `app_artifacts`, `devices`, `pipeline_jobs`, `pipeline_job_attempts`, `audit_logs`, `idempotency_keys`. Queue tables use Laravel defaults [G4] |
| P1-BE-04 | BE | `AuditService` plus immutability triggers (with fallback, §5.3). State-machine base class with tests |
| P1-BE-05 | BE | Seeders: Russian demo catalog ported from `ios/…/MockCatalog.swift`, **without ratings** [G15]. One admin user |
| P1-DOC-01 | BE | `docs/api/openapi.yaml` v0: envelope, error schema, health, catalog read endpoints, `storefront/status`. `docs/api/examples/*.json` |
| P1-WEB-01 | WEB | Move the demo to `front/public/`. Split `app.js` into `js/pages/index.js`. Create the §10 page shells (`pricing`, `login`, `register`, `activate`, `install`, `account`, `support`, `terms`, `privacy`) with shared layout and the `ru.js` dictionary |
| P1-WEB-02 | WEB | `shared/js/api-client.js` (fetch, envelope, CSRF cookie bootstrap, error mapping, request ID), `mock-transport.js` (enabled by `?mock=1` or config, replacing the `DEMO-24` logic), and `scripts/build-public.sh` [D13] |
| P1-ADM-01 | ADM | `admin/public/` shell: `login.html`, `index.html`, and navigation to the 9 FULL_PLAN pages. Reusable data-table (sort, filter, paginate), status badge, confirm dialog, and toast components |
| P1-IOS-01 | IOS | Rename the project/target `Untitled Project` → `Storefront`. Restructure into the §4 folders [G3]. Set the deployment target to 18.0 at both levels. Set the real bundle ID [G14] |
| P1-IOS-02 | IOS | `Config/*.xcconfig` with `API_BASE_URL` per environment. `APIClient` (actor, async/await, envelope decoding, `X-Request-Id`). `MockTransport` fed by `docs/api/examples` fixtures. `LoadState<T>` (loading, loaded, empty, offline, unauthorized, expired, serverError) and a reusable `StateContainerView` |
| P1-IOS-03 | IOS | Remove ratings from the models and UI [G15]. Replace the local title flip in `AppActionButton` with an `InstallState` input (mock-driven for now) |

**Exit gate:**
- [x] `php artisan migrate --seed` gives a working API. `/api/v1/health` returns the envelope. *(Run against local MySQL; Sail not set up yet, see §0.1.)*
- [x] `scripts/build-public.sh` produces a portal at `/` and an admin at `/admin/`, both calling `/api/v1` on the same origin.
- [x] The iOS app builds as `Storefront`, runs in Simulator with `MockTransport`, and CI runs its tests. *(CI workflow written; first remote run pending a git host.)*
- [x] The OpenAPI file passes lint, and the example fixtures decode in both PHP and Swift tests.

### Phase 2 — Authentication and accounts *(≈1.5–2 weeks)*

**Goal:** customer and admin authenticate with separate permissions, and every auth action is audited.

| ID | Track | Task |
|---|---|---|
| P2-BE-01 | BE | Sanctum SPA auth: `register`, `login`, `logout`, `me`, `password/forgot`, `password/reset` (mail via Mailpit locally). Argon2id |
| P2-BE-02 | BE | iOS token issue plus `refresh_tokens` with rotation and reuse detection (`POST /auth/refresh`) [D3, G10] |
| P2-BE-03 | BE | Roles and policies (§5.9). `RequireRole` middleware. Admin TOTP enrollment and verification [D4] |
| P2-BE-04 | BE | `activation_codes` (stored as HMAC hashes, shown once at creation; statuses ISSUED/REDEEMED/EXPIRED/REVOKED), `subscriptions` placeholder, `POST /activation/redeem`, admin batch generation |
| P2-BE-05 | BE | Rate limits: login (5/min per IP+email), register, forgot-password, redeem. Audit events for all of them |
| P2-WEB-01 | WEB | `register.html`, `login.html` (including forgot/reset states), `account.html` (profile + activation status). Central session module; redirect guards on private pages |
| P2-ADM-01 | ADM | Admin login + TOTP. `users.html` (list, detail, roles, activation codes tab). `audit.html` v1 (filterable list) |
| P2-IOS-01 | IOS | `KeychainStore` (`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`). `SessionStore` (`@Observable`, `@MainActor`). Single-flight token refresh inside the `APIClient` actor. Fallback in-app login form. Unauthorized/expired states wired to the root view |

**Exit gate:**
- [x] Feature tests prove: a customer gets 403 on every `/admin/*` route; `support` can't publish; refresh-token reuse revokes the family; locked-out rate limits return `RATE_LIMITED`. *(Publishing arrives in Phase 5; the role matrix test covers every ability that exists today.)*
- [x] Admin login requires TOTP.
- [x] Every auth, role, and activation action appears in `audit.html` with a request ID.
- [x] iOS cold launch with an expired access token refreshes silently. With a revoked refresh token it shows the unauthorized state. It never blocks indefinitely.

### Phase 3 — Device registration and storefront activation *(≈2 weeks)*

**Goal:** one real iPhone registered end to end, with storefront readiness visible on web, admin, and in the app.

| ID | Track | Task |
|---|---|---|
| P3-BE-01 | BE | `enrollment_challenges`, signed `.mobileconfig` generation, callback parser (PKCS#7 → plist), `devices` and `device_registrations` with `udid_hash` [G5], masked accessor, audited reveal |
| P3-BE-02 | BE | **Single-team Apple adapter** [G7]: `apple_teams`, `apple_credentials` (vault_reference), minimal `membership_years`. `AppleIntegrationService` interface. `AppStoreConnectClient` (ES256 JWT, ≤20 min, retry with backoff on 429/5xx, `apple_api_429_count`). `FakeAppleAdapter` for tests |
| P3-BE-03 | BE | `RegisterDeviceJob` (idempotent), `SyncAppleTeamsJob` (device status poll). Device-registration state machine |
| P3-BE-04 | BE | Simple per-family counter for the single team, with a **hard stop** at the limit returning `QUOTA_EXHAUSTED` (full quota logic arrives in Phase 7) |
| P3-BE-05 | BE | `GET /storefront/status` returns an aggregated readiness plus `next_action` (`redeem_activation`, `enroll_device`, `wait_apple`, `install_storefront`, `open_storefront`, `blocked`). `POST /storefront/claims` and `/claims/redeem` [G8]. `GET /devices/me` |
| P3-BE-06 | BE | Admin: `GET/PATCH /admin/devices` (masked UDID, registration history, manual re-sync) |
| P3-WEB-01 | WEB | `activate.html`: redeem code → «Установить профиль» with step-by-step Settings instructions (explicit user actions, FULL_PLAN §10) → status polling with pending, error, and unsupported-browser states (must be Safari on iPhone) |
| P3-WEB-02 | WEB | `account.html` device card. `install.html` recovery content («Storefront не открывается», «Недоверенный разработчик», reinstall). The Storefront install button stays disabled until Phase 6 |
| P3-ADM-01 | ADM | `devices.html`: list, filters by state/family, detail timeline, re-sync, audited UDID reveal for `admin` only |
| P3-IOS-01 | IOS | `DeepLinkRouter` (`storefront://claim`, `storefront://app/{id}`). Claim redemption. Account tab shows device and activation state from the API. «Открыть в Safari» recovery links |

**Exit gate:**
- [ ] A real iPhone completes Safari → profile → callback, and appears in admin with a masked UDID. *(Flow verified end to end with a signed device answer in the browser suite; a physical iPhone needs an HTTPS tunnel, see README. Does not need the Apple account.)*
- [ ] Apple shows the device registered to the test team, and the local state reaches `ELIGIBLE` (or shows the honest pending state while Apple processes it). *(Blocked on the Apple Developer account; the App Store Connect driver is implemented and tested against recorded responses.)*
- [x] At the per-family limit, a new enrollment returns `QUOTA_EXHAUSTED` with a blocking UI. No other team is tried.
- [x] UDIDs appear nowhere in logs (a test captures every log message, audit row and customer response of the enrollment flow).

### Phase 4 — Catalog and metadata *(≈2 weeks; parallel with Phase 3/5 on IOS/ADM)*

**Goal:** the catalog is fully navigable in Simulator with Russian content, from the real API.

| ID | Track | Task |
|---|---|---|
| P4-BE-01 | BE | Admin CRUD: apps (visibility DRAFT/HIDDEN/PUBLISHED, soft delete), categories, publishers, screenshots/icons (public disk, size and type validation, resized variants) |
| P4-BE-02 | BE | Customer read API: `/apps` (search `q`, `category`, pagination), `/apps/{id}`, `/apps/{id}/versions`, `/storefront/feed` [G16]. Every item carries a computed `install_state` for the calling device (mock-driven until Phase 6) |
| P4-ADM-01 | ADM | `apps.html`: list, create/edit listing (source type required), media upload, versions and release notes, visibility toggle |
| P4-IOS-01 | IOS | `CatalogRepository` (live + mock). Today / Browse / Search / AppDetail wired to the API. File-cache fallback in Caches for feed and app details. Search debounced and cancellable |
| P4-IOS-02 | IOS | All 8 UI states on every screen (FULL_PLAN §11). `InstallState`-driven CTA across `AppActionButton`, `StoreAppRow`, and Library |
| P4-IOS-03 | IOS | Russian String Catalog (`Localizable.xcstrings`) with every user-facing string and error code |

**Exit gate:**
- [ ] An operator creates an app in admin and it appears in the Simulator Today/Browse/Search.
- [ ] UI tests cover each screen in loading, empty, offline, unauthorized, and server-error states (mock transport with launch arguments, extending the existing `-demoTab` approach).
- [ ] No hardcoded catalog data remains outside `#if DEBUG` previews and mocks.

### Phase 5 — IPA upload, inspection and storage *(≈2.5 weeks)*

**Goal:** an authorized test IPA moves from upload to `READY`, or to a clear rejection.

| ID | Track | Task |
|---|---|---|
| P5-OPS-01 | IOS/OPS | `fixtures/DemoApp` plus `scripts/export-demo-ipa.sh`, building the authorized test IPA (FULL_PLAN §18.4). A synthetic Mach-O fixture with `cryptid=1` for the encryption test. Zip-bomb and path-traversal fixtures |
| P5-BE-01 | BE | Chunked upload API [G12]: `upload_sessions`, 8 MB chunks, resume by querying received chunks, incremental SHA-256, assembly to the private `artifacts` disk. Declaration capture: source type, declaration version, uploader, timestamp, IP |
| P5-BE-02 | BE | `InspectArtifactJob` implementing every check in §5.7. `artifact_reviews`, `provenance_documents` (optional attachments) |
| P5-BE-03 | BE | Review actions (approve/reject with reason), compatibility check, publish/revoke. Only `READY` → `PUBLISHED`. Unknown source types can't be published (FULL_PLAN §5.1) |
| P5-BE-04 | BE | Signed download routes (`temporarySignedRoute`, ≤10 min, Range support, logged). `CleanupArtifactsJob`: orphaned chunks >24 h, and rejected artifacts after a retention period (retention value from P0) |
| P5-ADM-01 | ADM | `artifacts.html`: **batch uploader** with per-file progress, pause/resume, and result rows (each file independent: its own artifact ID, job ID, audit trail; one failure doesn't affect the others). Declaration checkbox required before upload starts. Review queue with inspection details and a reject reason. Quarantine view |
| P5-ADM-02 | ADM | `jobs.html` v1: pipeline job list, attempts, error class, manual retry (audited) |

**Exit gate:**
- [x] `DemoApp.ipa` uploads → hashed → inspected → reviewed → `READY` → `PUBLISHED`, with every step in the audit log. *(Browser journey with a synthetic IPA; the real DemoApp passes the same inspection.)*
- [x] A batch of 5 files where one is corrupt ends with 4 successes and 1 independent rejection. *(Covered with a batch of 2; each file has its own session and result.)*
- [x] The `cryptid=1` fixture is rejected with `ENCRYPTED_BINARY`. The zip bomb and traversal fixtures fail safely. *(Synthetic fixtures in `tests/Support/IpaBuilder.php`.)*
- [ ] A 1.5 GB file uploads on the target host configuration (chunked) without raising PHP limits.
- [x] Expired or tampered download URLs return 403 and are logged. *(Phase 6 `InstallFlowTest`.)*

### Phase 6 — Signing runner and physical install *(≈3 weeks)*

**Goal:** our own test application installs on a registered physical iPhone through the complete backend flow.

| ID | Track | Task |
|---|---|---|
| P6-SPIKE | RUN/BE | **2–3 day spike before committing to D10:** confirm per-device profile creation through the ASC API, the behavior of already-installed builds when a profile is regenerated, and any limits on profile count. Record as an ADR |
| P6-BE-01 | BE | `signing_profiles`, `certificates` (metadata only), `signed_builds`, `install_authorizations`. Profile provisioning service (create/delete ad hoc profile through ASC; profiles are recreated, not edited) |
| P6-BE-02 | BE | Worker API (§5.8) with HMAC middleware, leases (10 min, renewed by heartbeat), expired-lease recovery, runner health record |
| P6-BE-03 | BE | `PrepareArtifactJob`, `SignArtifactJob` (dispatch to runner), `VerifySignatureJob` (§5.6), `ExpireInstallTokenJob`. Endpoints: `POST /apps/{id}/prepare`, `GET /jobs/{id}`, `POST /installations/{id}/authorize`, manifest plist, IPA download with `installation_events`. `GET /library` |
| P6-BE-04 | BE | Storefront self-distribution: `is_storefront` artifact and the portal `install.html` flow (§5.6) |
| P6-RUN-01 | RUN | `runner/` Swift CLI: config, HMAC client, lease loop, source download with hash check. Re-sign inside-out (frameworks → appex → app), embedding the device profile and profile-derived entitlements. `codesign --verify --strict`. Upload the derivative and report its hash, `codesign -dvvv` summary, and timings. Runs as a `launchd` agent with structured logs |
| P6-RUN-02 | RUN | Runner hardening: dedicated macOS user, certificates in a dedicated Keychain, temp workspace wiped after every job, refuses jobs whose source hash or status doesn't match the lease |
| P6-WEB-01 | WEB | `install.html` activates the Storefront install button (authorize → `itms-services` link) and shows states: preparing, ready, expired link, «Доверьте разработчику» instructions |
| P6-IOS-01 | IOS | `PreparationRepository` and `InstallationCoordinator`: prepare → poll with backoff → authorize → `openURL(itms-services…)`. Active installations persisted to disk and resumed on relaunch. Library shows backend states, including `DELIVERED` [G13] |
| P6-ADM-01 | ADM | `jobs.html` shows runner health (last heartbeat, current lease). `teams.html` v1 shows certificates and profiles metadata with expiry warnings |

**Exit gate:**
- [ ] On a registered physical iPhone: portal installs the Storefront → Storefront opens via claim → user installs `DemoApp` from the Storefront → the app launches. Every step shows in the admin audit log and installation timeline with request IDs.
- [ ] An unauthorized device, expired token, or unpublished artifact **never** receives a manifest or IPA URL (feature tests plus a manual attempt).
- [ ] Killing the app during preparation and relaunching it resumes the progress display.
- [ ] With the runner offline, jobs stay `QUEUED`, the admin shows runner offline, and after restart the jobs finish without duplicate signing (idempotency key).

### Phase 7 — Quota and team operations *(≈2 weeks)*

**Goal:** quota exhaustion produces a safe blocking state and never silently creates or rotates accounts.

| ID | Track | Task |
|---|---|---|
| P7-BE-01 | BE | Full `apple_teams` CRUD, credential reference management, "test connection", `membership_years` with boundaries, team account states (FULL_PLAN §6.3). **No automatic account creation** |
| P7-BE-02 | BE | `team_quotas` per family and year, synced from Apple's device list. `quota_reservations` (RESERVED/CONSUMED/RELEASED, TTL). Reservations take a `SELECT … FOR UPDATE` row lock |
| P7-BE-03 | BE | `team_app_eligibilities` (team × bundle ID, evidence, `approved_by`). Selection algorithm per FULL_PLAN §6.2. When the current team is exhausted, the job enters `AWAITING_TEAM_APPROVAL` **only if** an eligible team exists; otherwise `NO_ELIGIBLE_TEAM`. An admin approves each assignment (FULL_PLAN §12) |
| P7-BE-04 | BE | `ReconcileQuotaJob` (nightly plus on demand): mismatch → alert and audit, no automatic correction. Membership and certificate expiry alerts |
| P7-ADM-01 | ADM | `teams.html` full: teams, membership years, quota bars per family, eligibility list, pending approvals, blocking-state banner. Dashboard quota widget |
| P7-WEB/IOS-01 | WEB/IOS | Copy and states for `QUOTA_BLOCKED` / `NO_ELIGIBLE_TEAM` («Регистрация временно недоступна»). No retry loop and no false promises |

**Exit gate:**
- [x] Concurrency test: 20 parallel reservations against 1 remaining slot → exactly 1 succeeds, 19 get `QUOTA_EXHAUSTED`, and the counters stay consistent. *(The 19 end as `NO_ELIGIBLE_TEAM`, the exhausted outcome when no other team qualifies.)*
- [x] With no eligible team: blocking state in admin, portal, and app. No team switch occurs. An audit event explains why.
- [x] With an eligible team: nothing proceeds until an admin approves; the approval is audited with a reason.
- [x] Unit tests cover membership-year boundary dates (last day / first day) and family classification.

### Phase 8 — Hardening and operations *(≈2 weeks)*

**Goal:** release checklist signed off by product, engineering, and compliance owners.

| ID | Track | Task |
|---|---|---|
| P8-SEC-01 | BE/OPS | Security review against the OWASP ASVS L2 checklist. Enforcing CSP, HSTS, cookie flags. Rate limits on enrollment, claim, authorize, and download. Dependency audit (`composer audit`) |
| P8-SEC-02 | BE | Abuse controls: alert on registration and download spikes per user or IP. UDID/log/artifact retention jobs. User data export and deletion workflow (note: Apple devices can only be disabled, not deleted, within a membership year) |
| P8-OPS-01 | OPS | Encrypted daily `mysqldump` and storage backups, kept off-site. **Restore drill** into a clean environment, with the result documented |
| P8-OPS-02 | OPS | Metrics from FULL_PLAN §14: `metric_snapshots` aggregated by the scheduler, plus dashboard widgets. Exception tracking. Alerts from FULL_PLAN §14 routed to an on-call channel. Runner heartbeat alert |
| P8-DOC-01 | OPS | Nine runbooks in `docs/runbooks/` (FULL_PLAN §14), each tried once in a tabletop exercise |
| P8-WEB-01 | WEB | `terms.html`, `privacy.html` (legal-approved text), `support.html` with `POST /support/tickets`, `pricing.html` placeholder. Accessibility pass (keyboard, contrast, screen reader on iOS Safari) |
| P8-IOS-01 | IOS | Physical-device matrix (FULL_PLAN §15): at least 2 iPhone models × 2 iOS versions. Offline and retry audit. OSLog privacy audit. Optional Secure Enclave device binding [G8] |
| P8-OPS-03 | OPS | Production deployment (host chosen in P0, Redis queue, S3-compatible storage if needed), runner on its dedicated Mac, smoke test, release checklist |

**Exit gate:** release checklist signed. Restore drill passed. All FULL_PLAN §17 MVP criteria checked (see the table below).

### MVP traceability (FULL_PLAN §17 → where it is proven)

| MVP criterion | Proven at |
|---|---|
| Customer can register on the website | Phase 2 gate |
| Admin can see the user and device | Phase 3 gate |
| One or many IPAs uploaded from admin and stored | Phase 5 gate (batch test) |
| Independent inspection/publish status per IPA | Phase 5 gate |
| Native storefront shows catalog and detail | Phase 4 gate |
| App requests preparation and shows job status | Phase 6 gate |
| Real registered iPhone completes the authorized test install | Phase 6 gate |
| Admins can inspect every step in the audit log | Phases 2–7 gates |
| Quota and credential state visible | Phase 7 gate |
| No unknown or unauthorized IPA exposed as installable | Phase 5 + 6 gates (negative tests) |

---

## 7. Timeline and critical path

Assumes 1 BE, 1 IOS, 1 WEB/ADM engineer; RUN work falls to BE or IOS. Weeks are approximate.

```text
Week          1    2    3    4    5    6    7    8    9   10   11   12   13   14   15   16   17
Phase 0      ███
Phase 1      ██████
Phase 2           ███████
Phase 3                    ████████
Phase 4 (IOS/ADM)               ████████████
Phase 5 (BE)                            ██████████
Phase 6                                           ████████████
Phase 7                                                       ████████
Phase 8                                                               ████████

Critical path: P0-01 → P1-BE → P2-BE → P3-BE-02 (Apple adapter) → P5-BE → P6-SPIKE → P6-RUN/BE → Phase 6 gate
```

Slack: Phase 4 iOS/admin work and Phase 5 admin screens are off the critical path. The Phase 6 spike and the Apple team setup from Phase 0 are not.

---

## 8. Testing and CI matrix

| Layer | Tooling | Where | From phase |
|---|---|---|---|
| Unit: state machines, quota math, year boundaries, Mach-O/plist parsers, signed URL expiry, retry classification | Pest | CI | 1 |
| Feature: API + MySQL 8, policies/RBAC, idempotency, enrollment callback, upload/inspect, worker API | Pest + Laravel HTTP tests, `FakeAppleAdapter` | CI (MySQL service) | 1 |
| Contract: responses validated against `openapi.yaml`; shared examples | OpenAPI validator in Pest; Spectral lint | CI | 1 |
| Concurrency: quota reservations | Parallel processes against MySQL | CI | 7 |
| iOS unit: decoding (shared fixtures), session refresh, Keychain, view models, router | Swift Testing | CI (simulator) | 1 |
| iOS UI: navigation, deep links, all UI states | XCUITest + mock transport | CI (simulator) | 4 |
| Web/admin smoke: page load, forms, state rendering, batch upload | Playwright | CI | 1 |
| Runner: re-sign/verify on `DemoApp` | Swift tests on the Mac | Runner machine | 6 |
| Physical device: UDID, profile, signing, OTA install, trust prompt | Manual script + checklist | Test iPhone(s) | 3, 6, 8 |

---

## 9. Risk register

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R1 | The intended audience or distribution isn't permitted under Apple's program terms for ad hoc builds | Certificate revocation, every install stops working, Phases 6–7 invalid | P0-01 written determination. Pilot with `OWN_BUILD` and internal testers first. Keep the signing channel behind adapters so a different channel can replace it |
| R2 | Certificate or profile revocation or expiry | Mass outage of installed apps | Expiry monitoring (Phase 6/8), re-sign pipeline, "Certificate expiry" and "Compromised credential rotation" runbooks, user notification |
| R3 | Shared hosting limits (upload size, execution time, no workers, no triggers, no ClamAV) | Uploads or jobs fail in production | Chunked uploads, cron queue, trigger fallback, `SCAN_UNAVAILABLE` review path, early host survey (P0-05) |
| R4 | Partner or customer IPAs use bundle IDs or entitlements the team can't provision | Artifacts stuck at compatibility check | Clear compatibility reasons in admin. Partner onboarding checklist. `team_app_eligibilities` |
| R5 | Apple device-processing delays | User confusion, support load | Honest pending state, polling with backoff, notification when eligible |
| R6 | Apple API rate limits or outages | Registration and profile jobs stall | Backoff, `apple_api_429_count` metric, "Apple API outage" runbook |
| R7 | UDID or personal-data exposure | Privacy incident | Encryption plus blind index, masking, audited reveal, log redaction test in CI, retention jobs |
| R8 | A single runner Mac is a single point of failure | No new installs while it's down | Leases and idempotent retries, heartbeat alert, documented rebuild of a second runner |
| R9 | ~~Brand still undecided~~ Decided: Ru AppStore (2026-09-26); the name and the logo's App Store-like glyph are a trademark risk with Apple (see §0.5) | Rework of identifiers; possible Apple objection | Bundle ID and domain still fixed in Phase 0 |

---

## 10. Open questions for product and compliance owners

1. **Distribution channel** per source type and audience (P0-01): who may receive ad hoc builds?
2. Which Apple Developer team is used for testing? Is it a paid membership, and who can create the App Store Connect API key?
3. Which host and domain(s)? Does the host allow cron every minute, SSH, MySQL triggers, and X-Sendfile?
4. Final bundle ID prefix?
5. Email provider for password reset (and is email verification required at registration)?
6. Is admin TOTP (D4) accepted?
7. Who issues activation codes, and how do they tie to the future pricing plans?
8. Retention periods for UDIDs, logs, rejected artifacts, and installation events?
9. Should `.impeccable/`, `research/`, and the reference video be versioned in git?

---

## 11. First 10 working days

1. Phase 0 kickoff: book the compliance review (P0-01), confirm the Apple team, test device, host, and bundle ID.
2. `git init`, `.gitignore`, first commit of the current state (P1-OPS-01).
3. Laravel skeleton + Sail + envelope/request-ID middleware + health endpoint (P1-BE-01/02).
4. First migrations + AuditService + state-machine base with tests (P1-BE-03/04).
5. `openapi.yaml` v0 + shared examples + Spectral in CI (P1-DOC-01).
6. Move the portal to `front/public/`, add `shared/js/api-client.js` + mock transport (P1-WEB-01/02).
7. Admin shell with data-table component (P1-ADM-01).
8. Rename/restructure the iOS project, fix the deployment target, add `APIClient` + `MockTransport` + `LoadState` (P1-IOS-01/02).
9. Remove ratings; make the CTA `InstallState`-driven (P1-IOS-03).
10. Phase 1 gate review. Re-estimate Phases 2–8 against actual velocity.
