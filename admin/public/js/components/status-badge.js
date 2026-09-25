/**
 * Status pill for any backend state enum. Tone is derived from the value so
 * new states render sensibly without code changes.
 */
const OK = /^(READY|PUBLISHED|ELIGIBLE|DELIVERABLE|DELIVERED|SUCCEEDED|SIGNATURE_VERIFIED|ACTIVE|ok|get)$/i;
const ERROR = /(FAILED|REJECTED|REVOKED|QUARANTINED|BLOCKED|NO_ELIGIBLE_TEAM|EXPIRED|DISABLED|failed|unavailable)/i;

export function statusBadge(status, label = status) {
  const badge = document.createElement('span');
  const tone = OK.test(status) ? 'ok' : ERROR.test(status) ? 'error' : status ? 'warn' : 'neutral';
  badge.className = `badge badge--${tone}`;
  badge.textContent = label ?? '—';
  return badge;
}
