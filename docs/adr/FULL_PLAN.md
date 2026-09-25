# Native iOS Storefront + Web Portal + PHP Backend — Full Implementation Plan

> **Version:** 1.0  
> **Status:** Implementation source of truth  
> **Content language:** Russian (branding placeholder until final brand is selected)  
> **Frontend:** semantic HTML, CSS, vanilla JavaScript  
> **Backend:** PHP 8.2+ / Laravel (recommended)  
> **Database:** MySQL 8  
> **Storage:** hosting storage initially; S3-compatible object storage when volume requires it  
> **iOS:** native SwiftUI application  

This document consolidates the product and implementation plan for three connected products:

1. A public web portal for customers to create an account, register a device and install the native storefront.
2. A native iOS storefront that behaves like an App Store-style catalog and starts authorized app installation workflows.
3. An admin panel for operators to manage users, devices, applications, IPA artifacts, Apple teams, jobs and audit events.

The existing [APPSTORE.md](APPSTORE.md), [APP_PLAN.md](APP_PLAN.md), [PLAN.md](PLAN.md), and ADR documents remain useful references. This document is the execution-level plan and supersedes their technology choices where they conflict with the requested stack.

---

## 1. Product boundary and non-negotiable rules

### 1.1 What we are building

```text
Customer website
  → account and activation
  → device registration / UDID binding
  → native storefront installation

Native storefront
  → catalog
  → app detail
  → authorized IPA preparation
  → installation handoff
  → library and update status

Admin panel
  → users and devices
  → app catalog and artifacts
  → Apple team credentials and quota visibility
  → job monitoring
  → audit and support
```

### 1.2 Compliance boundary

The product is an **artifact hosting and distribution system**. The customer/client is responsible for supplying the IPA files and for confirming that they have the right to use and distribute them. The system does not search the internet for IPAs or promise to acquire third-party applications.

The customer may upload one IPA or a batch of IPAs from the admin panel. The backend stores those files on the configured hosting/object storage and exposes them to the catalog workflow. How the customer obtained each IPA is outside the product's acquisition scope; the admin workflow must nevertheless record an uploader, source declaration and acceptance of responsibility.

The supported artifact sources are:

- our own applications;
- developer/partner-provided builds;
- open-source builds whose license permits redistribution;
- Apple Alternative Marketplace packages when Apple and the app developer have enabled the relationship;
- user-imported artifacts where the user confirms the right to use them.

The system must not download, decrypt, re-sign or redistribute FairPlay-protected App Store binaries without authorization. An Apple Developer account does not grant rights to third-party apps.

### 1.3 Apple team quota rule

The product must not use account rotation to evade Apple limits, fraud detection or device restrictions. A quota manager may select another **legitimately controlled and eligible developer team** only when:

- the team belongs to the same approved business/organization or has a documented partner agreement;
- the application and signing rights are valid for that team;
- the device/application assignment is allowed by Apple’s program rules;
- the selection is recorded in an immutable audit trail.

The system must never promise that a new account resets or bypasses a quota. `100 devices` is tracked per product family and membership year, not as a universal pool that can be hidden by switching accounts.

---

## 2. Recommended architecture

```text
                         ┌──────────────────────┐
                         │ Customer Web Portal   │
                         │ HTML + CSS + JS       │
                         └──────────┬───────────┘
                                    │ HTTPS/JSON
                         ┌──────────▼───────────┐
                         │ PHP API / Laravel     │
                         │ Auth • Domain • Jobs  │
                         └──────┬───────┬────────┘
                                │       │
                     ┌──────────▼─┐ ┌──▼─────────────┐
                     │ MySQL 8    │ │ Artifact store │
                     │ source data│ │ IPA/manifest   │
                     └────────────┘ └──────┬─────────┘
                                            │
                         ┌──────────────────▼───────┐
                         │ Admin Panel               │
                         │ HTML + CSS + vanilla JS  │
                         └──────────────────────────┘

                         ┌──────────────────────────┐
                         │ macOS signing runner     │
                         │ isolated, controlled     │
                         └──────────┬───────────────┘
                                    │ signed artifact
                         ┌──────────▼───────────────┐
                         │ Native SwiftUI Storefront│
                         └──────────────────────────┘
```

