# Artifact quarantine

**Trigger.** Admin → Артефакты shows an artifact `QUARANTINED` with reason `MALWARE_DETECTED`
(ClamAV hit during inspection).

1. Do not download the file to a workstation. Read the inspection report in the artifact detail:
   signature name, bundle ID, uploader, declaration, source type.
2. Contact the uploader/partner through a known channel (not a reply to the upload itself).
3. Decide:
   - **Confirmed malicious** → «Отклонить» with the reason (→ `REJECTED`). Check other uploads by the same
     uploader and the same bundle ID; revoke any published build of that app if in doubt
     («Отозвать» removes installability at once and fails open installations).
   - **False positive** (vendor confirmed, signature known to misfire) → «Вернуть на проверку» with the
     evidence. The artifact goes back to provenance review, never straight to publishing, and the
     reviewer must acknowledge the scan result again.
4. Rejected files are purged after the retention period; the audit trail stays.
