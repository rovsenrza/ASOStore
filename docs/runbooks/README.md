# Runbooks

Operational procedures required by FULL_PLAN §14 (IMPLEMENTATION_PLAN P8-DOC-01). Each one starts
from the alert or symptom an operator actually sees, and names the exact screens and commands.

| Runbook | Typical trigger |
|---|---|
| [Apple API outage](apple-api-outage.md) | Registrations stuck in `APPLE_PENDING`, `apple_api_429_count` rising, `APPLE_UNAVAILABLE` |
| [Quota reconciliation mismatch](quota-mismatch.md) | Alert `quota.mismatch` |
| [Signing runner offline](runner-offline.md) | Alert `runner-offline`, installs stuck in «Подготовка» |
| [Artifact quarantine](artifact-quarantine.md) | Artifact in `QUARANTINED` (malware scan hit) |
| [Certificate expiry](certificate-expiry.md) | Alert `certificate.expiring`, team `EXPIRING` |
| [Database restore](database-restore.md) | Data loss or corruption; monthly drill |
| [Object storage restore](storage-restore.md) | Missing or corrupt IPA files |
| [Compromised credential rotation](credential-rotation.md) | Leaked `.p8`, runner key, signing identity, `APP_KEY` |
| [User device recovery](device-recovery.md) | Customer: Storefront gone, new iPhone, «не открывается» |

Conventions: every operator action that changes state is taken in the admin panel or with an
`artisan` command that writes an audit event; always enter a reason. Quote request IDs
(`X-Request-Id`) in tickets. Alerts land in `storage/logs/alerts-*.log` and, when
`LOG_ALERTS_SLACK_WEBHOOK_URL` is set, in the on-call channel.

**Tabletop exercises:** not yet held. Each runbook must be walked through once by the on-call
engineer before launch (release checklist item).