### 2.1 Why a macOS runner is required

PHP hosting can manage metadata, uploads, accounts and jobs, but it should not be expected to perform Apple code signing. The backend sends an authorized job to an isolated macOS runner. The runner signs/validates only artifacts and profiles it is allowed to handle, then uploads the result back to storage.

### 2.2 Hosting deployment modes

**MVP/shared hosting:** Laravel API, MySQL, cron-based queue worker, local/private storage, admin panel and public portal on one hosting account. Signing is disabled or delegated to a manually operated macOS machine.

**Production:** PHP API on VPS or managed hosting, MySQL with backups, Redis or database queue, private object storage, and a separate macOS signing runner connected through a restricted worker API/VPN.

---

## 3. Repository and folder structure

```text
front/
  public/                    # customer website: HTML/CSS/JS
    index.html
    account.html
    activate.html
    install.html
    support.html
    assets/
    css/
    js/
  README.md

ios/
  Storefront.xcodeproj/
  Storefront/
    App/
    Features/
      Today/
      Browse/
      Search/
      Library/
      Account/
      AppDetail/
      Preparation/
    Core/
      Networking/
      Auth/
      Keychain/
      Routing/
      Models/
    Resources/
  Tests/

backend/
  app/
    Http/Controllers/
    Http/Middleware/
    Models/
    Policies/
    Services/
      Apple/
      Catalog/
      Devices/
      Artifacts/
      Quotas/
      Installations/
    Jobs/
    Console/Commands/
  database/migrations/
  routes/api.php
  routes/web.php
  resources/views/admin/       # only if server-rendered fallback is needed
  storage/app/private/
  tests/Feature/
  tests/Unit/

admin/
  public/                      # operator panel: HTML/CSS/JS
    index.html
    login.html
    users.html
    devices.html
    apps.html
    artifacts.html
    teams.html
    jobs.html
    audit.html
    assets/
    css/
    js/

docs/
  api/openapi.yaml
  runbooks/
  adr/
```

---

## 4. Product surfaces

### 4.1 Customer web portal

The public website is not the main app catalog. Its responsibilities are:

- landing and product explanation;
- account registration/login/password reset;
- plan/subscription placeholder (payment later);
- activation code entry;
- device registration and UDID status;
- native storefront installation instructions;
- recovery when the native app is missing or expired;
- support, terms, privacy and troubleshooting.

Content is Russian for the first release. Brand name and logo remain placeholders.

### 4.2 Native iOS storefront

The native app is the App Store-style product. It contains:

- `Today`: featured and recently updated apps;
- `Apps`: categories and horizontal carousels;
- `Search`: title, publisher and category search;
- `Library`: installed, pending, update and failed states;
- `Account`: device, activation, support, activity and settings;
- app detail, compatibility, release notes and install CTA;
- preparation/install progress driven by backend status;
- universal/deep links back to the web recovery portal.

The storefront must never imply that an app is installable unless the backend says its artifact is authorized, valid and compatible.

### 4.3 Admin panel

Operator-only HTML/CSS/JS application with role-based access:

- dashboard and health indicators;
- users, sessions and activation codes;
- devices, UDIDs and registration history;
- apps, categories, publishers and visibility;
- IPA upload/import, provenance and validation result;
- builds, versions and release notes;
- Apple teams, membership years and quota usage;
- signing profiles/certificates metadata (never private keys);
- queue jobs, retries, failures and manual retry controls;
- installation events and support timeline;
- audit logs and exportable reports.

---

## 5. Application source and IPA lifecycle

### 5.1 Application source types

Every catalog item must declare one source type:

```text
OWN_BUILD
PARTNER_BUILD
OPEN_SOURCE_BUILD
ALTERNATIVE_MARKETPLACE_PACKAGE
USER_IMPORT
CUSTOMER_PROVIDED
```

Unknown or unverifiable source types cannot become publicly installable.

### 5.1.1 Customer-provided IPA ingestion (explicit product requirement)

The client can manage the catalog contents directly from the admin panel:

```text
Admin login
→ select one IPA or multiple IPA files
→ upload to private hosting storage
→ create/update app records
→ queue technical inspection
→ optionally publish to storefront
```

