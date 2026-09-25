# Release checklist (MVP)

Phase 8 gate (FULL_PLAN §16): **signed by product, engineering and compliance owners.**
Status as of 2026-09-26. ✅ done and verified · ⏳ needs the Apple account, a device or the host · ⛔ open decision / work.

## 1. Blocking decisions (Phase 0)

| | Item | Owner | Status |
|---|---|---|---|
| ⛔ | Written determination of the distribution channel per source type and audience (P0-01, risk R1); then narrow `STOREFRONT_PUBLISHABLE_SOURCE_TYPES` | Compliance | open |
| ⛔ | Source-type list, upload declaration text (versioned) and provenance review checklist wording (P0-02; `review_checklist` is a placeholder) | Compliance | open |
| ⛔ | Paid Apple Developer team, App Store Connect API key, first test iPhone (P0-03) | Product | open |
| ⛔ | Final bundle ID prefix and domain (P0-04; placeholders in `ios/Config`, runner LaunchAgent label, enrollment profile identifier) | Product | open |
| ⛔ | Host (P0-05): PHP ≥ 8.2, cron every minute, MySQL triggers, upload limits, X-Sendfile, TLS | Ops | open |
| ⛔ | Retention periods (§10 Q8) — defaults in `config/storefront.php` | Compliance | open |
| ⛔ | Email verification at registration (§10 Q5), devices per account (§10), four-eyes review (`STOREFRONT_INDEPENDENT_REVIEW`) | Product | open |

## 2. MVP criteria (FULL_PLAN §17)

| Criterion | Status | Proof |
|---|---|---|
| Customer can register on the website | ✅ | Phase 2 gate; Playwright phase 2 |
| Admin can see the user and device | ✅ | Phase 3; Playwright phase 3 |
| One or many IPAs uploaded from admin and stored | ✅ | Admin → Артефакты batch uploader; Playwright phase 5 (batch, multi-chunk, pause/resume) |
| Independent inspection/publish status per IPA | ✅ | `InspectArtifactTest`, `ArtifactReviewTest`, Playwright phase 5 |
| Native storefront shows catalog and detail | ✅ | Phase 4; iOS UI tests |
| App requests preparation and shows job status | ✅ | `InstallationCoordinatorTests`, `InstallFlowTest` |
| Real registered iPhone completes the authorized test install | ⏳ | Needs the Apple account, the runner Mac with the team's distribution identity and a test iPhone. The test IPA is ready: `scripts/export-demo-ipa.sh` |
| Admins can inspect every step in the audit log | ✅ | Audit on every transition; installation timelines with request IDs |
| Quota and credential state visible | ✅ | Admin → Команды Apple, dashboard widget |
| No unknown or unauthorized IPA exposed as installable | ✅ | Negative tests: encrypted, unpublished, revoked, other device, expired/tampered links |

## 3. Engineering

| | Item | Status |
|---|---|---|
| ✅ | Backend: Pest (unit, feature, concurrency), Larastan, Pint, `composer audit` in CI | 330+ tests |
| ✅ | OpenAPI lint + contract tests on live responses and shared fixtures | |
| ✅ | iOS unit + UI tests; runner `swift test`; DemoApp build; Playwright journeys (phases 2, 3, 4, 5, 7) | |
| ✅ | Enforced CSP, HSTS over HTTPS, secure session cookies, dotfiles denied | `EnvelopeTest` |
| ✅ | Security self-review against ASVS L2 (`docs/security/asvs-l2-review.md`) | |
| ⛔ | Independent penetration test on staging | |
| ✅ | Encrypted backups + restore drill passed (`docs/runbooks/database-restore.md`) | local dev DB |
| ⏳ | Restore drill on staging/production data with real artifact files; off-site target (`BACKUP_REMOTE`) configured | |
| ✅ | Metrics (FULL_PLAN §14) every 5 minutes; alerts to `alerts` log + Slack webhook | |
| ⏳ | `LOG_ALERTS_SLACK_WEBHOOK_URL` pointing at the on-call channel; one test alert received | |
| ✅ | Nine runbooks written | |
| ⛔ | Each runbook walked through once in a tabletop exercise | |
| ⏳ | Signing runner on its dedicated Mac: dedicated user and Keychain, LaunchAgent, `--list-identities` shows the team identity | |
| ⏳ | Physical-device matrix: ≥ 2 iPhone models × 2 iOS versions (FULL_PLAN §15), including trust prompt and reinstall | |
| ⏳ | Production deploy with `scripts/deploy.sh`; cron `* * * * * php artisan schedule:run`; smoke test | |

## 4. Content and legal

| | Item | Status |
|---|---|---|
| ⛔ | `terms.html` — legal text | placeholder |
| ⛔ | `privacy.html` — factual draft is published and marked as draft; legal approval required | draft |
| ⛔ | `pricing.html` — plans and prices | placeholder |
| ✅ | Final brand «Ru AppStore» replaces `[BRAND]` (web, admin, iOS, API title) | done |
| ⚠️ | Accessibility: labels, skip links, focus order and `aria-live` statuses reviewed in code; a VoiceOver pass on iOS Safari and in the app is still to do | |

## 5. Sign-off

| Role | Name | Date | Signature |
|---|---|---|---|
| Product owner | | | |
| Engineering lead | | | |
| Compliance owner | | | |
