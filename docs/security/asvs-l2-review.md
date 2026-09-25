# Security review — OWASP ASVS 4.0 Level 2 (condensed)

**Date:** 2026-09-25 · **Scope:** backend API, worker API, portal, admin panel, iOS app, signing runner ·
**Method:** code review against the ASVS L2 chapters that apply, plus the automated tests named below.
This is an engineering self-review (IMPLEMENTATION_PLAN P8-SEC-01); an independent review is still
recommended before launch (release checklist).

Legend: ✅ meets L2 · ⚠️ partial / accepted risk · ⛔ open

| ASVS | Requirement area | Status | Evidence |
|---|---|---|---|
| V1 | Architecture, threat model | ⚠️ | IMPLEMENTATION_PLAN §2, §5, §9 cover trust boundaries (browser, app, runner, Apple). No separate threat-model document. |
| V2.1 | Password security | ✅ | Argon2id (`config/hashing.php`), min length, breached-password check not implemented ⚠️ |
| V2.2 | Anti-automation on auth | ✅ | Rate limits: login 5/min per IP+email, register, forgot/reset, admin login/TOTP (`AppServiceProvider`), tests in `tests/Feature/Auth` |
| V2.8 | MFA for privileged users | ✅ | TOTP required for every staff role; reset only by another admin with reason (`AdminAuthTest`) |
| V3 | Session management | ✅ | Sanctum SPA cookies: HttpOnly, SameSite=Lax, `Secure` by default outside local/testing, CSRF (`XSRF-TOKEN`); native tokens 15 min + rotating refresh tokens with reuse detection (`TokenService`) |
| V4 | Access control | ✅ | Server-side abilities per role (`Abilities`), object-level checks (installations bound to the caller's device, uploads/artifacts by ability), staff checked before route-model binding (403 not 404). `AdminAccessTest` probes every admin route for every role |
| V5.1 | Input validation | ✅ | Laravel validation on every endpoint; OpenAPI contract tests on responses |
| V5.3 | Output encoding / XSS | ✅ | No server-rendered HTML with user data; portal/admin build DOM with `textContent`; enforced CSP `script-src 'self'`, no inline script |
| V5.2/V12 | File upload | ✅ | Chunked uploads to a private disk outside the docroot, SHA-256, size limits; IPA inspection treats archives as hostile (zip bomb, traversal, symlinks, duplicates, bounds-checked Mach-O parsing); ClamAV when available, quarantine; provenance documents limited to pdf/png/jpg/txt |
| V6 | Cryptography at rest | ✅ | UDIDs, TOTP secrets, profiles and runner secrets encrypted (`encrypted` casts, `APP_KEY`); UDID lookups via HMAC blind index with a separate key; Apple `.p8` stored encrypted outside the DB (`SecretStore`), only a reference in MySQL; signing keys only in the runner Keychain |
| V7 | Logging | ✅ | Immutable audit log (MySQL triggers), request IDs everywhere; UDIDs/tokens/secrets redacted (`AuditService::REDACTED_KEYS`, `UdidPrivacyTest`); customer actors pseudonymised, anonymous emails masked (P8) |
| V8 | Data protection & privacy | ✅ | Data export, deletion request, admin erasure, retention jobs (`RetentionService`); `Cache-Control: no-store` on downloads and manifests |
| V9 | Communications | ✅ | HTTPS required in production; HSTS sent on HTTPS responses (middleware + `.htaccess`); runner refuses non-HTTPS base URLs |
| V10 | Malicious code / integrity | ✅ | Encrypted (App Store) binaries rejected; signed builds verified server-side (hash, bundle ID, profile UUID, team, device in profile) before delivery; `composer audit` in CI |
| V11 | Business logic | ✅ | State machines with transition tests; quota reservations under row locks (20-process concurrency test); idempotency keys on mutating endpoints; no automatic team switching |
| V13 | API security | ✅ | Worker API: HMAC over method, path, timestamp, single-use nonce and body hash; 5-minute skew; leases bound to the runner. Install links single-use, device-bound, 10 minutes; downloads via 10-minute signed URLs, tampering audited |
| V14 | Configuration | ✅ | Enforced CSP, `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy; dotfiles denied in `.htaccess`; debug off in production (`APP_DEBUG=false`, release checklist) |

## Open items (tracked in the release checklist)

1. ⛔ **Independent penetration test** of the deployed staging environment (auth, upload, worker API, install links).
2. ⚠️ **Breached-password check** (e.g. k-anonymity against HIBP) is not implemented.
3. ⚠️ **`APP_KEY` rotation** needs a re-encryption command before it can be done safely (runbook: credential rotation).
4. ⚠️ **Email verification** at registration is still an open product question (§10 Q5).
5. ⚠️ **Enrollment device CA check** is off until a real iPhone has been tested (`STOREFRONT_ENROLLMENT_DEVICE_CA`).
6. ⚠️ **Rate limits use the application cache**; with several web servers the cache store must be shared (Redis) or limits apply per server.
7. ⚠️ **Existing audit rows** written before P8 contain customer emails as actor labels; they cannot be changed (immutable). Documented in the privacy page as retained security records.