The backend does not act as an IPA marketplace or acquisition service. It does not crawl IPA websites, purchase App Store downloads, decrypt FairPlay files or determine where the client obtained an IPA. The customer must confirm during upload that the artifact is theirs to use/distribute.

The system still performs technical safeguards needed for reliable operation:

- file type, size and upload integrity checks;
- SHA-256 hashing and duplicate detection;
- bundle/version/architecture extraction;
- nested extension and entitlement inspection;
- malware/quarantine scanning where available;
- compatibility and signing validation before installability;
- private storage, signed short-lived download URLs and audit logging.

Technical inspection is not a legal provenance certification. An artifact can be accepted for storage but remain `QUARANTINED` or `NOT_PUBLISHED` until an administrator approves it.

### 5.2 Artifact lifecycle

```text
UPLOADED
→ HASHING
→ INSPECTING
→ PROVENANCE_REVIEW
→ COMPATIBILITY_CHECK
→ SIGNING_PENDING
→ SIGNED
→ SIGNATURE_VERIFIED
→ READY
→ PUBLISHED
```

Failure states:

```text
REJECTED
INSPECTION_FAILED
PROVENANCE_FAILED
SIGNING_FAILED
VALIDATION_FAILED
QUARANTINED
EXPIRED
REVOKED
```

### 5.3 IPA inspection

The backend/runner records:

- SHA-256 hash and file size;
- bundle identifier and version/build number;
- minimum iOS version and supported architectures;
- main app and nested extensions;
- entitlements and provisioning profile metadata;
- certificate/team identifier;
- privacy/support metadata supplied by publisher;
- malware/quarantine scan result;
- source, uploader, authorization evidence and review status.

The original artifact is immutable. A derived signed artifact gets a new hash and a parent reference.

### 5.4 Installation authorization

The backend issues a short-lived, device-bound authorization only when:

- the user session is valid;
- device registration is eligible;
- artifact status is `READY`;
- the device/application compatibility check passes;
- the app has not been revoked or expired.

Long-lived public IPA URLs are prohibited. Download URLs are signed, expire quickly and are logged.

---

## 6. Apple Developer account and quota management

### 6.1 Data model

```text
apple_teams
  id, apple_team_id, name, organization_id, status, membership_expires_at

apple_credentials
  id, team_id, issuer_id, key_id, vault_reference, status

membership_years
  id, team_id, starts_at, ends_at, status

team_quotas
  id, team_id, membership_year_id, device_family,
  limit_count, reserved_count, registered_count, remaining_count

team_assignments
  id, team_id, app_id, device_id, reason, approved_by, audit_event_id
```

Private keys and API secrets are stored outside MySQL in a vault or encrypted secret store. MySQL contains only references and non-sensitive metadata.

### 6.2 Quota selection algorithm

The system must not blindly switch accounts when a counter reaches 100. It should:

1. determine device family and current membership year;
2. read Apple-synced quota state;
3. reserve quota transactionally;
4. select only an eligible team with a valid app/team relationship;
5. create an audit event explaining the selection;
6. release reservation if the Apple operation fails;
7. reconcile local counters against Apple state periodically.

If no eligible team exists, return `NO_ELIGIBLE_TEAM`; do not silently switch, retry forever or create a new account.

### 6.3 Account states

```text
PENDING_VERIFICATION
ACTIVE
EXPIRING
SUSPENDED
REVOKED
DISCONNECTED
```

Account onboarding, legal ownership and Apple credentials are admin-only workflows. Automatic account creation is out of scope.

---

## 7. MySQL data model

Core tables:

```text
users
roles
user_roles
sessions
activation_codes
subscriptions
devices
device_registrations
apple_teams
apple_credentials
membership_years
team_quotas
apps
app_categories
app_publishers
app_versions
app_artifacts
artifact_reviews
provenance_documents
signing_profiles
certificates
jobs
job_attempts
installations
installation_events
notifications
audit_logs
support_tickets
```

Important constraints:

- unique `(apple_team_id, device_udid, membership_year_id)`;
- unique `(app_id, version, build_number)`;
- unique artifact hash;
- unique activation code hash;
- idempotency key on every mutating job;
- foreign keys for ownership and lifecycle parents;
- soft-delete only for user-facing catalog records;
- immutable artifact and audit records.

