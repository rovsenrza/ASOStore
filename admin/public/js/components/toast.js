let region;

/**
 * Transient, screen-reader-announced message.
 */
export function toast(message, { tone = 'info', timeoutMs = 5000 } = {}) {
  if (!region) {
    region = document.createElement('div');
    region.className = 'toast-region';
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    document.body.append(region);
  }

  const item = document.createElement('div');
  item.className = `toast toast--${tone}`;
  item.textContent = message;
  region.append(item);
  setTimeout(() => item.remove(), timeoutMs);
}