UDIDs are sensitive identifiers. Encrypt at rest where possible, redact from logs, and show only masked values in the admin UI.

---

## 8. PHP backend modules

### 8.1 Framework choice

Laravel is preferred because it provides routing, validation, authentication, policies, queues, migrations, scheduling, storage abstraction and testing. A custom PHP application is possible but increases security and maintenance risk.

Required baseline:

- PHP 8.2 or later;
- Laravel current supported release;
- MySQL 8;
- Composer;
- HTTPS;
- cron for scheduler;
- database queue for shared hosting, Redis queue for production;
- PHPUnit/Pest and Laravel HTTP tests.

### 8.2 Service boundaries

```text
AuthService
UserService
ActivationService
DeviceService
AppleTeamService
AppleIntegrationService
QuotaService
CatalogService
ArtifactService
InspectionService
SigningJobService
InstallationService
NotificationService
AuditService
```

Apple API calls and signing calls must stay behind adapters so a provider failure does not leak Apple-specific response structures into the frontend.

### 8.3 Queue jobs

```text
SyncAppleTeamsJob
RegisterDeviceJob
ReconcileQuotaJob
InspectArtifactJob
ReviewArtifactJob
PrepareArtifactJob
SignArtifactJob
VerifySignatureJob
PublishArtifactJob
ExpireInstallTokenJob
CleanupArtifactsJob
SendNotificationJob
```

Every job includes: `job_id`, `idempotency_key`, `attempt`, `actor`, `correlation_id`, `started_at`, `finished_at`, `result_code` and `error_class`.

---

## 9. API contract

All endpoints are versioned under `/api/v1` and return a consistent envelope:

```json
{
  "data": {},
  "meta": {"request_id": "..."},
  "error": null
}
```

### Customer endpoints

```text
POST   /auth/register
POST   /auth/login
POST   /auth/logout
POST   /activation/redeem
POST   /devices/register
GET    /devices/me
GET    /storefront/status
GET    /apps
GET    /apps/{id}
GET    /apps/{id}/versions
POST   /apps/{id}/prepare
GET    /jobs/{id}
POST   /installations/{id}/authorize
GET    /library
GET    /notifications
POST   /support/tickets
```

### Admin endpoints

```text
GET/POST/PATCH /admin/users
GET/PATCH       /admin/devices
GET/POST/PATCH  /admin/apps
POST            /admin/apps/{id}/artifacts
POST            /admin/artifacts/{id}/inspect
POST            /admin/artifacts/{id}/publish
GET/POST/PATCH  /admin/apple-teams
POST            /admin/apple-teams/{id}/sync
GET             /admin/quotas
GET             /admin/jobs
POST            /admin/jobs/{id}/retry
GET             /admin/audit-logs
```

OpenAPI must be committed under `docs/api/openapi.yaml` and used to keep the native app, website and admin panel aligned.

---

## 10. Website implementation plan (HTML/CSS/JS)

### Pages

```text
/index.html          Landing
/pricing.html        Plans (payment placeholder)
/login.html          Login
/register.html       Registration
/activate.html       Activation and device setup
/install.html        Storefront installation
/account.html        Account/device status
/support.html        Support and troubleshooting
/terms.html
/privacy.html
```

### Technical rules

- semantic HTML and accessible form controls;
- CSS variables for colors, spacing and typography;
- mobile-first layout;
- no framework dependency for the demo;
- `fetch()` API client in a single module;
- central auth/session handling;
- visible loading, error, empty and success states;
- no secrets in JavaScript bundles;
- CSP, HTTPS and secure cookies in production;
- Russian copy with a translation-ready dictionary structure.

### Activation flow

```text
Create account
→ enter activation code
→ open Safari instruction
→ install configuration profile (where applicable)
→ submit/confirm device identifier
→ wait for registration status
→ download storefront installation package
→ open native app
```

The website must clearly explain that a profile or app installation may require explicit iOS user actions and cannot be silently performed by JavaScript.

---

## 11. Native iOS implementation plan

### Technology

- SwiftUI;
- URLSession + Codable;
- Keychain for tokens and device binding secrets;
- async/await;
- structured concurrency;
- OSLog with sensitive values redacted;
- minimum iOS version selected after checking required APIs;
- no private APIs;
- backend-driven state machine.

### Modules

```text
AppShell
SessionStore
APIClient
CatalogRepository
DeviceRepository
PreparationRepository
InstallationCoordinator
KeychainStore
DeepLinkRouter
FeatureFlags
```

### UI states

Every screen must implement:

- loading;
- loaded;
- empty;
- offline;
- unauthorized;
- expired/renewal required;
- server error;
- retry.

The install CTA is derived from backend state, not from local assumptions.

### Native acceptance criteria

- cold launch validates session without blocking the UI indefinitely;
- catalog renders from API data and cached fallback;
- search and horizontal lists remain responsive;
- preparation progress survives app relaunch;
- invalid authorization never exposes a download URL;
- all installation handoffs are logged with request ID;
- app can recover to Safari when activation is invalid.

---

## 12. Admin panel implementation plan (HTML/CSS/JS)

### Dashboard widgets

- active users and devices;
- registration success/failure rate;
- ready/pending/revoked artifacts;
- team quota by product family;
- certificate/profile expiry warnings;
- queue depth and failed jobs;
- recent audit events;
- storage usage.

### Critical admin workflows

#### Add an application

```text
Create listing
→ select source type
→ upload one or many IPA files
→ accept customer responsibility declaration
→ store files on hosting
→ inspect each artifact
→ review
→ compatibility check
→ publish or reject
```

The batch uploader must show per-file progress and result rows. One failed IPA must not roll back successful uploads from the same batch; each file gets its own artifact ID, job ID and audit trail.

#### Add an Apple team

```text
Create team record
→ enter non-secret metadata
→ store API key in vault
→ test connection
→ sync membership/quota
→ activate team
```

#### Handle quota exhaustion

```text
Quota warning
→ check eligible teams
→ approve legitimate assignment
→ reserve quota
→ continue job
```

If there is no eligible team, the UI must show a blocking state, not an automatic account-creation or evasion action.

---

## 13. Security and privacy

- HTTPS everywhere;
- Argon2id password hashing;
- CSRF protection for browser sessions;
- HttpOnly/SameSite cookies;
- short-lived access tokens and refresh rotation;
- RBAC and object-level authorization;
- rate limits on authentication, UDID and download endpoints;
- malware scan and quarantine for uploads;
- private artifact storage with signed URLs;
- encryption for sensitive identifiers and credentials;
- secret values never stored in MySQL or logs;
- immutable audit events for team, artifact, device and install actions;
- backup encryption and restore drills;
- deletion/export workflows for user data;
- retention policy for UDIDs, logs and artifacts.

---

## 14. Observability and operations

### Metrics

```text
api_request_duration
api_error_rate
device_registration_success_rate
apple_api_429_count
quota_remaining_by_team_family
artifact_inspection_failures
signing_success_rate
install_authorization_success_rate
queue_depth
artifact_storage_bytes
```

### Alerts

- Apple credentials invalid or expiring;
- certificate/profile below warning threshold;
- quota below configured threshold;
- repeated signature failures;
- queue backlog above threshold;
- storage capacity risk;
- unusual registration or download spikes;
- failed backup verification.

### Runbooks

```text
Apple API outage
Quota reconciliation mismatch
Signing runner offline
Artifact quarantine
Certificate expiry
Database restore
Object storage restore
Compromised credential rotation
User device recovery
```

---

## 15. Testing strategy

### Unit tests

- quota calculation and membership-year boundaries;
- state transitions;
- authorization policies;
- artifact metadata parser;
- signed URL expiry;
- retry classification;
- input validation.

### Integration tests

- PHP API + MySQL migrations;
- queue dispatch and idempotency;
- fake Apple adapter;
- artifact upload/inspection;
- admin role boundaries;
- device registration callback;
- quota reservation under concurrent requests.

### iOS tests

- API decoding;
- session refresh;
- Keychain storage;
- navigation and deep links;
- catalog/search view models;
- preparation status rendering;
- offline/retry states.

### Physical-device tests

Simulator is sufficient for UI and mock backend flow. A real registered iPhone is required for:

- UDID collection;
- provisioning/signing;
- Developer Mode;
- profile installation;
- real IPA install handoff;
- device-specific entitlement and push behavior.

Apple notes that simulator execution does not reproduce all physical-device features; final verification must include physical devices. [Apple simulated and physical devices](https://developer.apple.com/documentation/Xcode/running-your-app-on-simulated-or-physical-devices)

---

## 16. Implementation phases and acceptance gates

### Phase 0 — Product and compliance lock

- approve source types and legal ownership workflow;
- confirm one Apple Developer account for initial testing;
- identify first physical test device;
- finalize Russian content and placeholder brand;
- approve API and database naming.

**Gate:** no unresolved decision about what is installable.

### Phase 1 — Repository and local environment

- create `front`, `ios`, `backend`, `admin` structure;
- Laravel + MySQL setup;
- static website/admin shells;
- SwiftUI project and API environment config;
- local `.env.example`, migrations and seed data.

**Gate:** website, admin, API and iOS app run locally.

### Phase 2 — Authentication and accounts

- registration/login/logout;
- roles and admin guard;
- activation code model;
- secure session and Keychain storage;
- password reset and audit events.

**Gate:** customer and admin can authenticate with separate permissions.

### Phase 3 — Device registration and storefront activation

- device flow on web;
- registration states and callbacks;
- admin device list;
- storefront session validation;
- recovery/deep-link pages.

**Gate:** one real iPhone can be registered and storefront readiness is visible end-to-end.

### Phase 4 — Catalog and metadata

- app listing CRUD;
- categories/publishers/screenshots;
- native Today/Apps/Search/Detail screens;
- admin catalog workflow;
- mock install states.

**Gate:** catalog is fully navigable on simulator with Russian content.

### Phase 5 — IPA upload, inspection and storage

- private upload endpoint;
- hash and metadata extraction;
- provenance review;
- quarantine state;
- storage cleanup and signed download URLs;
- admin artifact screens.

**Gate:** an authorized test IPA moves from upload to `READY` or a clear rejection.

### Phase 6 — Signing runner and physical install

- macOS runner contract;
- job dispatch and status polling;
- profile/certificate metadata;
- signature validation;
- device-bound installation authorization;
- physical iPhone installation test.

**Gate:** our own test application installs on a registered physical iPhone through the complete backend flow.

### Phase 7 — Quota and team operations

- Apple team records;
- credential references;
- membership years;
- product-family quota sync;
- transactional reservations;
- eligible-team selection with audit;
- expiry/reconciliation alerts.

**Gate:** quota exhaustion produces a safe blocking state and never silently creates or rotates accounts.

### Phase 8 — Hardening and operations

- security review;
- backup/restore test;
- rate limits and abuse controls;
- observability and alerts;
- runbooks;
- privacy/terms pages;
- production deployment.

**Gate:** release checklist signed by product, engineering and compliance owners.

---

## 17. MVP definition

The first usable release is complete when:

- a customer can register on the website;
- an admin can see the user and device;
- one or many customer-provided IPAs can be uploaded from Admin and stored on hosting;
- each uploaded IPA receives an independent inspection/publish status;
- the native storefront displays the catalog and app detail;
- the app can request preparation and show job status;
- a real registered iPhone can complete the authorized test installation;
- admins can inspect every step in the audit log;
- quota and credential state are visible;
- no unknown or unauthorized IPA is exposed as installable.

Payment, large public catalog, third-party publisher onboarding and automatic production scale-up are post-MVP.

---

## 18. Immediate next tasks

1. Create `backend/` Laravel skeleton and MySQL migrations.
2. Create static `front/` and `admin/` shells with shared API client patterns.
3. Add `apps`, `app_versions`, `app_artifacts`, `devices`, `jobs` and `audit_logs` migrations.
4. Create a locally generated authorized demo IPA from a simple Swift test app.
5. Implement upload → inspect → ready flow with fake signing status.
6. Connect the native storefront to catalog and job endpoints.
7. Test all UI states in Simulator.
8. Test real installation on one registered physical iPhone.
9. Add Apple team/quota integration only after the single-team flow is stable.
